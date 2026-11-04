"""Real Nginx + PHP + Unix broker in disposable, non-root, loopback-only fixtures."""
import contextlib
import http.server
import importlib.util
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.request

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('broker', str(root / 'server/webuzo_cache_purge.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
nginx_binary = os.environ.get('NGINX_BINARY') or shutil.which('nginx')
php_binary = os.environ.get('PHP_BINARY') or shutil.which('php')
if not nginx_binary or not php_binary:
    raise SystemExit('Install nginx/PHP or set NGINX_BINARY and PHP_BINARY to their executable paths.')
checks = []


def check(value, message):
    if not value:
        raise AssertionError(message)
    checks.append(message)


class Origin(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        content = b'Real isolated cache test'
        self.send_response(200)
        self.send_header('Cache-Control', 'public, max-age=600')
        self.send_header('Content-Length', str(len(content)))
        if self.path.startswith('/vary'):
            self.send_header('Vary', 'Accept-Language')
        self.end_headers()
        self.wfile.write(content)

    def log_message(self, *args):
        pass


def stop_nginx(process):
    process.terminate()
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)


with tempfile.TemporaryDirectory(prefix='wsc-service-nginx-', dir=str(Path.home())) as directory, contextlib.ExitStack() as cleanup:
    sandbox = Path(directory)
    base = sandbox / 'cache'
    base.mkdir()
    identity = pwd.getpwuid(os.getuid())
    own = base / identity.pw_name
    other = base / 'other_account_fixture'
    own.mkdir()
    other.mkdir()
    base_fd = os.open(str(base), module.DIR_FLAGS)
    cleanup.callback(os.close, base_fd)
    origin = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Origin)
    origin_thread = threading.Thread(target=origin.serve_forever, daemon=True)
    origin_thread.start()
    cleanup.callback(origin.server_close)
    cleanup.callback(origin.shutdown)
    with socket.socket() as temporary:
        temporary.bind(('127.0.0.1', 0))
        port = temporary.getsockname()[1]
    config = sandbox / 'nginx.conf'
    servers = ''
    for zone, names in [('own', 'example.test same-account.test'), ('other', 'other-account.test')]:
        servers += '''server { listen 127.0.0.1:%d; server_name %s;
        location / { proxy_pass http://127.0.0.1:%d; proxy_cache %s;
        proxy_cache_key "$prefix$scheme://$host$request_uri";
        add_header X-Cache-Status $upstream_cache_status always; } }\n''' % (port, names, origin.server_port, zone)
    config.write_text('''daemon off; master_process off; pid %s/pid;
error_log %s/error.log notice; events { worker_connections 32; }
http { access_log off; client_body_temp_path %s/client; proxy_temp_path %s/proxy;
proxy_cache_path %s levels=1:2 keys_zone=own:1m inactive=10m;
proxy_cache_path %s levels=1:2 keys_zone=other:1m inactive=10m;
map $http_x_mobile $prefix { default ""; "1" "mobile_"; }
%s }
''' % (sandbox, sandbox, sandbox, sandbox, own, other, servers))
    log = open(sandbox / 'process.log', 'wb')
    cleanup.callback(log.close)
    nginx = subprocess.Popen([nginx_binary, '-p', str(sandbox) + '/', '-c', str(config)], stdout=log, stderr=log)
    cleanup.callback(stop_nginx, nginx)
    broker = module.Broker(base_fd, {str(os.getuid()): {'account': identity.pw_name, 'gid': identity.pw_gid}})
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    cleanup.callback(listener.close)
    socket_path = str(sandbox / 'control.sock')
    listener.bind(socket_path)
    listener.listen(4)
    stop = threading.Event()
    thread = threading.Thread(target=module.serve, args=(listener, broker, stop))
    thread.start()
    cleanup.callback(thread.join, timeout=4)
    cleanup.callback(stop.set)

    def request(host, path, language='en', mobile=False):
        req = urllib.request.Request('http://127.0.0.1:%d%s' % (port, path), headers={
            'Host': host, 'Accept-Language': language, 'X-Mobile': '1' if mobile else '0'})
        with urllib.request.urlopen(req, timeout=5) as response:
            response.read()
            return response.headers.get('X-Cache-Status')

    def php(operation):
        data = subprocess.check_output([php_binary, str(root / 'tests/service-client-runner.php'), socket_path, operation], text=True)
        return json.loads(data)

    for unused in range(50):
        try:
            request('example.test', '/ready')
            break
        except OSError:
            if nginx.poll() is not None:
                raise AssertionError((sandbox / 'process.log').read_text())
            time.sleep(.1)
    fixtures = [('example.test', '/article'), ('example.test', '/article?a=1'),
        ('example.test', '/mobile', 'en', True), ('example.test', '/vary', 'en'),
        ('example.test', '/vary', 'el'), ('same-account.test', '/article'), ('other-account.test', '/article')]
    for fixture in fixtures:
        request(*fixture)
        check(request(*fixture) == 'HIT', 'Real Nginx HIT: ' + repr(fixture))
    status = php('status')
    check(status['ok'] and status['account'] == identity.pw_name, 'PHP connects via Unix socket; kernel UID selects the account')
    preview = broker.execute(os.getuid(), b'{"v":1,"op":"preview"}')
    check(preview['result']['matched'] >= 6 and preview['result']['deleted'] == 0, 'Python parser recognizes real Nginx mobile/query/Vary entries without deleting them')
    broker.last_scan.clear()  # Test-only clock reset, not a production protocol capability.
    time.sleep(.12)  # Respect the connection admission limit after status.
    cleared = php('purge')
    check(cleared['ok'] and cleared['result']['complete'] and cleared['result']['deleted'] >= 6,
        'PHP purge through the broker removes recognized cache entries in its own account')
    for fixture in fixtures[:-1]:
        check(request(*fixture) == 'MISS', 'Same-account entry becomes MISS: ' + repr(fixture))
    check(request(*fixtures[-1]) == 'HIT', 'A different account retains its cached HIT')
    time.sleep(.12)
    check(php('status')['account'] == identity.pw_name, 'Status remains read-only after purge')

print(json.dumps({'passed': len(checks), 'checks': checks, 'uid': os.getuid(),
    'production_main_started': False, 'live_cache_touched': False, 'root_used': False}, indent=2))
