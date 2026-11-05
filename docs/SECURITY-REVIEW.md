# Version 0.5.6 review scope

This is a developer-run review candidate, not an independent penetration test or a claim that a privileged plugin cannot contain vulnerabilities.

## Intended boundary

A compromised WordPress may request native cache clears for its hosting account. The bridge must not let it select another account, upload/write arbitrary content, delete arbitrary paths or execute arbitrary commands. Websites sharing one Unix account intentionally share this purge authority. Existing access available through their normal filesystem permissions is outside this plugin's boundary.

The privileged Webuzo panel, its service identity, native CLI, root configuration, Linux kernel and administrator are trusted. The native clear implementation is encoded by Webuzo and was observed through runtime tracing; it has not been fully source-audited. Reusing it transfers the cache operation to Webuzo, not all security responsibility.

## Controls

- Dedicated plugin action in the existing root backend; only direct loopback POSTs accepted.
- Separate 256-bit random signing secret per eligible account, outside website directories and the WordPress database. Root owns the files; only a private account primary group can read its client credential.
- HMAC-SHA256 over the exact request and response with distinct request/response contexts. The secret is never transmitted. Client responses must echo the fresh request nonce and confirm account scope, operation and backend.
- Exact canonical JSON schema: `v`, `op`, `id`, `ts`, `nonce`. Unknown/duplicate fields, invalid types and unsupported operations are rejected. A public credential ID maps to the account in a root-only registry.
- Thirty-second clock allowance, persistent replay detection, two-second request cooldown and ten-second purge cooldown per account.
- At most two native purge children globally. Native jobs are bounded by GNU timeout; the client has a total response deadline and bounded response/header parsing.
- Account UID, name, primary GID and Webuzo UUID/creation metadata are rechecked before execution. Deleted/recreated account identities invalidate the old capability.
- Fixed executable/arguments, controlled environment and working directory, no bridge shell interpolation. Only the validated registry account becomes `--users=ACCOUNT`. The native command itself may use a shell internally.
- Lifecycle operations serialize against active requests. Deactivation revokes scoped credentials; reactivation provisions fresh ones. The old custom root daemon is retired.
- A fixed root cron reconciles enrollment every ten minutes, with a 45-second timeout and nonblocking lifecycle lock. It accepts no account/path arguments, preserves existing credentials and replay state, and never executes a purge. Deactivation removes the cron; a queued job cannot recreate disabled enrollment.
- WordPress actions require administrator capability, POST and an operation-specific nonce; notices are escaped. No general account API compatibility mode remains.

## Verification

Portable suites:

| Suite | Assertions | Scope |
| --- | ---: | --- |
| Native protocol | 29 | Schema, HMAC binding, account-ID substitution, malformed/extra fields, expiry, duplicate keys and registry validation |
| WordPress native client | 41 | Fixed HTTP API destination, redirects disabled, secret not sent, response HMAC/nonce/scope validation, truncation, ambiguous headers, bounded size and timeout |
| WordPress handler/UI | 47 | Capability, method, nonce, unsupported actions, multisite, escaping, toolbar, duplicate-installation rejection, isolated notice keys, scoped styles and matching action/nonce prefixes |
| Isolated lifecycle/cron | 17 | Ten-minute schedule, new/stale enrollment, identity changes, key/nonce preservation and disabled-job behavior |

Native deployment acceptance uses an isolated Ubuntu/Webuzo environment and two real hosting accounts. It exercises account-separated credential access, signed requests through the actual panel, forged signatures, account overrides, replay/expiry, rate limits and unsupported operations. Its cache attack fixtures include symlinks to root-owned files outside cache and to the other account's cache. Outside data and the other account's cached content/inodes/owners/modes are compared after a native purge.

Actual WordPress administrator HTTP forms and PHP-FPM are also exercised: warm an anonymous native cache entry, edit real content, observe the stale HIT, submit the purge form, then verify a MISS with the changed text and an unchanged HIT for the other account. Missing/forged/cross-installation nonces and unsupported WordPress operations are rejected.

