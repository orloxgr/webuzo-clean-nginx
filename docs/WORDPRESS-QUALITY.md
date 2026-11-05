# WordPress quality checks for 1.0.0

Clear Nginx for Webuzo uses namespace/package `ClearNginxForWebuzo`, text domain `clear-nginx-for-webuzo`, and prefix `clear_nginx_for_webuzo_` for actions, nonces, notices and error codes. There are no legacy namespace aliases, old action handlers, settings migration or notice migration. The bootstrap rejects duplicate loading of its own implementation.

Expected client failures return `WP_Error` and are checked with `is_wp_error()` before response data is used. Error messages remain plain text until escaped with `esc_html()` when displayed. The tests include translated markup and double-escaping checks.

Operation input must exactly match an allowed original string before `sanitize_text_field()` is applied. HTML, whitespace, control characters, missing fields and non-string input are rejected rather than normalized into an allowed action. WordPress authorization requires `manage_options`, POST and an operation-specific nonce.

Credential contents use `WP_Filesystem_Direct::get_contents()`, without changing the global filesystem object or `FS_METHOD`, selecting FTP/SSH, or requesting credentials. Numeric owner, mode, regular-file type, hard-link count, canonical parent and size checks remain. Read failures and oversized content return `WP_Error`.

## Current packages

The WordPress plugin is 1.0.0. The Webuzo companion is independently versioned as 0.5.8 and displays Clean Nginx Cache Wordpress Connector. The WordPress readme contains the purpose, requirements and installation steps, with the third-party statement at the end. The PHP suites passed 124 assertions (29 protocol, 41 client, 54 handler/UI); 26 live toolbar HTTP assertions and full static/runtime Plugin Check scans were rerun against the current WordPress package. The scans use separate package copies with all annotation comments removed. The actual Webuzo menu, page heading, version and ready state were also verified. The runtime protocol and enrollment implementation are unchanged.

## Version 0.5.7 toolbar and page changes

The primary Clear Cache toolbar item is now the purge POST form, with Settings as a separate submenu link. A WordPress referer field and safe redirect return the user to the originating page; malformed return fields are rejected before nonce processing and external referers are rejected by WordPress redirect validation. Result notices also render on other administrator pages. Hosting enrollment/backend implementation paragraphs are removed from the tools page; the introduction now reads: "Clear cached pages to help visitors see your latest changes." The handler suite has 54 assertions; the historical results below describe the 0.5.6 baseline.

The toolbar changes passed 26 live WordPress HTTP assertions: rendered primary POST control, separate Settings link, Dashboard/frontend return paths, actual account cache MISS after purge, other-account cache HIT preservation, success notice, nonce/method checks, malformed return field rejection and external-URL fallback. Both full static and runtime Plugin Check scans passed on separate copies of the 0.5.7 WordPress package with all annotation comments removed. The frontend fixture used a unique query string to reach an uncached authenticated page; this does not validate the hosting provider's general cookie/cache-bypass configuration. Earlier release assets remain unchanged; 0.5.7 includes both installable packages.

## One explicit coding-standard exception

There is **one `phpcs:ignore` line**, for `WordPress.PHP.NoSilencedErrors.Discouraged` on the native `@lstat()` metadata call. It prevents a missing/concurrently removed credential from emitting a raw filesystem-path warning; failure instead returns `WP_Error`. The WordPress Filesystem API does not expose the full numeric ownership and hard-link metadata required here.

WordPress-Core permits `@lstat()` in its native-function allowance; WordPress-Extra is stricter. We keep the full WordPress ruleset and document the single exception instead of weakening that ruleset. Running PHPCS with `--ignore-annotations` reports that **one warning**. This is not a claim of zero exceptions or guaranteed reviewer acceptance.

The previous exception-escaping, input-sanitization and direct-read annotations are removed. No Plugin Check category is excluded. Full static and runtime Plugin Check scans also pass on separate copies with **all annotation comments removed**.

## Repeatable checks

```sh
composer install
composer lint
composer test:wordpress
php tests/native-bridge-contract.php
python3 -I -B tests/lifecycle-contract.py
php vendor/bin/phpcs --standard=phpcs.xml.dist --ignore-annotations
```

The last command deliberately exposes the documented metadata warning. `phpcs.xml.dist` checks all three packaged WordPress PHP files with WordPress Coding Standards and PHPCompatibilityWP, for WordPress 6.0 / PHP 7.4 minimums. The lifecycle suite requires Linux `fcntl`. Development dependencies are not packaged.

On a disposable WordPress installation with Plugin Check:

```sh
wp plugin check clear-nginx-for-webuzo --format=json
wp --require=wp-content/plugins/plugin-check/cli.php plugin check clear-nginx-for-webuzo --format=json
```

The second command includes runtime checks. Neither excludes a check.

## Version 0.5.6 baseline verification scope

- WordPress Coding Standards 3.4.1 / PHP_CodeSniffer 3.13.6 with PHPCompatibilityWP 2.1.8: pass with the single explicit annotation above; one warning without annotations.
- Plugin Check 2.1.0: full static and runtime checks pass, including the updater check. No Update URI or custom updater is included.
- Public suites: 29 native protocol, 41 client, 47 handler/UI and 17 isolated lifecycle assertions (**134 total**).
- Actual WordPress HTTP/PHP-FPM: 35 assertions, including stale content becoming visible after native purge and the other account retaining HIT. The release run uses `WP_DEBUG=true` with private logs outside webroots, verifies those logs, and restores original configurations byte-for-byte.
- Minimum-version fixture: eight assertions on WordPress 6.0 / PHP 7.4.33. Current deployment fixtures use WordPress 7.1.4 / PHP 8.3.
- Ten actual runtime namespace/action checks across two WordPress installations confirm the new namespace/action, absent old aliases/actions and removed settings migration.
- Fifteen malformed-input HTTP smoke assertions cover strict input rejection and a valid connection request.

These are developer-run checks on the documented fixtures. They do not cover every theme/plugin/hosting configuration, replace an independent penetration test, or guarantee manual WordPress.org approval.
