# Hestia feature comparison and implementation plan

## Source baseline (2026-11-18, before implementation)

Compared the actual local Clear Nginx for Webuzo 1.0.0 / connector 0.5.9
sources with Hestia Nginx Cache 2.4.5.1, the stable version listed at
<https://wordpress.org/plugins/hestia-nginx-cache/>. Upstream source inspected:

- <https://github.com/imjuniper/hestia-nginx-cache/blob/develop/hestia-nginx-cache.php>
- <https://github.com/imjuniper/hestia-nginx-cache/blob/develop/includes/admin.php>
- <https://github.com/imjuniper/hestia-nginx-cache/blob/develop/includes/site_health.php>
- <https://github.com/imjuniper/hestia-nginx-cache/blob/develop/wp-cli.php>

This is a feature comparison, not a source-code import. Implementations here
use the project's own classes, credential transport and authorization boundary.

| Feature | Hestia baseline | Webuzo before implementation | Planned adaptation |
| --- | --- | --- | --- |
| Automatic purge | Post, attachment, comment, menu, theme and permalink hooks; consolidated at shutdown | Absent | Public-change filtering, durable queue and bounded temporary-error retries |
| Automatic purge switch | Disable checkbox | Absent | Opt-in automatic purge; independent comments switch |
| Toolbar | Frontend/backend button; visibility and label settings | Frontend/backend POST button, fixed label | Keep authorized POST; add settings and frontend results |
| Settings link | Plugin list | Toolbar only | Add plugin-list link |
| Site Health | Version, configured flag, host and port | Absent | Safe configuration, cached authenticated status and queue diagnostics |
| WP-CLI | purge and API setup | Absent | purge, status and local settings; no API key setup needed |
| Purge target | Hestia API takes account and domain | Native Webuzo CLI takes registry account | Account-wide until domain support is positively verified |
| Connection | User supplies Hestia API credentials | Automatic account-scoped signed loopback transport | Preserve automatic enrollment; additive signed connector capabilities |

## Backend investigation and coordination decision

The existing runner invokes the fixed native command `cli.php --clear_cache
--users=ACCOUNT` and uses `/var/webuzo-data/nginx_proxy_cache/ACCOUNT`.
The public Webuzo reverse-proxy documentation describes user-level cache
controls (<https://webuzo.com/docs/admin/nginx-reverse-proxy/>). The CLI
reference (<https://webuzo.com/docs/developers/webuzo-cli-commands/>) does not
establish a domain-scoped cache-clear operation. No validated native
domain-targeting mechanism is available from these sources. Do not invent a
domain argument or selectively delete undocumented cache formats.

The connector already serializes every site's requests through the same
account state lock, imposes a shared 10-second purge cooldown, and caps global
native jobs at two. Keep that central authority. Persist each site's outstanding
changes in WordPress, consolidate at shutdown, and reschedule explicit temporary
rejections. A newer change must survive completion of an older purge. This
keeps retries out of the privileged lifecycle/enrollment cron (which must never
purge cache) and works with older connectors too. Multiple sites may each need
a subsequent purge; never treat an earlier purge as covering a later change.

## Completion gates

- Implement every planned adaptation above, preserving single-site scope,
  WordPress 6.0 / PHP 7.4 minimums and administrator-only manual controls.
- Verify public hooks, scheduled publication, comments, queue races, cooldown,
  permanent/temporary/ambiguous failures, settings, CLI, frontend results,
  authenticated capabilities and existing security contracts.
- Update POT, readme, upgrade instructions and reproducible package manifests.
- Record current checks separately from historical real-Webuzo acceptance.
- Build installable packages and report remaining differences. Do not publish
  or deploy to production.

## Implemented release

WordPress 1.1.0 implements all planned rows above. Content filtering uses
`wp_after_insert_post` with the previous object, so public withdrawals and the
real scheduled-publication path are included. Revisions/autosaves and draft-only
edits are ignored. Public attachment file/image/alternative-text metadata and
block templates/navigation/global styles are also covered. Only approved public
comments, or transitions into/out of approval, trigger optional comment purges.

The frontend result returns through the existing authorized POST/safe redirect,
then renders an escaped, user-specific status message. Manual controls and
preferences remain administrator-only. Automation has no current-user capability
requirement and therefore supports scheduled/programmatic changes.

The durable queue uses non-autoloaded options, a 60-second ownership lock and
atomic conditional database updates/deletes. A completed worker cannot erase a
newer generation or release a replacement lock. Explicit authenticated `busy`
and `rate_limited` results receive bounded retries; ambiguous transport/native
failures do not. A fallback cron wakeup survives interruption of the foreground
worker. Insert-only acquisition also protects against stale cached option
misses: WordPress `add_option()` can upsert an existing row. Three narrowly
documented direct-database annotations cover insert-only ownership and
compare-and-swap operations that the Options API cannot guarantee.

Connector 0.6.0 adds signed `connector_version`, `capabilities`, `purge_interval`
and temporary-rejection `retry_after` metadata to the existing v1 responses.
Requests, account lookup, native command, enrollment and lifecycle are unchanged.
New WordPress clients understand older connector rejections with a conservative
fallback. Old clients ignore the additive response fields. No coordinated
credential migration or required minimum connector upgrade is needed.

Deliberate differences from Hestia: automatic purge defaults to off because the
scope is the whole hosting account; manual actions retain `manage_options` rather
than Hestia's `edit_posts`; setup remains automatic instead of exposing Hestia
API credentials. A domain or URL purge is not advertised. Site Health describes
saved status with its timestamp rather than claiming an unperformed live test.
