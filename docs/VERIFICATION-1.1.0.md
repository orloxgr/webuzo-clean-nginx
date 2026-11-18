# Verification: WordPress 1.1.0 / Webuzo connector 0.6.0

Developer-run verification on 2026-11-18, completed before release publication.
No production deployment was performed. Private lab addresses,
credentials, cookies and environment-specific harnesses remain outside this
repository and both ZIP packages.

## Feature and behavior evidence

| Requirement | Implementation | Verification |
| --- | --- | --- |
| Public posts/pages, scheduled publication, withdrawal, trash/restore/deletion | `Auto_Purge` late post and deletion hooks | Real WordPress content mutations on current and minimum supported versions |
| Revisions, autosaves and drafts ignored | Public-change filtering | Core autosave/revision insertion and draft edits leave no queued clear |
| Attachments | Insertion, updates, deletion and file/image/alternative-text metadata | Public/draft-parent and unattached-media fixtures |
| Comments | Separate opt-in, approved visibility and transitions; remember old location when moved | Approval, unapproval, edits, deletion, spam filtering and public-to-private movement |
| Rendering inputs | Menu/item updates and deletion, theme, permalinks, Customizer and block template inputs | Real menu item save/deletion, registered rendering hooks and real block-template save |
| Preferences and toolbar | Settings API with strict sanitization; defaults preserve manual-only behavior | Real Settings API HTTP forms, toolbar label/visibility and strict CLI inputs |
| Manual UI and frontend feedback | Existing authorized POST and safe redirect, escaped per-user frontend status | Real PHP-FPM forms, frontend return/result, nonce/method/installation checks |
| Site Health | Safe saved/timestamped connection, connector and automatic-work information | Real native status followed by diagnostics; cached objects remain HIT |
| WP-CLI | `webuzo-cache purge`, `status`, and `settings [name] [value]` | Real dispatcher plus portable authorization/error contracts |
| Durable queue | Generation tokens, conditional state changes and expiring worker lock | Real database tests retain concurrent newer changes and reject stale lock owners |
| Cooldown and retry limits | Shared native account cooldown; persistent WordPress retry work | Real rate rejection followed by actual WP-Cron completion; four-retry bound and failure classification fixtures |
| Native compatibility | Additive v1 response metadata; unchanged credentials and request schema | Existing 1.0.0 client against 0.6.0; new 1.1.0 client against real 0.5.9; restored 0.6.0 recognized |
| Account-wide behavior | Native registry chooses the fixed account | One site's clear invalidates two sites in that same account; concurrent calls cannot duplicate a native clear |
| Security boundary | No target/path/command inputs, signed responses, exact schema, administrator-only manual actions | Protocol/client contracts and real HTTP rejection checks; original 598 Webuzo PHP files unchanged |

## Portable and WordPress integration suites

| Suite | Assertions | Scope |
| --- | ---: | --- |
| `tests/wordpress-contract.php` | 54 | Manual authorization, method, nonce, malformed inputs, toolbar, redirect handling, escaping and duplicate-install protection |
| `tests/native-client-contract.php` | 44 | Fixed signed transport, invalid/ambiguous responses, credentials, older connector fallback and signed retry metadata |
| `tests/native-bridge-contract.php` | 33 | Fake-account protocol isolation, exact request schema, HMAC, expiry/replay inputs and shared cooldown boundaries |
| `tests/cli-contract.php` | 20 | CLI permissions, unsupported inputs, boolean validation and failure propagation |
| `tests/lifecycle-contract.py` | 17 | Actual Linux execution with fake account metadata and temporary filesystem state; unchanged enrollment lifecycle |
| `tests/wordpress-integration.php` | 71 | Real WordPress hooks, options, database compare-and-swap and WP-Cron, with a simulated native client |

The PHP contracts pass on PHP 7.4.33 and 8.4.23 locally. The WordPress
integration suite passes on local WordPress 7.1.4 / PHP 8.4.23, and in the lab
on WordPress 7.1.4 / PHP 8.3.36 and WordPress 6.0 / PHP 7.4.33. Simulated-native
tests establish application behavior, not actual Nginx deletion.

## Real Webuzo acceptance

- **54 HTTP/native checks** use two clean WordPress 7.1.4 installations inside
  the existing hosting account. They cover real administrator sessions and
  Settings API forms, actual PHP-FPM, manual and automatic native clears,
  stale HIT to fresh MISS, account-wide invalidation, frontend results,
  negative authorization requests and a real deferred WP-Cron clear.
- **10 additional native-environment checks** cover both connector compatibility directions,
  authenticated status on WordPress 6.0 / PHP 7.4.33, restoration of the current
  bridge, simultaneous requests from two installations, signed backoff,
  exclusive site-worker ownership across real concurrent WordPress processes,
  unchanged signing credentials and the original Webuzo PHP baseline.
- The lab currently has one hosting account. No second hosting account was
  created. Cross-account signature/identifier substitution is covered with
  separate fake capabilities in the protocol suite, not claimed as a new
  two-real-account deployment test.
- The lab's normal frontend uses Varnish. An isolated loopback Nginx probe
  serves the actual Apache/PHP-FPM origin and writes into the real native
  account cache directory. The shipped client calls the actual Webuzo bridge
  and native clear command. This verifies the Nginx layer without changing the
  existing frontend routing or claiming that an Nginx purge clears Varnish.

## Quality and artifact checks

Full PHPCS with WordPress Coding Standards / PHPCompatibilityWP passes for all
packaged WordPress PHP files. Without annotations it reports zero errors and
four documented warnings; see [coding-standard exceptions](WORDPRESS-QUALITY.md).
Full Plugin Check static and runtime scans pass without excluded categories.
WP-CLI i18n generates the updated POT successfully.

The builder uses explicit source lists, fixed ZIP metadata and internal SHA256
manifests. Both installable ZIPs are checked against the source and their
manifests. Development dependencies, tests, private reports and credentials are
excluded. The final delivery includes public archive checksums.

## Remaining differences and operational limits

The comparison is in [Hestia parity](HESTIA-PARITY.md). Purges remain account-wide:
neither the public Webuzo documentation nor the inspected native CLI establishes
a verified domain/URL purge operation. Do not expose a domain selector based on
guesswork. Automatic mode defaults to off and manual controls retain
`manage_options`. Deferred work needs functioning WP-Cron or later uncached
visits. Native success confirms command completion, not an independently
audited count of deleted files.
