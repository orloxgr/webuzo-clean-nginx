"""Linux-only isolated broker tests, runnable as an unprivileged Unix account.

Never imports production configuration, invokes main(), or touches real cache paths.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import socket
import struct
import tempfile
import threading
import unittest
from unittest.mock import patch
import zlib

module_path = Path(__file__).resolve().parents[1] / 'server/webuzo_cache_purge.py'
spec = importlib.util.spec_from_file_location('cache_broker', str(module_path))
broker_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker_module)


def request(operation, **extra):
    return json.dumps(dict({'v': 1, 'op': operation}, **extra)).encode()


class CacheTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wsc-broker-test-', dir=str(Path.home()))
        self.root = Path(self.temp.name)
        self.base = self.root / 'cache'
        self.base.mkdir()
        self.user = pwd.getpwuid(os.getuid())
        self.account = self.user.pw_name
        self.own = self.base / self.account
        self.own.mkdir()
        self.other = self.base / 'other_account_fixture'
        self.other.mkdir()
        # Fixture is owned by this test. /home may be execute-only for tenants;
        # production opens its fixed /var tree as root and still uses open_tree.
        self.base_fd = os.open(str(self.base), broker_module.DIR_FLAGS)
        self.registry = {str(os.getuid()): {'account': self.account, 'gid': self.user.pw_gid}}
        self.broker = broker_module.Broker(self.base_fd, self.registry)

    def tearDown(self):
        os.close(self.base_fd)
        self.temp.cleanup()

    def cache_file(self, account_dir, key='https://example.test/article', variant=False):
        encoded = key.encode()
        digest = hashlib.md5((encoded + b'vary') if variant else encoded).hexdigest()
        path = account_dir / digest[-1] / digest[-3:-1] / digest
        path.parent.mkdir(parents=True, exist_ok=True)
        header = bytearray(336)
        header[:8] = b'\x05\0\0\0\0\0\0\0'
        struct.pack_into('<I', header, 48, zlib.crc32(encoded))
        header_start = 343 + len(encoded)
        body = b'HTTP/1.1 200 OK\r\n\r\nbody'
        struct.pack_into('<HH', header, 54, header_start, header_start)
        if variant:
            header[187] = 1
            header[316:332] = bytes.fromhex(digest)
        path.write_bytes(header + b'\nKEY: ' + encoded + b'\n' + body)
        return path

    def test_status_has_no_file_operations(self):
        file = self.cache_file(self.own)
        response = self.broker.execute(os.getuid(), request('status'))
        self.assertEqual(response['account'], self.account)
        self.assertNotIn('result', response)
        self.assertTrue(file.exists())

    def test_preview_preserves_files(self):
        file = self.cache_file(self.own)
        result = self.broker.execute(os.getuid(), request('preview'))['result']
        self.assertEqual(result['matched'], 1)
        self.assertEqual(result['deleted'], 0)
        self.assertTrue(file.exists())

    def test_purge_is_account_scoped(self):
        own = self.cache_file(self.own)
        other = self.cache_file(self.other)
        result = self.broker.execute(os.getuid(), request('purge'))['result']
        self.assertEqual(result['deleted'], 1)
        self.assertFalse(own.exists())
        self.assertTrue(other.exists())

    def test_other_uid_cannot_use_this_account(self):
        own = self.cache_file(self.own)
        with self.assertRaises(broker_module.Rejected):
            self.broker.execute(os.getuid() + 100000, request('purge'))
        self.assertTrue(own.exists())

    def test_root_is_not_a_tenant(self):
        with self.assertRaises(broker_module.Rejected):
            self.broker.execute(0, request('purge'))

    def test_payload_cannot_select_account_path_or_uid(self):
        for field, value in [('account', 'other_account_fixture'), ('path', '/'), ('uid', 0), ('command', 'delete_all'), ('host', 'other.test')]:
            with self.subTest(field=field), self.assertRaises(broker_module.Rejected):
                self.broker.execute(os.getuid(), request('purge', **{field: value}))

    def test_unknown_operations_and_protocol_are_rejected(self):
        for raw in [request('upload'), request('delete'), b'{"v":true,"op":"purge"}', b'{"v":1,"op":"status","op":"purge"}', b'[]', b'not-json']:
            with self.subTest(raw=raw), self.assertRaises(broker_module.Rejected):
                broker_module.parse_request(raw)

    def test_identity_reuse_is_detected(self):
        self.registry[str(os.getuid())]['account'] = 'a_different_account'
        with self.assertRaises(broker_module.Rejected):
            self.broker.execute(os.getuid(), request('purge'))

    def test_same_account_has_a_cooldown(self):
        self.broker.execute(os.getuid(), request('preview'))
        with self.assertRaises(broker_module.Rejected) as captured:
            self.broker.execute(os.getuid(), request('purge'))
        self.assertEqual(captured.exception.code, 'rate_limited')

    def test_account_symlink_is_rejected(self):
        self.own.rmdir()
        self.own.symlink_to(self.other, target_is_directory=True)
        with self.assertRaises(broker_module.Rejected):
            self.broker.execute(os.getuid(), request('purge'))

    def test_links_and_corrupt_cache_are_preserved(self):
        other = self.cache_file(self.other)
        own = self.cache_file(self.own)
        own.unlink()
        own.symlink_to(other)
        malformed = self.cache_file(self.own, 'https://example.test/malformed')
        malformed.write_bytes(b'fake\nKEY: https://example.test/malformed\n')
        result = self.broker.execute(os.getuid(), request('purge'))['result']
        self.assertEqual(result['deleted'], 0)
        self.assertGreaterEqual(result['unknown'], 2)
        self.assertTrue(other.exists())
        self.assertTrue(own.is_symlink())
        self.assertTrue(malformed.exists())

    def test_hardlinks_are_preserved(self):
        own = self.cache_file(self.own)
        external = self.root / 'external-hardlink'
        os.link(own, external)
        result = self.broker.execute(os.getuid(), request('purge'))['result']
        self.assertEqual(result['deleted'], 0)
        self.assertTrue(own.exists() and external.exists())

    def test_vary_entry_is_recognized(self):
        file = self.cache_file(self.own, variant=True)
        result = self.broker.execute(os.getuid(), request('purge'))['result']
        self.assertEqual(result['deleted'], 1)
        self.assertFalse(file.exists())

    def test_unrecognized_files_are_never_removed(self):
        sentinel = self.own / 'wp-config.php'
        sentinel.write_text('not a cache file')
        self.broker.execute(os.getuid(), request('purge'))
        self.assertTrue(sentinel.exists())

    def test_entry_budget_reports_incomplete(self):
        self.cache_file(self.own)
        result = broker_module.scan_account(self.base_fd, self.account, max_entries=0)
        self.assertFalse(result['complete'])
        self.assertEqual(result['deleted'], 0)

    def test_deadline_reports_incomplete(self):
        self.cache_file(self.own)
        self.assertFalse(broker_module.scan_account(self.base_fd, self.account, seconds=0)['complete'])

    def test_directory_swap_does_not_follow_symlink(self):
        own = self.cache_file(self.own)
        other = self.cache_file(self.other)
        first = own.parent.parent.name
        original_open = os.open
        swapped = []
        def open_and_swap(path, flags, *args, **kwargs):
            fd = original_open(path, flags, *args, **kwargs)
            if path == first and flags & os.O_DIRECTORY and not swapped:
                os.rename(self.own / first, self.own / 'detached-fixture')
                (self.own / first).symlink_to(self.other / first, target_is_directory=True)
                swapped.append(True)
            return fd
        with patch.object(broker_module.os, 'open', side_effect=open_and_swap):
            self.broker.execute(os.getuid(), request('purge'))
        self.assertTrue(swapped)
        self.assertTrue(other.exists())

    def test_file_swap_after_validation_is_not_followed(self):
        own = self.cache_file(self.own)
        other = self.cache_file(self.other)
        original = broker_module.valid_entry
        def swap(fd, name):
            metadata = original(fd, name)
            own.unlink()
            own.symlink_to(other)
            return metadata
        with patch.object(broker_module, 'valid_entry', side_effect=swap):
            result = self.broker.execute(os.getuid(), request('purge'))['result']
        self.assertEqual(result['deleted'], 0)
        self.assertFalse(result['complete'])
        self.assertTrue(other.exists())

    def test_kernel_reports_actual_peer_uid(self):
        a, b = socket.socketpair()
        try:
            self.assertEqual(broker_module.peer_uid(a), os.getuid())
        finally:
            a.close()
            b.close()

    def test_socket_protocol_dispatches_by_kernel_uid(self):
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        path = str(self.root / 'control.sock')
        listener.bind(path)
        listener.listen(4)
        stop = threading.Event()
        thread = threading.Thread(target=broker_module.serve, args=(listener, self.broker, stop))
        thread.start()
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                connection.settimeout(3)
                connection.connect(path)
                connection.sendall(request('status') + b'\n')
                response = json.loads(connection.recv(4096))
                self.assertTrue(response['ok'])
                self.assertEqual(response['account'], self.account)
        finally:
            stop.set()
            thread.join(timeout=4)
            listener.close()
            self.assertFalse(thread.is_alive())


if __name__ == '__main__':
    unittest.main(verbosity=2)