Completed native acceptance groups contain 48 endpoint/cache/credential assertions, 35 real WordPress assertions, 22 native lifecycle assertions, 15 additional identity/concurrency/runner assertions and 3 real legacy-setting migration assertions. The runner fixtures execute inside private mount namespaces: they verify rejection of a native CLI error with exit code zero, and a bounded timeout, without changing the host's Webuzo files. All 598 original Webuzo PHP files match the pre-installation baseline. Lifecycle tests confirm credential rotation/revocation, cache preservation, cron removal/restoration, harmless queued jobs and unchanged Nginx/PHP/Webuzo master processes.

Private acceptance harnesses and deployment reports stay outside the public repository. They contain environment-specific setup. Public test sources use fake capabilities and generic examples only.

Version 0.5.2 replaces the WordPress client's custom socket/HTTP parser with the WordPress HTTP API while retaining fixed loopback routing and signed account-only requests/responses. Its 35 real WordPress HTTP assertions are rerun with the new transport. Coding standards, Plugin Check results and the WordPress 6.0 / PHP 7.4 minimum-version fixture are described in [WordPress quality checks](WORDPRESS-QUALITY.md). The Webuzo bridge protocol, lifecycle code and ten-minute enrollment schedule are unchanged.

Version 0.5.3 expands the WordPress notice prefix and documents the local connection. Its 35 real HTTP assertions run with WordPress debug logging enabled on two otherwise clean installations, with zero unexpected debug entries. Separate fixtures confirm that notices belonging to another plugin or user are not rendered or removed. Debug configurations are restored after testing. These changes do not extend the account's cache-clear authority.

Version 0.5.1 additionally exercises a real system-cron enrollment of a newly created native account, late cache enablement with actual PHP-FPM connectivity, unchanged existing keys/nonce files/cache contents, overlap avoidance, root file permissions and revocation after disposable account deletion. These native enrollment checks contain 23 assertions. No Webuzo core patch is used to trigger or observe them.

## Limits

- Successful execution confirms normal native command completion, not an independently audited deletion count. The CLI may suppress underlying filesystem errors or change behavior in another version. Visits can refill cache immediately.
- Native cache clearing removes ordinary contents under the account cache root, including unrecognized files there; it does not preserve unknown files as the previous broker did. This plugin does not expose a content-write operation. Native cache poisoning risks and cache permissions are not redesigned here.
- Scoped secrets are capabilities. A leaked secret permits local status/purge requests for its account until revoked. It cannot authenticate another Webuzo API action, but protecting each account's credential remains essential.
- The local HTTP messages are authenticated, not encrypted. Any administrator/root compromise is outside this boundary. Processes with the same UID or credential-reading group share purge authority.
- The endpoint is security-sensitive root PHP code. HMAC does not prevent every denial of service or substitute for an independent audit. No arbitrary-file-write operation or general command runner is intentionally provided.
- Enrollment refresh depends on working system cron and a successful ten-minute run. Busy/failed runs can delay new accounts until a later cycle. Native account metadata/layout and active-plugin loading behavior are version-dependent; unsupported setups fail closed and require administrator review.

Version 0.5.4 renames only the WordPress public identity and adds a duplicate-installation guard. Manual migration retains the account credentials and existing native backend. The account-scoped transport and native bridge protocol remain unchanged.

Version 0.5.5 removes the WordPress Update URI header and redundant translation loader for directory submission. Native bridge and lifecycle code are unchanged; the existing 0.5.4 server companion remains compatible.

Version 0.5.6 uses namespace `ClearNginxForWebuzo` and prefix `clear_nginx_for_webuzo_`. Legacy WordPress setting migration and compatibility identifiers are removed. The current public client/handler suites contain 41 and 47 assertions; versioned deployment counts above describe earlier candidates. One explicit native metadata-check annotation remains; see the WordPress quality report.
