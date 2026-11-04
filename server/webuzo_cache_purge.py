#!/usr/bin/python3 -I
"""Local, UID-authenticated cache purger. No HTTP, shell, or caller-supplied paths."""
import concurrent.futures
import contextlib
import fcntl
import hashlib
import json
import logging
import os
import pwd
import re
import signal
import socket
import stat
import struct
import threading
import time
import zlib

VERSION = '0.1.0'
CONFIG = '/etc/webuzo-cache-purge/accounts.json'
CACHE_BASE = '/var/webuzo-data/nginx_proxy_cache'
SOCKET = '/run/webuzo-cache-purge/control.sock'
DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
FILE_FLAGS = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK
NAME = re.compile(r'[a-z_][a-z0-9_-]*\Z', re.I)
HASH = re.compile(r'[0-9a-f]{32}\Z')
MAX_ENTRIES = 100000
SCAN_SECONDS = 15
MAX_REQUEST = 512
COOLDOWN = 10


class Rejected(Exception):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def open_tree(path, trusted=False):
    """Open each directory component without following links; caller owns returned fd."""
    if not path.startswith('/') or any(p in ('.', '..') for p in path.split('/')):
        raise Rejected('unsafe_path', 'Invalid fixed server path.')
    fd = os.open('/', DIR_FLAGS)
    try:
        for part in filter(None, path.split('/')):
            next_fd = os.open(part, DIR_FLAGS, dir_fd=fd)
            os.close(fd)
            fd = next_fd
            info = os.fstat(fd)
            if trusted and (info.st_uid != 0 or info.st_mode & 0o022):
                raise Rejected('unsafe_path', 'Server directory ownership or permissions are unsafe.')
        return fd
    except BaseException:
        os.close(fd)
        raise


