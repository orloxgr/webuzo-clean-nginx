#!/usr/bin/python3 -I
"""Native Webuzo bridge lifecycle: fixed install/remove, no cache deletion."""
import fcntl
import grp
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import stat
import subprocess
import sys
import tempfile
import time

HERE = Path(__file__).resolve().parent
CONFIG = Path('/etc/webuzo-clean-nginx/bridge.json')
ROOT = Path('/var/lib/webuzo-clean-nginx')
CACHE = Path('/var/webuzo-data/nginx_proxy_cache')
CRON = Path('/etc/cron.d/webuzo-clean-nginx')
REFRESH_LOG = ROOT / 'refresh.log'
CRON_TEXT = ('# Managed by Webuzo Clean Nginx; enrollment only, never cache purge.\n'
             'SHELL=/bin/sh\nMAILTO=""\nPATH=/usr/sbin:/usr/bin:/sbin:/bin\n'
             '*/10 * * * * root /usr/bin/timeout --signal=TERM --kill-after=2s 45s '
             '/usr/bin/python3 -I -B /usr/local/webuzo/plugins/webuzo-clean-nginx/service/lifecycle.py refresh '
             '>> /var/lib/webuzo-clean-nginx/refresh.log 2>&1\n')
LEGACY = ('/usr/local/lib/webuzo-cache-purge/webuzo_cache_purge.py',
          '/etc/webuzo-cache-purge/accounts.json', '/etc/systemd/system/webuzo-cache-purge.service')


