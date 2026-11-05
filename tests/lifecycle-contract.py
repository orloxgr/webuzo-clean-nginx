#!/usr/bin/python3
"""Isolated lifecycle/cron logic. Fake identities; all writes stay in a temp tree."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / 'server/lifecycle.py'
spec = importlib.util.spec_from_file_location('bridge_lifecycle', SOURCE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
checks = []


def check(value, label):
    if not value:
        raise AssertionError(label)
    checks.append(label)


with tempfile.TemporaryDirectory(prefix='webuzo-lifecycle-test-') as temporary:
    base = Path(temporary)
    module.ROOT = base / 'data'
    module.CONFIG = base / 'etc/bridge.json'
    module.CRON = base / 'etc/cron.d/webuzo-clean-nginx'
    module.REFRESH_LOG = module.ROOT / 'refresh.log'
    module.CRON.parent.mkdir(parents=True)
    identities = [(SimpleNamespace(pw_uid=1001, pw_gid=os.getgid(), pw_name='account1'), 'a' * 64)]

    def fake_trusted(path, directory=False):
        # Permission/bundle validation is separately exercised on the real host.
        # No fixture may escape into a real deployment destination.
        if str(path).startswith(str(base)):
            assert not path.is_symlink(), 'Unexpected symlink in fixture'

    def unexpected_process(*args, **kwargs):
        raise AssertionError('Lifecycle logic must not launch a process in this fixture')

    def run(operation):
        with patch.object(module.sys, 'argv', ['lifecycle.py', operation]), contextlib.redirect_stdout(io.StringIO()):
            module.main()

    with contextlib.ExitStack() as stack:
        stack.enter_context(patch.object(module, 'validate_bundle', lambda: None))
        stack.enter_context(patch.object(module, 'trusted', fake_trusted))
        stack.enter_context(patch.object(module, 'retire_legacy', lambda: None))
        stack.enter_context(patch.object(module, 'identities', lambda: identities))
        stack.enter_context(patch.object(module.os, 'geteuid', lambda: 0))
        stack.enter_context(patch.object(module.os, 'fchown', lambda *args: None))
        stack.enter_context(patch.object(module.subprocess, 'run', unexpected_process))
        original_umask = os.umask(0o077)
        try:
            run('refresh')
            check(not module.ROOT.exists(), 'Refresh does not initialize a disabled installation')
            run('install')
            config = json.loads(module.CONFIG.read_text())
            first_id = next(iter(config['keys']))
            first_client = (module.ROOT / 'clients/1001.json').read_bytes()
            check(module.CRON.read_text() == module.CRON_TEXT, 'Activation installs exactly the fixed cron entry')
            check(module.CRON_TEXT.splitlines()[-1].split()[0] == '*/10', 'Cron interval is exactly ten minutes')
            check('45s' in module.CRON_TEXT and '--kill-after=2s' in module.CRON_TEXT, 'Cron command has bounded execution')
            check('--clear_cache' not in module.CRON_TEXT, 'Cron never invokes a purge command')
            check(json.loads((module.ROOT / 'status.json').read_text())['refresh_interval_minutes'] == 10, 'Status reports ten-minute interval')
            nonce = module.ROOT / 'state' / (first_id + '.json')
            nonce.write_text(json.dumps({'last_purge': 100, 'last_request': 101, 'nonces': {'fake-nonce': 101}}))
            state_before = nonce.read_bytes()
            identities.append((SimpleNamespace(pw_uid=1002, pw_gid=os.getgid(), pw_name='account2'), 'b' * 64))
            run('refresh')
            current = json.loads(module.CONFIG.read_text())
            check(len(current['keys']) == 2, 'Refresh enrolls a newly eligible account')
            check((module.ROOT / 'clients/1002.json').exists(), 'New account gets a client credential')
            check((module.ROOT / 'clients/1001.json').read_bytes() == first_client, 'Existing account credential is preserved')
            check(nonce.read_bytes() == state_before, 'Refresh preserves cooldowns and replay history')
            second_id = next(i for i, e in current['keys'].items() if e['uid'] == 1002)
            identities.pop()
            run('refresh')
            check(second_id not in json.loads(module.CONFIG.read_text())['keys'], 'Ineligible/deleted account is removed from registry')
            check(not (module.ROOT / 'clients/1002.json').exists(), 'Stale client credential removed')
            identities[0] = (identities[0][0], 'c' * 64)
            run('refresh')
            check(first_id not in json.loads(module.CONFIG.read_text())['keys'], 'Changed native account generation receives a fresh capability')
            check(not nonce.exists(), 'Old generation replay state removed')
            run('remove')
            check(not module.CRON.exists() and not module.CONFIG.exists(), 'Deactivation removes cron and revokes registry')
            run('refresh')
            check(not module.CRON.exists() and not module.CONFIG.exists(), 'Queued cron cannot reactivate the bridge')
            check(not list((module.ROOT / 'clients').iterdir()), 'No client credentials remain after removal')
        finally:
            os.umask(original_umask)

print(json.dumps({'passed': len(checks), 'checks': checks}, indent=2))
