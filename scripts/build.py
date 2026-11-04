"""Build public packages from explicit source lists; never copy local reports."""
import hashlib
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = (
    'webuzo-site-cache/webuzo-site-cache.php',
    'webuzo-site-cache/readme.txt',
    'webuzo-site-cache/includes/class-webuzo-api.php',
    'webuzo-site-cache/includes/class-local-service.php',
)
SERVICE = (
    'server/webuzo_cache_purge.py',
    'server/install.py',
    'server/lifecycle.py',
    'server/webuzo-cache-purge.service',
    'README.md',
    'docs/INSTALLATION.md',
    'docs/SECURITY-REVIEW.md',
    'LICENSE',
    'tests/service-contract.py',
    'tests/service-nginx-integration.py',
    'tests/service-client-runner.php',
)
NATIVE = (
    'webuzo-clean-nginx/webuzo-clean-nginx.php',
    'webuzo-clean-nginx/info.json',
)


def package(destination, sources, manifest_path='SHA256SUMS.txt'):
    payloads = {name: (ROOT / source).read_bytes().replace(b'\r\n', b'\n') for name, source in sources.items()}
    manifest = ''.join(hashlib.sha256(data).hexdigest() + '  ' + name + '\n'
                       for name, data in sorted(payloads.items()))
    payloads[manifest_path] = manifest.encode('utf-8')
    with zipfile.ZipFile(destination, 'w') as bundle:
        for name, data in sorted(payloads.items()):
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            bundle.writestr(entry, data)
    with zipfile.ZipFile(destination) as bundle:
        assert bundle.testzip() is None
        assert set(bundle.namelist()) == set(payloads)
        for name, data in payloads.items():
            assert bundle.read(name) == data


def main():
    source = (ROOT / PLUGIN[0]).read_text(encoding='utf-8')
    version = re.search(r'^ \* Version: ([0-9.]+)$', source, re.M).group(1)
    output = ROOT / 'dist'
    output.mkdir(exist_ok=True)
    plugin = output / ('webuzo-site-cache-' + version + '-public-review.zip')
    service = output / ('webuzo-cache-service-' + version + '-public-review.zip')
    native = output / ('webuzo-clean-nginx-' + version + '-public-review.zip')
    plugin_sources = {name: name for name in PLUGIN}
    plugin_sources['webuzo-site-cache/LICENSE'] = 'LICENSE'
    package(plugin, plugin_sources, 'webuzo-site-cache/SHA256SUMS.txt')
    package(service, {name: name for name in SERVICE})
    native_sources = {name: name for name in NATIVE}
    for name in ('lifecycle.py', 'install.py', 'webuzo_cache_purge.py', 'webuzo-cache-purge.service'):
        native_sources['webuzo-clean-nginx/service/' + name] = 'server/' + name
    native_sources['webuzo-clean-nginx/LICENSE'] = 'LICENSE'
    package(native, native_sources, 'webuzo-clean-nginx/SHA256SUMS.txt')
    checksums = ''.join(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.name + '\n'
                        for path in (plugin, service, native))
    (output / 'PUBLIC-SHA256SUMS.txt').write_text(checksums, encoding='utf-8')
    print(checksums, end='')


if __name__ == '__main__':
    main()
