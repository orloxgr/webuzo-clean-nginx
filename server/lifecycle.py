#!/usr/bin/python3 -I
"""Fixed native Webuzo plugin lifecycle. Root only; never accepts paths or accounts."""
import contextlib
import fcntl
import grp
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys
import time

HERE = Path(__file__).resolve().parent
STATE = '/var/lib/webuzo-cache-purge/status.json'
LOG = '/var/log/webuzo-cache-purge-install.log'
UNIT = 'webuzo-cache-purge.service'


def trusted_file(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1:
        raise ValueError('Unsafe plugin file: ' + str(path))


def main():
    if os.geteuid() != 0 or len(sys.argv) != 2 or sys.argv[1] not in ('install', 'remove'):
        raise SystemExit('Only root may invoke the fixed install/remove lifecycle.')
    # Validate every ancestor BEFORE importing executable code from this bundle.
    current = Path('/')
    for part in HERE.parts[1:]:
        current /= part
        info = current.lstat()
        writable = info.st_mode & 0o022
        if current == Path('/usr/local/webuzo') and writable == 0o020:
            # Official Webuzo installs this root:webuzo 0771. The panel's
            # system group is trusted only when it contains no ordinary tenant.
            group = grp.getgrnam('webuzo')
            members = [pwd.getpwnam(name) for name in group.gr_mem]
            members += [entry for entry in pwd.getpwall() if entry.pw_gid == group.gr_gid]
            if 0 < group.gr_gid < 1000 and info.st_gid == group.gr_gid and all(entry.pw_uid < 1000 for entry in members):
                writable = 0
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or writable:
            raise SystemExit('Unsafe plugin directory.')
    for name in ('lifecycle.py', 'install.py', 'webuzo_cache_purge.py', 'webuzo-cache-purge.service'):
        trusted_file(HERE / name)
    # -I deliberately excludes the current directory; import only the checked bundle.
    sys.path.insert(0, str(HERE))
    from install import DESTINATIONS, atomic_write, safe_directory
    safe_directory('/var/lib/webuzo-cache-purge', 0o755)
    lockfd = os.open('/var/lib/webuzo-cache-purge/lifecycle.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(lockfd, 'w') as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        # Root-owned /var/log plus no-follow avoids writing a substituted log target.
        logfd = os.open(LOG, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        with os.fdopen(logfd, 'a', buffering=1) as log:
            trusted_file(Path(LOG))
            try:
                log.write(time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()) + ' ' + sys.argv[1] + '\n')
                with contextlib.redirect_stdout(log), contextlib.redirect_stderr(log):
                    if sys.argv[1] == 'install':
                        subprocess.run(['/usr/bin/python3', '-I', '-B', str(HERE / 'install.py'), '--all-webuzo-accounts', '--apply'], stdout=log, stderr=log, check=True, timeout=60)
                        accounts = len(json.loads(Path(DESTINATIONS['accounts']).read_text())['accounts'])
                        state = {'state': 'ready', 'accounts': accounts}
                    else:
                        # Stop before removing only known, validated files. Never touch cache data.
                        # Missing unit is already disabled (e.g. a failed first install).
                        load = subprocess.run(['systemctl', 'show', '--property=LoadState', '--value', UNIT], stdout=subprocess.PIPE, stderr=log, text=True, check=True, timeout=10)
                        if load.stdout.strip() != 'not-found':
                            subprocess.run(['systemctl', 'disable', '--now', UNIT], stdout=log, stderr=log, check=True, timeout=40)
                        for path in DESTINATIONS.values():
                            file = Path(path)
                            if file.exists() or file.is_symlink():
                                trusted_file(file)
                                file.unlink()
                        subprocess.run(['systemctl', 'daemon-reload'], stdout=log, stderr=log, check=True, timeout=20)
                        state = {'state': 'disabled', 'accounts': 0}
                    atomic_write(STATE, json.dumps(state).encode() + b'\n', 0o644)
            except Exception as error:
                log.write(type(error).__name__ + ': ' + str(error) + '\n')
                atomic_write(STATE, b'{"state":"error","accounts":0}\n', 0o644)
                raise SystemExit('Plugin lifecycle failed; inspect the root-only installation log.')
    print('Cache service lifecycle completed.')


if __name__ == '__main__':
    main()
