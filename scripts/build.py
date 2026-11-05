"""Build public packages from explicit source lists; never copy local reports."""
import hashlib
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = (
    'clear-nginx-for-webuzo/clear-nginx-for-webuzo.php',
    'clear-nginx-for-webuzo/readme.txt',
    'clear-nginx-for-webuzo/includes/class-native-panel.php',
    'clear-nginx-for-webuzo/includes/class-plugin.php',
    'clear-nginx-for-webuzo/assets/admin-bar.css',
    'clear-nginx-for-webuzo/languages/clear-nginx-for-webuzo.pot',
)
NATIVE = (
    'webuzo-clean-nginx/webuzo-clean-nginx.php',
    'webuzo-clean-nginx/info.json',
    'webuzo-clean-nginx/includes/class-native-bridge.php',
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
    assert not re.search(r'^ \* Update URI:', source, re.M), 'WordPress.org packages must not override the update URI.'
    version = re.search(r'^ \* Version: ([0-9.]+)$', source, re.M).group(1)
    assert json.loads((ROOT / 'webuzo-clean-nginx/info.json').read_text())['version'] == version
    assert '\nVersion: ' + version + '\n' in (ROOT / NATIVE[0]).read_text()
    output = ROOT / 'dist'
    output.mkdir(exist_ok=True)
    plugin = output / ('clear-nginx-for-webuzo-' + version + '-public-review.zip')
    native = output / ('webuzo-clean-nginx-' + version + '-public-review.zip')
    plugin_sources = {name: name for name in PLUGIN}
    plugin_sources['clear-nginx-for-webuzo/LICENSE'] = 'LICENSE'
    package(plugin, plugin_sources, 'clear-nginx-for-webuzo/SHA256SUMS.txt')
    native_sources = {name: name for name in NATIVE}
    for name in ('lifecycle.py',):
        native_sources['webuzo-clean-nginx/service/' + name] = 'server/' + name
    native_sources['webuzo-clean-nginx/LICENSE'] = 'LICENSE'
    for name in ('README.md', 'docs/INSTALLATION.md', 'docs/SECURITY-REVIEW.md', 'docs/WORDPRESS-QUALITY.md'):
        native_sources['webuzo-clean-nginx/' + name] = name
    package(native, native_sources, 'webuzo-clean-nginx/SHA256SUMS.txt')
    checksums = ''.join(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + path.name + '\n'
                        for path in (plugin, native))
    (output / 'PUBLIC-SHA256SUMS.txt').write_text(checksums, encoding='utf-8')
    print(checksums, end='')


if __name__ == '__main__':
    main()
