# Focused security and deployment review

Date: 2026-11-05. Plugins: 0.4.0; broker: 0.2.0.

These are developer-run checks in an isolated VMware lab, not an independent penetration test, certification or guarantee of absolute security. No production server was used. Deployment credentials, domains, addresses, account names, snapshots and private harnesses are excluded from this repository and packages.

## Environment and evidence

Ubuntu 24.04 LTS, Webuzo 4.9.1, WordPress 7.1.4, PHP 8.3, native Nginx/Apache reverse proxy, two actual hosting accounts with different kernel UIDs. The native Webuzo upload and activation/deactivation interface installed and removed the service. WordPress admin requests ran through the actual PHP-FPM pools and used the production root-owned socket validation, not a substituted socket or mocked UID.

| Check group | Result and scope |
| --- | --- |
| Portable contracts | 39 WordPress/API, 8 local-client, 20 Linux broker and 18 isolated real Nginx/PHP assertions passed. Contract network calls are stubbed; isolated integration has one connecting UID. |
| Native WordPress HTTP acceptance | 37 assertions passed, including genuine admin login, both FPM identities, scope warning, stale content after an edit, purge → MISS with the edited content, cache rebuild and an unchanged HIT in the second account. Repeated page/login assertions are included in that count. |
| Live service adversarial tests | 38 assertions passed: root/system/unenrolled rejection; two actual allowed UIDs; 18 malformed, duplicate, oversized or override payloads; preview/cooldown; hardlinks, symlinks, FIFO, unknown files; a protected file outside cache; byte comparisons for the other account; a slow drip connection; concurrent connection burst; recovery and service liveness. |
| Additional access checks | 10 assertions passed: real subscriber settings/purge rejection; no subscriber toolbar; real administrator dropdown; ignored account/path/command injection in a legitimate purge; second-account preservation; no native admin status for unauthenticated/end-user callers; sessionless native lifecycle URL rejected. |
| Native lifecycle | 19 assertions passed: panel removal/reactivation, socket/unit/code/registry removal, WordPress fail-closed behavior without API fallback, cache preservation, unchanged Nginx/PHP/Webuzo main PIDs, two-account enrollment, denied tenant lifecycle execution and all 598 recorded original Webuzo PHP files unchanged. |
| Final deployment health | 7 assertions passed after a VM reboot: both actual PHP-FPM connections/frontends, broker boot enablement, all 21 installed plugin/broker files matching final package/source hashes and all 598 original Webuzo PHP hashes unchanged. |

Host-specific harnesses and detailed output are retained privately. Counts describe test assertions and must not be interpreted as a count of discovered or eliminated vulnerability classes.

## Fixes identified during acceptance

- Native Webuzo has a privileged system-owned cache ancestor and a system-group-writable panel ancestor. The first strict root-only check refused installation. Explicit, narrowly scoped trust for those native service identities was added; no Webuzo ownership or permission changes were made. The root socket, registry and installed broker remain outside tenant write access.
- A checksum manifest at the ZIP root prevented WordPress recognizing its plugin directory. Plugin manifests now live inside their single top-level plugin folder; native upload and WordPress installation both succeeded.
- Lifecycle operations run synchronously through the native root helper and serialize on a root-owned lock. Deactivation is safe after a missing/failed first installation. Installation failures are visible through the plugin status and private log rather than a false success claim.

## Boundaries and remaining coverage

An enrolled compromised PHP process can purge its entire Unix hosting account within rate limits. The service does not isolate sites sharing a UID. It accepts no caller-selected account, path or executable, and has no upload/delete-account operation or TCP endpoint. This does not restrict unrelated filesystem or panel access that the compromised process already has.

Root, the privileged Webuzo panel/system identity, Python/systemd and the host kernel remain trusted. The native plugin inherits Webuzo's administrator/session controls. A sessionless lifecycle request was rejected in this lab; this does not constitute an audit of Webuzo's own authentication or plugin manager.

The service runs as root with a restricted unit, not as an unprivileged process. `ProtectSystem=strict`, `ProtectHome=yes`, the filesystem write exceptions, AF_UNIX-only sockets, capability bounds and resource settings were verified on the running unit. Supported cache parsing is limited to the documented Nginx v5 x86-64 layout. Large/unsupported caches may report incomplete scans; live Nginx requests can rebuild entries while a purge runs.

This review does not establish compatibility with every distribution, architecture, PHP restriction, cache layout, Webuzo release or account lifecycle. It is not exhaustive fuzzing, a sustained distributed/many-UID denial-of-service study, or a comprehensive race/kernel/filesystem audit. Enrollment is a snapshot; refresh after account changes. Reuse of the exact same UID/name/GID is indistinguishable from the original identity. Each deployment needs its own two-account acceptance checks and backup/recovery plan.
