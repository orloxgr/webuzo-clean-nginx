# Webuzo Clean Nginx

Webuzo administrator plugin, companion WordPress plugin and local Linux service for clearing a hosting account's Nginx cache without storing a hosting API key in WordPress.

By **Byron Iniotakis**. Licensed under GPL-2.0-or-later.

> **Lab-tested release candidate:** native installation/removal, real PHP-FPM connectivity, two-account isolation and focused adversarial checks passed in an isolated Ubuntu/Webuzo lab. This is not an independent penetration test or a guarantee of security on every host. See [test scope and limitations](docs/SECURITY-REVIEW.md).

## How it works

WordPress connects to a fixed Unix socket. Linux supplies the PHP process's actual UID through `SO_PEERCRED`. A root-owned registry maps that UID to its approved hosting account. The service accepts only `status`, `preview` and `purge`; callers cannot supply an account, domain, filesystem path or shell command.

**Clearing affects every website in the same hosting account.** All processes sharing its Unix UID share purge authority. A compromised site can clear that account's cache within the service's rate limits. Other access available to PHP under its normal filesystem permissions is outside this service's scope.

The service recognizes Nginx v5 x86-64 cache files using the `levels=1:2` layout under `/var/webuzo-data/nginx_proxy_cache/ACCOUNT`. It uses directory file descriptors and no-follow opens, skips unknown formats, symlinks and hardlinks, and unlinks recognized cache files without removing directories. It does not execute a shell or call the Webuzo API.

## Requirements

- WordPress 6.0+; PHP 7.4+ with Unix stream sockets; single-site installations.
- Hosting administrator access for one-time service installation on each server.
- Linux, systemd and Python 3, with a separate Unix UID per hosting account and a supported Webuzo Nginx cache layout.
- PHP restrictions must permit access to the service socket.

The plugin alone cannot bypass filesystem permissions on a server where the provider has not installed a suitable purge service. Other hosting providers must install and enroll the service themselves.

## Installation

Read **[the administrator installation and removal guide](docs/INSTALLATION.md)** before activation. The native Webuzo plugin installs only its own service/code/registry; it does not patch Webuzo, Nginx or PHP configuration or restart those services. Its lifecycle uses the [official Webuzo plugin interface](https://www.webuzo.com/docs/developers/plugins/).

1. Build the packages with `python scripts/build.py`.
2. In **Webuzo Admin → Plugins → Installed Plugins → Add New**, upload `webuzo-clean-nginx-0.4.0-public-review.zip` and activate it. Open **Nginx Cache** to check installation status.
3. Upload `webuzo-site-cache-0.4.0-public-review.zip` to WordPress, activate it and open **Tools → Webuzo Site Cache**.
4. Select **Local cache service**, save and **Test connection**.
5. Optionally preview the cache. Wait 10 seconds between previews or purges.
6. Use **Clear Cache → Clear Cache** in the toolbar, or the settings-page button.

Activation enrolls existing eligible Unix accounts that have native Nginx cache directories. After adding accounts or enabling their cache, deactivate/reactivate the Webuzo plugin to rebuild enrollment. Deactivation removes the installed broker, unit and registry; cache data is preserved. The plugin directory, installation log and small status/lock files remain. A manual plan-first installer is also available in the separate service bundle.

WordPress actions require `manage_options`, POST and a nonce. The scope warning remains visible; there is no purge confirmation checkbox. Manual purges only; no multisite support or automatic purging.

## Security boundaries and limits

The broker is root-owned and runs with a restricted systemd unit. The socket accepts local connections, but Linux UID credentials and explicit account enrollment determine authorization. No API key or shared secret is needed in local mode. The broker rejects root and unenrolled callers and checks the account name and primary GID against passwd on each request.

The privileged Webuzo panel and its system identity are part of the trusted host boundary. Native `/usr/local/webuzo` group-write access is accepted only for a system `webuzo` group containing no ordinary tenant. The broker permits the native system `webuzo` owner of `/var/webuzo-data`; other cache-base ancestors must be root owned and not group/world writable. Hosting tenants must never have membership in the privileged panel group.

Limits include one active scan per account, a 10-second cooldown, 8 workers, 512-byte requests, a 2-second total request-read deadline, and 100,000 visited entries / 15 seconds per scan. The unit limits CPU, memory, tasks, address families, capabilities and filesystem access.

Large or unsupported caches can produce incomplete scans. Repeated scans are not guaranteed to make progress past unchanged unsupported entries. Concurrent Nginx requests can replace or rebuild cache files. A completed scan is not an atomic transaction that freezes the cache. Root broker code remains security-sensitive; these controls do not guarantee protection against every attack or denial of service.

## Optional Webuzo API compatibility

API mode must be selected explicitly. **There is no automatic fallback to an account API key.** Existing settings without a transport select local mode. Previously saved encrypted keys remain stored until removed, but local mode does not read or send them. Remove the saved key and revoke it separately in Webuzo when retiring API mode.

Compatibility mode uses the HTTPS end-user API, checks domain membership and requires native purge success confirmation. TLS verification stays enabled; redirects are refused. Credentials are encrypted with WordPress salts. This protects against a database-only disclosure, while compromised PHP with access to the salts can recover the key. Ordinary account API keys can authorize actions beyond cache clearing; an IP restriction does not narrow those actions.

## Tests

```sh
php tests/api-contract.php
php tests/local-client-contract.php
python3 -I -B tests/service-contract.py
python3 -I -B tests/service-nginx-integration.py
```

Service tests require Linux and a non-root test user with UID ≥1000. Integration also needs Nginx and PHP on PATH, or `NGINX_BINARY` and `PHP_BINARY` set to executable paths. It creates disposable fixtures and loopback-only listeners; it does not invoke the production service entry point or read live cache/configuration.

The portable suites cover 39 WordPress/API checks, 8 local-client checks, 20 Linux broker tests and 18 real Nginx/PHP/socket integration assertions. Their integration fixture uses one connecting UID. Separate deployment acceptance on the native lab service exercised two real hosting UIDs, real WordPress admin HTTP requests and PHP-FPM, lifecycle removal/reactivation and filesystem/protocol attacks. Private deployment harnesses, credentials and environment details are intentionally excluded; the public report describes their scope without identifying infrastructure.

## Repository contents

- `webuzo-clean-nginx/`: native Webuzo administrator plugin, version 0.4.0.
- `webuzo-site-cache/`: WordPress plugin, version 0.4.0.
- `server/`: broker, version 0.2.0, native lifecycle, plan-first installer and systemd unit.
- `docs/`: generic administrator instructions.
- `tests/`: isolated contracts and Linux integration tests.
- `scripts/build.py`: creates packages from an explicit source allowlist.

Local deployment reports, screenshots, credentials, customer configuration and historical packages are excluded. Generated packages belong in ignored `dist/` and contain only allowlisted public sources.