def load_registry():
    parent = open_tree('/etc/webuzo-cache-purge', trusted=True)
    try:
        fd = os.open('accounts.json', FILE_FLAGS, dir_fd=parent)
    finally:
        os.close(parent)
    with os.fdopen(fd, 'r') as config:
        info = os.fstat(config.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1:
            raise Rejected('unsafe_config', 'The account registry must be a root-owned regular file.')
        raw = config.read(1048577)
        if len(raw) > 1048576:
            raise Rejected('unsafe_config', 'Account registry is too large.')
        data = json.loads(raw)
    if not isinstance(data, dict) or set(data) != {'version', 'accounts'} or data['version'] != 1 or not isinstance(data['accounts'], dict):
        raise Rejected('unsafe_config', 'Unsupported account registry.')
    for uid, entry in data['accounts'].items():
        if not uid.isdigit() or int(uid) < 1000 or not isinstance(entry, dict) or set(entry) != {'account', 'gid'}:
            raise Rejected('unsafe_config', 'Invalid account registry entry.')
        if not isinstance(entry['account'], str) or not NAME.fullmatch(entry['account']) or type(entry['gid']) is not int:
            raise Rejected('unsafe_config', 'Invalid account registry identity.')
    return data['accounts']


def identity_for(uid, registry):
    entry = registry.get(str(uid))
    if uid < 1000 or not entry:
        raise Rejected('not_allowed', 'This Unix account is not enabled for cache clearing.')
    try:
        identity = pwd.getpwuid(uid)
    except KeyError:
        raise Rejected('not_allowed', 'This Unix account no longer exists.')
    if identity.pw_name != entry['account'] or identity.pw_gid != entry['gid']:
        raise Rejected('identity_changed', 'Account identity changed; the hosting administrator must review enrollment.')
    return entry


def parse_request(raw):
    try:
        def unique_object(pairs):
            result = {}
            for key, value in pairs:
                if key in result:
                    raise ValueError('Duplicate property')
                result[key] = value
            return result
        value = json.loads(raw.decode('utf-8'), object_pairs_hook=unique_object)
    except (UnicodeError, ValueError):
        raise Rejected('bad_request', 'Send one valid JSON request.')
    if not isinstance(value, dict) or set(value) != {'v', 'op'} or type(value['v']) is not int or value['v'] != 1:
        raise Rejected('bad_request', 'Only protocol version and operation are accepted; account and path overrides are forbidden.')
    if value['op'] not in ('status', 'preview', 'purge'):
        raise Rejected('bad_operation', 'Only status, preview, and purge are supported.')
    return value['op']


def valid_entry(fd, filename):
    """Validate Nginx v5 x86-64 header/key/hash, including Vary entries."""
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        return None
    header = os.read(fd, 336)
    if len(header) != 336 or header[:8] != b'\x05\0\0\0\0\0\0\0':
        return None
    header_start, body_start = struct.unpack_from('<HH', header, 54)
    key_length = header_start - 343
    if key_length < 1 or key_length > 16384 or body_start < header_start or body_start > info.st_size:
        return None
    line = os.read(fd, key_length + 7)
    if len(line) != key_length + 7 or not line.startswith(b'\nKEY: ') or not line.endswith(b'\n'):
        return None
    key = line[6:-1]
    if any(c in key for c in (b'\0', b'\r', b'\n')) or struct.unpack_from('<I', header, 48)[0] != zlib.crc32(key):
        return None
    if hashlib.md5(key).hexdigest() != filename and not (header[187] and header[316:332].hex() == filename):
        return None
    return info


def scan_account(base_fd, account, delete=False, max_entries=MAX_ENTRIES, seconds=SCAN_SECONDS):
    """All traversal, file opens, stat checks and unlink calls are relative to pinned fds."""
    if not isinstance(account, str) or not NAME.fullmatch(account):
        raise Rejected('bad_account', 'Invalid enrolled account.')
    try:
        account_fd = os.open(account, DIR_FLAGS, dir_fd=base_fd)
    except OSError:
        raise Rejected('cache_unavailable', 'The enrolled account cache directory is missing or unsafe.')
    result = {'visited': 0, 'scanned': 0, 'matched': 0, 'deleted': 0, 'unknown': 0, 'errors': 0, 'complete': True}
    deadline = time.monotonic() + seconds
    device = os.fstat(base_fd).st_dev
    try:
        if os.fstat(account_fd).st_dev != device:
            raise Rejected('unsafe_mount', 'The cache directory must be on the configured cache filesystem.')
        try:
            fcntl.flock(account_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Rejected('busy', 'An account cache scan is already running.')

        def walk(directory_fd, depth, first='', second=''):
            with os.scandir(directory_fd) as items:
                for item in items:
                    if result['visited'] >= max_entries or time.monotonic() >= deadline:
                        result['complete'] = False
                        return
                    result['visited'] += 1
                    name = item.name
                    try:
                        metadata = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                        if depth < 2 and re.fullmatch(r'[0-9a-f]' if depth == 0 else r'[0-9a-f]{2}', name) and stat.S_ISDIR(metadata.st_mode):
                            child = os.open(name, DIR_FLAGS, dir_fd=directory_fd)
                            try:
                                if os.fstat(child).st_dev != device:
                                    result['unknown'] += 1
                                else:
                                    walk(child, depth + 1, name if depth == 0 else first, name if depth == 1 else '')
                            finally:
                                os.close(child)
                            if not result['complete']:
                                return
                        elif depth == 2 and HASH.fullmatch(name) and stat.S_ISREG(metadata.st_mode) and name[-1] == first and name[-3:-1] == second:
                            result['scanned'] += 1
                            fd = os.open(name, FILE_FLAGS, dir_fd=directory_fd)
                            try:
                                checked = valid_entry(fd, name)
                                if checked is None or checked.st_dev != device:
                                    result['unknown'] += 1
                                    continue
                                result['matched'] += 1
                                if delete:
                                    current = os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
                                    if (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns, current.st_nlink) != (checked.st_dev, checked.st_ino, checked.st_size, checked.st_mtime_ns, 1):
                                        result['errors'] += 1
                                        continue
                                    os.unlink(name, dir_fd=directory_fd)
                                    result['deleted'] += 1
                            finally:
                                os.close(fd)
                        else:
                            result['unknown'] += 1
                    except FileNotFoundError:
                        pass  # Nginx can expire or replace an entry concurrently.
                    except OSError:
                        result['errors'] += 1
        try:
            walk(account_fd, 0)
        except OSError:
            result['errors'] += 1
            result['complete'] = False
    finally:
        os.close(account_fd)
    if result['errors']:
        result['complete'] = False
    return result


class Broker:
    def __init__(self, base_fd, registry):
        self.base_fd = base_fd
        self.registry = registry
        self.lock = threading.Lock()
        self.active = set()
        self.last_scan = {}

    def execute(self, uid, request):
        identity = identity_for(uid, self.registry)
        op = parse_request(request)
        result = {'ok': True, 'v': 1, 'service_version': VERSION, 'account': identity['account'], 'scope': 'account'}
        if op == 'status':
            return result
        with self.lock:
            if uid in self.active:
                raise Rejected('busy', 'A cache operation for this account is already running.')
            if time.monotonic() - self.last_scan.get(uid, float('-inf')) < COOLDOWN:
                raise Rejected('rate_limited', 'Wait 10 seconds between cache scans for this account.')
            self.active.add(uid)
            self.last_scan[uid] = time.monotonic()
        try:
            result['result'] = scan_account(self.base_fd, identity['account'], delete=op == 'purge')
        finally:
            with self.lock:
                self.active.discard(uid)
        logging.info('uid=%d account=%s operation=%s result=%s', uid, identity['account'], op, json.dumps(result['result'], separators=(',', ':')))
        return result


def peer_uid(connection):
    return struct.unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize('3i')))[1]


