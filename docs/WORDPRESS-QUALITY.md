# WordPress quality checks for 0.5.3

The WordPress companion follows the WordPress capability, nonce, validation and late-escaping patterns. Its UI and errors use the `webuzo-site-cache` text domain, with a bundled POT template. Toolbar CSS is enqueued only for an authorized visible toolbar; the button has a keyboard focus indicator. The transport uses the WordPress HTTP API with a fixed loopback URL, no redirects, no cookies, a twenty-second timeout and a bounded response. Exact response bytes remain HMAC-verified before accepting account scope or native completion.

## Repeatable development checks

From a source checkout, install development dependencies and run:

```sh
composer install
composer lint
composer test:wordpress
php tests/native-bridge-contract.php
python3 -I -B tests/lifecycle-contract.py
```

`phpcs.xml.dist` checks all three packaged WordPress PHP files with WordPress Coding Standards and PHPCompatibilityWP, using WordPress 6.0 and PHP 7.4 as the minimums. Development dependencies are not included in either plugin ZIP.

For Plugin Check, use a disposable WordPress installation with Plugin Check installed:

```sh
wp plugin check webuzo-site-cache --format=json
wp plugin check webuzo-site-cache --exclude-checks=plugin_updater --format=json
wp --require=wp-content/plugins/plugin-check/cli.php plugin check webuzo-site-cache --exclude-checks=plugin_updater --format=json
```

The third command includes Plugin Check's runtime checks. Run the full first command too; the exclusion is not a claim of unrestricted WordPress.org directory acceptance.

## Results and deliberate exceptions

Temporary notices use `webuzo_site_cache_notice_` plus the current user ID. Notices with the previous short prefix are not read or migrated; they expire after their original two-minute lifetime. Regression fixtures verify that other-plugin and other-user notice keys are neither displayed nor removed. The WordPress readme documents the fixed local endpoint, dependency, signed request fields and standard WordPress HTTP User-Agent.


- WordPress Coding Standards 3.4.1 / PHP_CodeSniffer 3.13.6: zero errors and warnings, with PHPCompatibilityWP 2.1.8.
- Plugin Check 2.1.0: the full scan reports the single directory-specific `plugin_updater_detected` finding because of `Update URI`. Static and runtime scans excluding only `plugin_updater` pass. This plugin is distributed on GitHub; the header protects it from unrelated WordPress.org updates. A future WordPress.org submission needs a separate distribution decision and review.
- Explicit local translation loading is retained for GitHub distribution, on `init`. Its directory-hosting-only discouraged-function notice is documented inline.
- Two credential reads use narrowly annotated PHP error suppression to handle concurrent provider enrollment/removal without exposing filesystem warnings. The path is fixed and root-owned. Direct local reading preserves Unix account permissions and does not request FTP credentials or use a remote URL.
- Exception messages are plain text and escaped when the tools page renders the notice. The corresponding exception-escaping sniff exceptions are documented at each throw; output escaping remains enabled.
- Operation input is type-checked and matched against an exact safelist before use. Its narrowly annotated input-sanitization notice is covered by malformed/unknown-operation tests; no request data becomes a URL, path or command.
- Portable suites: 29 native protocol, 28 HTTP API client, 32 WordPress authorization/UI and 17 isolated lifecycle assertions (106 total).
- Actual WordPress administrator HTTP/PHP-FPM acceptance: 35 assertions, including stale anonymous cache becoming MISS with edited content, the other account retaining HIT, and rejected unauthorized/forged/unsupported actions.
- Version 0.5.3 reruns those 35 HTTP assertions on two clean WordPress installations with only the companion plugin active and `WP_DEBUG=true`. Error display is disabled and each account writes a private debug log outside its webroot. A logging probe verifies that each log works; both logs contain zero unexpected entries after the HTTP acceptance and subsequent Plugin Check runs. A real stored-transient fixture also confirms foreign notice preservation. Original WordPress configurations are backed up and restored byte-for-byte, and the original Plugin Check activation state is restored.
- Minimum-version fixture: WordPress 6.0 with PHP 7.4.33, exercising activation, signed status/purge, tools-page forms and escaping of translated output. The current deployment fixture uses WordPress 7.1.4 with PHP 8.3.

The CLI compatibility fixture does not claim a complete browser/theme/plugin matrix for WordPress 6.0. Automated checks and developer-run deployment checks do not replace an independent security review, accessibility audit or WordPress.org manual review.
