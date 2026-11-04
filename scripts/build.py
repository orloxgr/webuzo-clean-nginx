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
    'server/webuzo-cache-purge.service',
    'README.md',
    'docs/INSTALLATION.md',
    'LICENSE',
    'tests/service-contract.py',
    'tests/service-nginx-integration.py',
    'tests/service-client-runner.php',
)


def package(destination, sources):
    payloads = {name: (ROOT / source).read_bytes() for name, source in sources.items()}
    manifest = ''.join(hashlib.sha256(data).hexdigest() + '  ' + name + '\n'
                       for name, data in sorted(payloads.items()))
    payloads['SHA256SUMS.txt'] = manifest.encode('utf-8')
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
    plugin_sources = {name: name for name in PLUGIN}
    plugin_sources['webuzo-site-cache/LICENSE'] = 'LICENSE'
    package(plugin, plugin_sources)
    package(service, {name: name for name in SERVICE})
    checksums = ''.join(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.name + '\n'
                        for path in (plugin, service))
    (output / 'PUBLIC-SHA256SUMS.txt').write_text(checksums, encoding='utf-8')
    print(checksums, end='')


if __name__ == '__main__':
    main()
