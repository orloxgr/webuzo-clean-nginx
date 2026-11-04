#!/usr/bin/python3 -I
"""Print a deployment plan by default. --apply is a separate, administrator-only step."""
import argparse
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
BASE = Path('/var/webuzo-data/nginx_proxy_cache')
DESTINATIONS = {
    'code': '/usr/local/lib/webuzo-cache-purge/webuzo_cache_purge.py',
    'accounts': '/etc/webuzo-cache-purge/accounts.json',
    'unit': '/etc/systemd/system/webuzo-cache-purge.service',
}


def identity(account):
    if not re.fullmatch(r'[a-z_][a-z0-9_-]*', account, re.I):
        raise ValueError('Invalid account name: ' + account)
    entry = pwd.getpwnam(account)
    if entry.pw_uid < 1000:
        raise ValueError('System users cannot be enrolled: ' + account)
    path = BASE / account
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or path.resolve() != path:
        raise ValueError('Cache root is missing or is a symlink: ' + account)
    return entry.pw_uid, {'account': account, 'gid': entry.pw_gid}


def safe_directory(path, mode):
    """Existing destination ancestors must be root owned and not writable by tenants."""
    parts = Path(path).parts
    current = Path(parts[0])
    for part in parts[1:]:
        current = current / part
        if not current.exists() and not current.is_symlink():
            current.mkdir(mode=mode if str(current) == path else 0o755)
        info = current.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise ValueError('Unsafe destination directory: ' + str(current))


def atomic_write(destination, content, mode):
    path = Path(destination)
    if path.exists() or path.is_symlink():
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1:
            raise ValueError('Unsafe existing destination: ' + destination)
    fd, name = tempfile.mkstemp(prefix='.install-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
            os.fchown(output.fileno(), 0, 0)
            os.fchmod(output.fileno(), mode)
        os.replace(name, destination)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--account', action='append', help='Enroll only named accounts; repeat for several accounts.')
    group.add_argument('--all-webuzo-accounts', action='store_true', help='Enroll passwd accounts with existing cache directories.')
    parser.add_argument('--apply', action='store_true', help='Actually write root-owned files and enable/start only this service.')
    args = parser.parse_args()
    if BASE.resolve() != BASE or not BASE.is_dir():
        raise SystemExit('Expected Webuzo cache base is missing or unsafe.')
    names = sorted(p.name for p in BASE.iterdir() if p.is_dir() and not p.is_symlink()) if args.all_webuzo_accounts else sorted(set(args.account))
    accounts = {}
    skipped = []
    for name in names:
        try:
            uid, entry = identity(name)
        except (KeyError, ValueError, OSError) as error:
            if not args.all_webuzo_accounts:
                raise SystemExit(str(error))
            skipped.append(name)
            continue
        if str(uid) in accounts:
            raise SystemExit('Two account names share a UID; enroll only after resolving that ambiguity.')
        accounts[str(uid)] = entry
    if not accounts:
        raise SystemExit('No eligible cache accounts were selected.')
    config = json.dumps({'version': 1, 'accounts': accounts}, indent=2).encode() + b'\n'
    plan = {'apply': args.apply, 'destinations': DESTINATIONS, 'modes': {'code': '0644 root:root', 'accounts': '0600 root:root', 'unit': '0644 root:root'},
        'runtime': '/run/webuzo-cache-purge (0755 root:root); control.sock (0666 root:root; enrolled UID checks)',
        'accounts': accounts, 'skipped_accounts': skipped,
        'service_commands': ['systemctl daemon-reload', 'systemctl enable webuzo-cache-purge.service', 'systemctl restart webuzo-cache-purge.service'],
        'existing_server_services_restarted': [], 'nginx_or_php_config_changed': False}
    print(json.dumps(plan, indent=2))
    if not args.apply:
        print('PLAN ONLY. No directories, files, accounts, or services were changed.')
        return
    if os.geteuid() != 0:
        raise SystemExit('--apply must be run by the hosting administrator as root.')
    if not Path('/usr/bin/python3').is_file() or shutil.which('systemctl') is None or shutil.which('systemd-analyze') is None:
        raise SystemExit('Python 3 and systemd are required. This installer does not install system packages.')
    subprocess.run(['systemd-analyze', 'verify', str(HERE / 'webuzo-cache-purge.service')], check=True)
    safe_directory('/usr/local/lib/webuzo-cache-purge', 0o755)
    safe_directory('/etc/webuzo-cache-purge', 0o700)
    safe_directory('/etc/systemd/system', 0o755)
    atomic_write(DESTINATIONS['code'], (HERE / 'webuzo_cache_purge.py').read_bytes(), 0o644)
    atomic_write(DESTINATIONS['accounts'], config, 0o600)
    atomic_write(DESTINATIONS['unit'], (HERE / 'webuzo-cache-purge.service').read_bytes(), 0o644)
    subprocess.run(['systemctl', 'daemon-reload'], check=True)
    subprocess.run(['systemctl', 'enable', 'webuzo-cache-purge.service'], check=True)
    subprocess.run(['systemctl', 'restart', 'webuzo-cache-purge.service'], check=True)
    subprocess.run(['systemctl', 'is-active', '--quiet', 'webuzo-cache-purge.service'], check=True)
    print('Installed and started only webuzo-cache-purge.service. No Nginx/PHP restart or permission changes were made.')


if __name__ == '__main__':
    main()
