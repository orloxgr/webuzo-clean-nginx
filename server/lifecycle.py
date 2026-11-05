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

HERE = Path(__file__).resolve().parent
CONFIG = Path('/etc/webuzo-clean-nginx/bridge.json')
ROOT = Path('/var/lib/webuzo-clean-nginx')
CACHE = Path('/var/webuzo-data/nginx_proxy_cache')
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


def write(path, data, mode=0o600, gid=0):
    if path.exists() or path.is_symlink():
        trusted(path)
    fd, temporary = tempfile.mkstemp(prefix='.bridge-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(json.dumps(data, separators=(',', ':')).encode() + b'\n')
            output.flush()
            os.fsync(output.fileno())
            os.fchown(output.fileno(), 0, gid)
            os.fchmod(output.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


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
    if os.geteuid() != 0 or len(sys.argv) != 2 or sys.argv[1] not in ('install', 'remove'):
        raise SystemExit('Only the administrator may invoke the fixed install/remove lifecycle.')
    os.umask(0o077)
    validate_bundle()
    directory(ROOT, 0o755)
    directory(ROOT / 'clients', 0o711)
    directory(ROOT / 'state', 0o700)
    directory(CONFIG.parent, 0o700)
    lock = ROOT / 'lifecycle.lock'
    if not lock.exists():
        write(lock, {})
    trusted(lock)
    with lock.open('r+') as handle:
        fcntl.flock(handle, fcntl.LOCK_EX)
        old = read_config()
        write(ROOT / 'status.json', {'state': 'pending', 'accounts': 0}, 0o644)
        retire_legacy()
        keys = {}
        if sys.argv[1] == 'install':
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
        if sys.argv[1] == 'remove':
            CONFIG.unlink()
        write(ROOT / 'status.json', {'state': 'ready' if sys.argv[1] == 'install' else 'disabled', 'accounts': len(keys)}, 0o644)
    print('Native bridge lifecycle completed. Enrolled accounts: ' + str(len(keys)))


if __name__ == '__main__':
    main()
