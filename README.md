# Webuzo Clean Nginx

Version **0.5.7**, review candidate.

A Webuzo plugin and companion WordPress plugin that let WordPress administrators clear their hosting account's Nginx cache from the WordPress dashboard or toolbar.

This project is an independent third-party integration. It is not affiliated with, endorsed, sponsored, or supported by Webuzo or Softaculous. The Webuzo name is used only to describe compatibility with the hosting platform.

**Clearing cache affects every website in the same hosting account.** This version clears account-wide cache; it does not offer per-site or per-URL purging.

## Downloads

Download the two installable ZIPs from [release v0.5.7](https://github.com/orloxgr/webuzo-clean-nginx/releases/tag/v0.5.7). The release also includes `SHA256SUMS.txt` for checking the downloads.

| Package | Install in | Purpose |
| --- | --- | --- |
| [Webuzo Clean Nginx](https://github.com/orloxgr/webuzo-clean-nginx/releases/download/v0.5.7/webuzo-clean-nginx-0.5.7-public-review.zip) | Webuzo Admin, once per server | Enroll accounts and handle authenticated cache-clear requests through Webuzo's native backend |
| [Clear Nginx for Webuzo](https://github.com/orloxgr/webuzo-clean-nginx/releases/download/v0.5.7/clear-nginx-for-webuzo-0.5.7-public-review.zip) | Each WordPress installation that needs a clear-cache button | Test the connection and request a manual account cache clear |

Use these release assets when installing. GitHub's **Source code** archives contain the repository and are not the individual plugin packages.

The WordPress package uses `clear-nginx-for-webuzo/clear-nginx-for-webuzo.php`, namespace `ClearNginxForWebuzo`, and prefix `clear_nginx_for_webuzo_` for actions, nonces, notices and error codes. No compatibility aliases or legacy WordPress settings migration are included.

## Installation and use

1. The hosting administrator uploads the Webuzo ZIP in **Webuzo Admin → Plugins → Installed Plugins → Add New**, then activates it.
2. Open **Nginx Cache** in Webuzo to check the bridge status, enrolled-account count and last enrollment sync.
3. Upload and activate the WordPress ZIP in **WordPress → Plugins → Add New → Upload Plugin**.
4. Open **Tools → Clear Nginx for Webuzo** and click **Test connection**. No panel password, API key or connection settings need to be entered.
5. Click **Clear Cache** directly in the WordPress administrator toolbar, or **Clear Nginx cache for this account** on the tools page. The toolbar submits a nonce-protected POST and returns to the current page; its dropdown contains a separate **Settings** link.

The tools page displays an account-wide warning. Connection tests do not clear cache. Cache clearing is manual; publishing or updating a post does not automatically purge it. New visits rebuild cache after a clear.

WordPress actions require `manage_options`, POST and an operation-specific nonce. Wait 2 seconds between connection checks and 10 seconds between cache clears. This release supports single-site WordPress.

See [installation, upgrade and removal](docs/INSTALLATION.md) for permissions, installed files and troubleshooting, and [security review](docs/SECURITY-REVIEW.md) for the tested scope and limitations.

Version 0.5.7 makes the main toolbar button clear cache directly and return to the current page, keeps Settings separate, and simplifies the tools-page text. The native command and ten-minute enrollment cron are unchanged. One documented `@lstat()` coding-standard annotation remains for the metadata security check. See [WordPress quality checks](docs/WORDPRESS-QUALITY.md) for the exact results and exception.

## New accounts and scheduled enrollment

Activation enrolls existing eligible accounts and installs the plugin's own root-owned cron entry at `/etc/cron.d/webuzo-clean-nginx`. Its schedule, `*/10 * * * *`, runs at minutes **0, 10, 20, 30, 40 and 50** to synchronize account credentials. **The cron never clears cache.**

New accounts become available after their native cache/proxy is enabled and the next successful sync. Accounts whose cache is enabled later are also picked up. Valid credentials, replay history and purge cooldowns are preserved; disabled or deleted accounts lose their enrollment, and recreated identities receive fresh credentials.

A busy lifecycle lock skips that run and retries at the next scheduled interval. Check the Webuzo plugin's last-sync time and `/var/lib/webuzo-clean-nginx/refresh.log` if enrollment is delayed. A working system cron is required. Deactivating the Webuzo plugin removes its scheduled task and revokes the credentials without clearing cache.

## How it works

1. The hosting administrator activates the Webuzo plugin. It provisions a random signing credential for each eligible account.
2. WordPress reads only its own credential from a root-owned file outside the websites. No panel password or general Webuzo API key is needed.
3. WordPress signs a short request to the existing local end-user panel on `127.0.0.1:2002`.
4. The active Webuzo plugin handles one dedicated action, validates the signature, timestamp, nonce and account identity, and selects the account from its root-only registry.
5. The existing root PHP backend runs the fixed native command:

   ```sh
   /usr/local/emps/bin/php /usr/local/webuzo/cli.php --clear_cache --users=ACCOUNT
   ```

The request cannot contain an account name, path, domain, command or uploaded file. The only operations are `status` and `purge`. The server selects the account from its own registry and checks the current Unix identity and native Webuzo creation metadata on every request.

The native operation deletes cache contents from disk; this is not an Nginx HTTP `PURGE` module. The plugin delegates that operation to Webuzo. It does not have its own cache-file scanner/deleter, persistent purge daemon, timer, sudo rule or setuid helper. A root cron synchronizes enrollment every **10 minutes**; it never purges cache. No Webuzo core or Nginx/PHP configuration is patched.

## Requirements and limits

- Tested on Webuzo **4.9.1**, Ubuntu **24.04**, native Nginx caching, panel PHP **7.4**, and WordPress PHP **8.3**.
- WordPress 6.0+, PHP 7.4+ with POSIX, hashing and a working WordPress HTTP transport; single-site WordPress only.
- The existing end-user panel must listen locally on port 2002. The active-plugin loading stage and native CLI must match the tested Webuzo behavior.
- Linux hosting accounts need separate UIDs and private primary groups. Shared groups are not enrolled.
- The hosting administrator installs the Webuzo plugin once per server. Customers do not need root or SSH; providers on other servers must install the companion plugin themselves.
- Python 3 is needed for native lifecycle and enrollment refresh. Working system cron is required; the plugin installs its own root-owned `/etc/cron.d/webuzo-clean-nginx` entry. GNU `timeout` bounds native children and the refresh job. No packages are installed automatically.

## Security boundary

Requests and responses are authenticated with HMAC-SHA256. Only a public random credential identifier and signature are sent, never the signing secret. The loopback transport uses the existing HTTP panel; it is not encrypted. Fresh nonces, expiry and signed responses protect request and response authenticity and reject replayed requests.

A stolen account credential permits connection checks and cache clears for that account from the local host. It does not grant a panel session or general hosting API access. Sites sharing a hosting account share that account's cache-clear authority. Other accounts' credentials and the root registry must remain inaccessible through host UID/group isolation.

The bridge is security-sensitive code running inside Webuzo's existing root backend; using a native command does not make that code unprivileged. The protocol provides no file-upload, arbitrary file-editing or arbitrary deletion operation. The native Webuzo command remains responsible for the filesystem cache clear. These boundaries have developer-run checks; they are not a guarantee against every possible vulnerability in the plugin or its host dependencies.

The UI confirms that the native command completed normally. It does not claim independently verified deletion of every cache entry: Webuzo can suppress internal filesystem errors, and live traffic can refill cache. Validate actual cache behavior on each deployment and after Webuzo upgrades.

## Upgrading from earlier candidates

Version 0.5.0 replaces the previous filesystem broker. Activation retires only the previous plugin's known daemon, unit and registry. WordPress uses only the provisioned account capability; it does not read or migrate old panel API settings.

## Verification

```sh
php tests/native-bridge-contract.php
php tests/native-client-contract.php
php tests/wordpress-contract.php
python3 -I -B tests/lifecycle-contract.py
```

The protocol/client/WordPress stub suites contain 29, 41 and 54 assertions; the isolated lifecycle suite adds 17. They do not clear live cache. Native deployment acceptance also exercises actual WordPress HTTP/PHP-FPM, hosting-account isolation, filesystem attacks, native plugin lifecycle, credential revocation and the ten-minute cron with disposable accounts. See the report for the precise tested scope. These are developer-run checks, not an independent penetration test.

For WordPress coding standards and PHP compatibility, run `composer install` followed by `composer lint`. Use `composer test:wordpress` for the WordPress handler and HTTP client fixtures. The minimum-version fixture uses WordPress 6.0 / PHP 7.4; the current HTTP/PHP-FPM fixture uses WordPress 7.1.4 / PHP 8.3.

The 0.5.6 HTTP acceptance also runs with `WP_DEBUG=true` on two clean installations. Private logs are checked for unexpected errors, warnings and notices, and the original WordPress debug settings are restored afterward. See the quality report for the precise procedure and results.

## Building packages

```sh
python scripts/build.py
```

The build creates both installable ZIPs and `PUBLIC-SHA256SUMS.txt` in ignored `dist/`. It uses explicit source allowlists and creates deterministic ZIPs with a checksum manifest inside each plugin folder. Local environments, deployment harnesses, credentials and infrastructure details are excluded. Build output reflects the checked-out source; use the release tag to reproduce the corresponding published packages.
