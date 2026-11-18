# WordPress quality checks for 1.1.0

The WordPress plugin is independently versioned as 1.1.0 and the native
Webuzo connector as 0.6.0. Public identifiers remain `ClearNginxForWebuzo`,
`clear-nginx-for-webuzo` and `clear_nginx_for_webuzo_`. No legacy aliases,
general panel API key, updater override or destination selector is added.

## Current verification

See [the release verification report](VERIFICATION-1.1.0.md) for current
commands, environments and evidence. Historical acceptance in
[the security review](SECURITY-REVIEW.md) is not a substitute for these checks.

The PHPCS configuration now includes every PHP file in the WordPress package.
It applies the complete WordPress ruleset and PHPCompatibilityWP, with WordPress
6.0 / PHP 7.4 minimums. Normal PHPCS and full Plugin Check static/runtime scans
pass. The POT is generated using WP-CLI i18n rather than maintained by hand.

## Documented coding-standard exceptions

There are four narrowly annotated lines, without a weakened ruleset:

- Native `@lstat()` in `class-native-panel.php` prevents a missing credential
  from exposing an internal path warning. The Filesystem API does not expose
  numeric ownership, link count and the other metadata this boundary requires.
- Two conditional SQL operations in `class-state.php` implement compare-and-swap
  update/delete. The Options API cannot atomically compare the previous value.
  Both queries use prepared values and invalidate the option caches explicitly.
  State is non-autoloaded. These operations prevent old workers deleting new
  content changes or releasing replacement ownership locks.
- An insert-only SQL ownership claim avoids the upsert in `add_option()` after
  concurrent cached misses. It cannot replace an existing lock. The real
  WordPress integration suite reproduces a stale cached miss and verifies
  that the original owner remains intact.

Running PHPCS with `--ignore-annotations` reports zero errors and four warnings
(one metadata warning and three direct-database warnings). This is documented
rather than presented as an annotation-free result or a guarantee of reviewer
approval. Plugin Check is run without excluding categories.

## Repeatable checks

```sh
composer install
composer lint
composer test:wordpress
composer test:protocol
python3 -I -B tests/lifecycle-contract.py
php vendor/bin/phpcs --standard=phpcs.xml.dist --ignore-annotations
```

The lifecycle suite requires Linux `fcntl`. Dependencies, fixtures and private
VM reports are not packaged.

On a disposable single-site WordPress installation with administrator
`fixtureadmin`, and with this plugin skipped during the simulated-native suite:

```sh
wp --skip-plugins --skip-themes eval-file /absolute/path/tests/wordpress-integration.php
```

This suite uses real WordPress hooks, options, cron and database operations,
but a simulated native client. Never run it against production: it creates and
deletes fixture content and changes plugin preferences. Real native acceptance
is measured separately in the dedicated Webuzo VM.

On a disposable installation with Plugin Check:

```sh
wp plugin check clear-nginx-for-webuzo --format=json
wp --require=wp-content/plugins/plugin-check/cli.php plugin check clear-nginx-for-webuzo --format=json
```

Expected client failures remain `WP_Error`. Only authenticated native `busy`
and `rate_limited` errors include retry information consumed by automation.
Transport timeouts, invalid signatures and native failures are not retried
blindly. UI output is escaped at display; manual forms require `manage_options`,
POST and operation-specific nonces. CLI controls also require an administrator
selected with `--user` and run under the hosting Unix UID.
