# Webuzo Clean Nginx

WordPress plugin and a local Linux service for clearing a Webuzo hosting account's Nginx cache without storing a hosting API key in WordPress.

By **Byron Iniotakis**. Licensed under GPL-2.0-or-later.

> **Review candidate:** privileged service startup, systemd sandbox behavior, production PHP-FPM connectivity and isolation between two real hosting accounts still require deployment acceptance testing. This is not an independently audited security product.

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

Read **[the administrator installation and removal guide](docs/INSTALLATION.md)** before any privileged operation. The installer prints a plan by default; `--apply` is a separate explicit step.

After the service is installed and the account enrolled:

1. Build the packages with `python scripts/build.py`.
2. Upload the WordPress ZIP from `dist/`, activate it and open **Tools → Webuzo Site Cache**.
3. Select **Local cache service**, save and **Test connection**.
4. Optionally preview the cache. Wait 10 seconds between previews or purges.
5. Use **Nginx cache → Clear Cache** in the toolbar, or the settings-page button.

WordPress actions require `manage_options`, POST and a nonce. The scope warning remains visible; there is no purge confirmation checkbox. Manual purges only; no multisite support or automatic purging.

## Security boundaries and limits

The broker is root-owned and runs with a restricted systemd unit. The socket accepts local connections, but Linux UID credentials and explicit account enrollment determine authorization. No API key or shared secret is needed in local mode. The broker rejects root and unenrolled callers and checks the account name and primary GID against passwd on each request.

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

The test suites cover 39 WordPress/API checks, 8 local-client checks, 20 Linux broker tests and 18 real Nginx/PHP/socket integration assertions. The second cache account in integration is a directory fixture, not a second real connecting UID. The PHP client's production root-owned-socket check uses stubs; the integration test uses a test socket harness. Real privileged startup, sandbox execution, PHP-FPM access and two distinct real caller UIDs remain acceptance checks.

## Repository contents

- `webuzo-site-cache/`: WordPress plugin, version 0.3.0.
- `server/`: broker, version 0.1.0, plan-first installer and systemd unit.
- `docs/`: generic administrator instructions.
- `tests/`: isolated contracts and Linux integration tests.
- `scripts/build.py`: creates packages from an explicit source allowlist.

Local deployment reports, screenshots, credentials, customer configuration and historical packages are excluded. Generated packages belong in ignored `dist/` and contain only allowlisted public sources.
