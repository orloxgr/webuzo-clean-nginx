# Webuzo Clean Nginx

By **Byron Iniotakis**. GPL-2.0-or-later. Version **0.5.0**, review candidate.

A Webuzo administrator plugin and companion WordPress plugin for clearing a hosting account's Nginx cache using **Webuzo's existing privileged backend and native clear command**.

## How it works

1. The hosting administrator activates the Webuzo plugin. It provisions a random signing credential for each eligible account.
2. WordPress reads only its own credential from a root-owned file outside the websites. No panel password or general Webuzo API key is needed.
3. WordPress signs a short request to the existing local end-user panel on `127.0.0.1:2002`.
4. The active Webuzo plugin handles one dedicated action, validates the signature, timestamp, nonce and account identity, and selects the account from its root-only registry.
5. The existing root PHP backend runs the fixed native command:

   ```sh
   /usr/local/emps/bin/php /usr/local/webuzo/cli.php --clear_cache --users=ACCOUNT
   ```

**All websites in the hosting account are affected.** The request cannot contain an account name, path, domain, command or uploaded file. The only operations are `status` and `purge`.

The native operation deletes cache contents from disk; this is not an Nginx HTTP `PURGE` module. The plugin delegates that operation to Webuzo. It does not have its own cache-file scanner/deleter, persistent purge daemon, cron, timer, sudo rule or setuid helper. It does not patch Webuzo core or Nginx/PHP configuration.

## Installation

Read [installation and removal](docs/INSTALLATION.md) and [security scope](docs/SECURITY-REVIEW.md).

1. Build with `python scripts/build.py`.
2. Upload `webuzo-clean-nginx-0.5.0-public-review.zip` in **Webuzo Admin → Plugins → Installed Plugins → Add New**, then activate it.
3. Upload `webuzo-site-cache-0.5.0-public-review.zip` in WordPress and activate it.
4. Open **Tools → Webuzo Site Cache → Test connection**.
5. Use **Clear Cache → Clear Cache** in the administrator toolbar, or the settings-page button.

Wait 2 seconds between requests and 10 seconds between purges. WordPress requires `manage_options`, POST and an operation-specific nonce. The account-wide warning remains visible; there is no confirmation checkbox or filesystem preview.

Account enrollment is a snapshot of eligible native accounts with cache directories and private primary groups. Deactivate/reactivate after creating accounts or enabling cache. Existing account identity and Webuzo creation metadata are checked on every request; a deleted/recreated account cannot inherit an old capability merely by reusing its Unix UID. No automatic account-reconciliation cron is installed.

## Requirements and limits

- Tested on Webuzo **4.9.1**, Ubuntu **24.04**, native Nginx caching, panel PHP **7.4**, and WordPress PHP **8.3**.
- WordPress 6.0+, PHP 7.4+ with POSIX, hashing and TCP stream sockets; single-site WordPress only.
- The existing end-user panel must listen locally on port 2002. The active-plugin loading stage and native CLI must match the tested Webuzo behavior.
- Linux hosting accounts need separate UIDs and private primary groups. Shared groups are not enrolled.
- The hosting administrator installs the Webuzo plugin once per server. Customers do not need root or SSH; providers on other servers must install the companion plugin themselves.
- Python 3 is needed only for native activation/deactivation. GNU `timeout` bounds native child execution. No packages are installed automatically.

Requests and responses are authenticated with HMAC-SHA256. Only a public random credential identifier and signature are sent, never the signing secret. The loopback transport uses the existing HTTP panel; it is not encrypted. Fresh nonces, expiry and signed responses prevent accepting a forged or replayed result.

A stolen account credential permits connection checks and cache clears for that account from the local host. The compromised account already controls its own PHP and sites. Other accounts' credentials and the root registry must remain inaccessible. The bridge is new security-sensitive code running inside Webuzo's existing root backend; using a native command does not make that code unprivileged.

The UI confirms that the native command completed normally. It does not claim independently verified deletion of every cache entry: Webuzo can suppress internal filesystem errors, and live traffic can refill cache. Validate actual cache behavior on each deployment and after Webuzo upgrades.

## Upgrading from earlier candidates

Version 0.5.0 replaces the previous filesystem broker. Activation retires only the previous plugin's known daemon, unit and registry. WordPress no longer offers general API compatibility or reads/decrypts saved API credentials. On an administrator visit it removes the old plugin settings and, when a saved key existed, displays a reminder to **revoke that key separately in Webuzo**. Database backups may still contain old settings.

## Verification

```sh
php tests/native-bridge-contract.php
php tests/native-client-contract.php
php tests/wordpress-contract.php
```

These portable protocol/client/WordPress stub suites contain 29, 14 and 24 assertions. They do not clear live cache. Native deployment acceptance also exercises actual WordPress HTTP/PHP-FPM, two hosting accounts, filesystem attacks, native plugin lifecycle and credential revocation. See the report for the precise tested scope. These are developer-run checks, not an independent penetration test.

## Public packages

`scripts/build.py` uses explicit source allowlists and creates deterministic ZIPs with a checksum manifest inside each plugin folder. Local environments, deployment harnesses, credentials and infrastructure details are excluded. Generated packages belong in ignored `dist/`.