def trusted(path, directory=False):
    info = path.lstat()
    kind = stat.S_ISDIR if directory else stat.S_ISREG
    if not kind(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or (not directory and info.st_nlink != 1):
        raise ValueError('Unsafe plugin-owned path: ' + str(path))


def directory(path, mode):
    current = Path('/')
    for part in path.parts[1:]:
        current /= part
        if not current.exists() and not current.is_symlink():
            current.mkdir(mode=mode if current == path else 0o755)
        trusted(current, True)
    os.chmod(path, mode)


def write_bytes(path, content, mode=0o600, gid=0):
    if path.exists() or path.is_symlink():
        trusted(path)
        info = path.stat()
        if path.read_bytes() == content and stat.S_IMODE(info.st_mode) == mode and info.st_gid == gid:
            return False
    fd, temporary = tempfile.mkstemp(prefix='.bridge-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
            os.fchown(output.fileno(), 0, gid)
            os.fchmod(output.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return True


def write(path, data, mode=0o600, gid=0):
    return write_bytes(path, json.dumps(data, separators=(',', ':')).encode() + b'\n', mode, gid)


def read_config():
    if not CONFIG.exists():
        return {'v': 1, 'keys': {}}
    trusted(CONFIG)
    if CONFIG.stat().st_size > 1048576:
        raise ValueError('Oversize registry.')
    value = json.loads(CONFIG.read_text())
    if value.get('v') != 1 or not isinstance(value.get('keys'), dict):
        raise ValueError('Invalid registry.')
    for key_id, entry in value['keys'].items():
        if not re.fullmatch('[a-f0-9]{32}', key_id) or not isinstance(entry, dict) or \
                not re.fullmatch('[a-f0-9]{64}', entry.get('key', '')) or \
                not isinstance(entry.get('uid'), int) or entry['uid'] < 1000 or \
                not isinstance(entry.get('gid'), int) or \
                not re.fullmatch('[a-z_][a-z0-9_-]{0,31}', entry.get('account', ''), re.I):
            raise ValueError('Invalid registry identity.')
    return value


def retire_legacy():
    unit = Path(LEGACY[2])
    if unit.exists() or unit.is_symlink():
        for name in LEGACY:
            path = Path(name)
            if path.exists() or path.is_symlink():
                trusted(path)
        subprocess.run(['/usr/bin/systemctl', 'disable', '--now', 'webuzo-cache-purge.service'],
                       check=True, timeout=25, stdout=subprocess.DEVNULL)
        for name in LEGACY:
            path = Path(name)
            if path.exists():
                path.unlink()
        subprocess.run(['/usr/bin/systemctl', 'daemon-reload'], check=True, timeout=15)


def identities():
    if not CACHE.exists() and not CACHE.is_symlink():
        return []
    if not CACHE.is_dir() or CACHE.resolve() != CACHE:
        raise ValueError('Native cache base is missing or unsafe.')
    users = pwd.getpwall()
    result = []
    for path in sorted(CACHE.iterdir()):
        if not re.fullmatch('[a-z_][a-z0-9_-]{0,31}', path.name, re.I) or path.is_symlink() or not path.is_dir():
            continue
        try:
            user = pwd.getpwnam(path.name)
            group = grp.getgrgid(user.pw_gid)
        except KeyError:
            continue
        if user.pw_uid < 1000 or sum(u.pw_uid == user.pw_uid for u in users) != 1:
            continue
        if any(u.pw_uid >= 1000 and u.pw_name != user.pw_name and
               (u.pw_gid == user.pw_gid or u.pw_name in group.gr_mem) for u in users):
            continue
        try:
            generation = native_generation(user.pw_name)
        except (ValueError, OSError, KeyError):
            continue
        result.append((user, generation))
    return result


def native_generation(account):
    """Bind capabilities to Webuzo's account generation, including Unix UID reuse."""
    webuzo = pwd.getpwnam('webuzo')
    if not 0 < webuzo.pw_uid < 1000 or not 0 < webuzo.pw_gid < 1000:
        raise ValueError('Invalid panel identity.')
    path = Path('/var/webuzo/users') / account / 'info'
    current = Path('/')
    for part in path.parts[1:]:
        current /= part
        info = current.lstat()
        kind = stat.S_ISREG if current == path else stat.S_ISDIR
        if not kind(info.st_mode) or info.st_uid not in (0, webuzo.pw_uid) or info.st_mode & 0o002 or \
                (info.st_mode & 0o020 and info.st_gid not in (0, webuzo.pw_gid)):
            raise ValueError('Unsafe native account metadata.')
    if path.stat().st_size > 1048576:
        raise ValueError('Oversize native metadata.')
    data = json.loads(path.read_text())
    if any(data.get(flag) not in (None, False, 0, '', '0') for flag in ('disable_nginx_cache', 'disable_reverse_proxy')):
        raise ValueError('Native account cache or proxy is disabled.')
    if data.get('user') != account or not re.fullmatch('[A-Za-z0-9-]{8,64}', data.get('uuid', '')) or \
            type(data.get('created')) is not int or data['created'] <= 0:
        raise ValueError('Missing native account generation.')
    return hashlib.sha256((data['uuid'] + '\n' + str(data['created'])).encode()).hexdigest()


def validate_bundle():
    current = Path('/')
    for part in HERE.parts[1:]:
        current /= part
        info = current.lstat()
        if current == Path('/usr/local/webuzo') and info.st_mode & 0o022 == 0o020:
            group = grp.getgrnam('webuzo')
            members = [u for u in pwd.getpwall() if u.pw_gid == group.gr_gid or u.pw_name in group.gr_mem]
            if info.st_uid == 0 and info.st_gid == group.gr_gid and 0 < group.gr_gid < 1000 and all(u.pw_uid < 1000 for u in members):
                continue
        trusted(current, True)
    trusted(HERE / 'lifecycle.py')
    trusted(HERE.parent / 'includes', True)
    trusted(HERE.parent / 'includes/class-native-bridge.php')
    trusted(HERE.parent / 'webuzo-clean-nginx.php')


def main():
    if os.geteuid() != 0 or len(sys.argv) != 2 or sys.argv[1] not in ('install', 'remove', 'refresh'):
        raise SystemExit('Only the administrator may invoke the fixed install/remove/refresh lifecycle.')
    operation = sys.argv[1]
    os.umask(0o077)
    validate_bundle()
    if operation == 'refresh':
        # A queued cron invocation must never reactivate a disabled plugin.
        if not CONFIG.exists() or not CRON.exists():
            return
        for path in (ROOT, ROOT / 'clients', ROOT / 'state', CONFIG.parent, CRON.parent):
            trusted(path, True)
        trusted(CRON)
        if CRON.read_text() != CRON_TEXT:
            raise SystemExit('Unexpected enrollment cron content; refresh refused.')
    else:
        directory(ROOT, 0o755)
        directory(ROOT / 'clients', 0o711)
        directory(ROOT / 'state', 0o700)
        directory(CONFIG.parent, 0o700)
    lock = ROOT / 'lifecycle.lock'
    if not lock.exists() and operation != 'refresh':
        write(lock, {})
    trusted(lock)
    with lock.open('r+') as handle:
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | (fcntl.LOCK_NB if operation == 'refresh' else 0))
        except BlockingIOError:
            return  # A running purge or lifecycle operation wins; retry next scheduled run.
        if operation == 'refresh' and (not CONFIG.exists() or not CRON.exists()):
            return
        old = read_config()
        if operation != 'refresh':
            write(ROOT / 'status.json', {'state': 'pending', 'accounts': 0}, 0o644)
            retire_legacy()
        if operation == 'remove' and (CRON.exists() or CRON.is_symlink()):
            trusted(CRON)
            CRON.unlink()
        keys = {}
        if operation in ('install', 'refresh'):
            for executable in ('/usr/local/emps/bin/php', '/usr/local/webuzo/cli.php', '/usr/bin/timeout'):
                trusted(Path(executable))
            old_by_uid = {e['uid']: (i, e) for i, e in old['keys'].items()}
            for user, generation in identities():
                existing = old_by_uid.get(user.pw_uid)
                if existing and existing[1]['account'] == user.pw_name and existing[1]['gid'] == user.pw_gid and existing[1].get('generation') == generation:
                    key_id, entry = existing
                else:
                    key_id = secrets.token_hex(16)
                    entry = {'uid': user.pw_uid, 'gid': user.pw_gid, 'account': user.pw_name, 'generation': generation, 'key': secrets.token_hex(32)}
                keys[key_id] = entry
                write(ROOT / 'clients' / (str(user.pw_uid) + '.json'),
                      {'v': 1, 'id': key_id, 'key': entry['key']}, 0o440, user.pw_gid)
                state = ROOT / 'state' / (key_id + '.json')
                if not state.exists():
                    write(state, {'last_purge': 0, 'last_request': 0, 'nonces': {}})
                else:
                    trusted(state)
            for i in range(2):
                slot = ROOT / 'state' / ('slot-' + str(i))
                if not slot.exists():
                    write(slot, {})
                else:
                    trusted(slot)
        write(CONFIG, {'v': 1, 'keys': keys})
        live_clients = {str(e['uid']) + '.json' for e in keys.values()}
        for path in (ROOT / 'clients').iterdir():
            if re.fullmatch('[0-9]+[.]json', path.name) and path.name not in live_clients:
                trusted(path)
                path.unlink()
        for path in (ROOT / 'state').iterdir():
            if re.fullmatch('[a-f0-9]{32}[.]json', path.name) and path.stem not in keys:
                trusted(path)
                path.unlink()
        if operation == 'remove':
            CONFIG.unlink()
        elif operation == 'install':
            trusted(CRON.parent, True)
            trusted(REFRESH_LOG.parent, True)
            if REFRESH_LOG.exists() or REFRESH_LOG.is_symlink():
                trusted(REFRESH_LOG)
                os.chmod(REFRESH_LOG, 0o600)
            else:
                write_bytes(REFRESH_LOG, b'')
            write_bytes(CRON, CRON_TEXT.encode(), 0o644)
        summary = {'state': 'disabled' if operation == 'remove' else 'ready', 'accounts': len(keys),
                   'refresh_interval_minutes': 10, 'last_refresh': int(time.time())}
        write(ROOT / 'status.json', summary, 0o644)
    if operation != 'refresh' or keys != old['keys']:
        print('Native bridge ' + operation + ' completed. Enrolled accounts: ' + str(len(keys)))


if __name__ == '__main__':
    main()