def send_response(connection, response):
    connection.sendall(json.dumps(response, separators=(',', ':')).encode('utf-8') + b'\n')


def handle_connection(connection, uid, broker):
    with connection:
        try:
            deadline = time.monotonic() + 2
            raw = bytearray()
            while b'\n' not in raw:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise Rejected('request_timeout', 'Request deadline exceeded.')
                connection.settimeout(remaining)
                chunk = connection.recv(MAX_REQUEST + 1 - len(raw))
                if not chunk:
                    raise Rejected('bad_request', 'A newline-terminated JSON request is required.')
                raw.extend(chunk)
                if len(raw) > MAX_REQUEST:
                    raise Rejected('too_large', 'Request is too large.')
            line, tail = bytes(raw).split(b'\n', 1)
            if tail:
                raise Rejected('bad_request', 'Only one request per connection is supported.')
            connection.settimeout(2)
            send_response(connection, broker.execute(uid, line))
        except Rejected as error:
            with contextlib.suppress(OSError):
                send_response(connection, {'ok': False, 'v': 1, 'code': error.code, 'message': str(error)})
        except (OSError, ValueError):
            with contextlib.suppress(OSError):
                send_response(connection, {'ok': False, 'v': 1, 'code': 'request_failed', 'message': 'The local cache request could not be completed.'})


def serve(listener, broker, stop):
    slots = threading.BoundedSemaphore(8)
    receiving = set()
    last_connection = {}
    receive_lock = threading.Lock()
    listener.settimeout(0.5)

    def worker(connection, uid):
        try:
            handle_connection(connection, uid, broker)
        finally:
            with receive_lock:
                receiving.discard(uid)
            slots.release()

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        while not stop.is_set():
            try:
                connection, unused = listener.accept()
            except socket.timeout:
                continue
            try:
                uid = peer_uid(connection)
                identity_for(uid, broker.registry)  # Reject unenrolled peers before reading any payload.
                with receive_lock:
                    if uid in receiving:
                        raise Rejected('busy', 'One connection per account is allowed at a time.')
                    now = time.monotonic()
                    if now - last_connection.get(uid, float('-inf')) < 0.1:
                        raise Rejected('busy', 'Connection rate exceeded.')
                    if not slots.acquire(blocking=False):
                        raise Rejected('busy', 'The cache service is busy; try again shortly.')
                    receiving.add(uid)
                    last_connection[uid] = now
                pool.submit(worker, connection, uid)
            except (Rejected, OSError):
                connection.close()


def main():
    if os.geteuid() != 0:
        raise SystemExit('Production service must be started by the reviewed systemd unit as root.')
    logging.basicConfig(level=logging.INFO, format='webuzo-cache-purge %(message)s')
    registry = load_registry()
    base_fd = open_tree(CACHE_BASE, trusted=True)
    runtime_fd = open_tree('/run/webuzo-cache-purge', trusted=True)
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    stop = threading.Event()
    bound = False
    try:
        with contextlib.suppress(FileNotFoundError):
            previous = os.stat('control.sock', dir_fd=runtime_fd, follow_symlinks=False)
            if not stat.S_ISSOCK(previous.st_mode) or previous.st_uid != 0:
                raise Rejected('unsafe_socket', 'Refusing to replace an unexpected runtime entry.')
            probe = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            try:
                probe.settimeout(0.2)
                try:
                    probe.connect(SOCKET)
                except ConnectionRefusedError:
                    pass
                else:
                    raise Rejected('already_running', 'A cache service is already listening.')
            finally:
                probe.close()
            os.unlink('control.sock', dir_fd=runtime_fd)
        listener.bind(SOCKET)
        bound = True
        os.chmod(SOCKET, 0o666)
        listener.listen(32)
        for signum in (signal.SIGTERM, signal.SIGINT):
            signal.signal(signum, lambda *unused: stop.set())
        logging.info('started version=%s enrolled_accounts=%d', VERSION, len(registry))
        serve(listener, Broker(base_fd, registry), stop)
    finally:
        listener.close()
        if bound:
            with contextlib.suppress(FileNotFoundError):
                os.unlink('control.sock', dir_fd=runtime_fd)
        os.close(runtime_fd)
        os.close(base_fd)


if __name__ == '__main__':
    main()
