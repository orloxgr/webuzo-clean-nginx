=== Clear Nginx for Webuzo ===
Contributors: orloxgr
Tested up to: 7.1
Tags: webuzo, nginx, cache, purge
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.5.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clear hosting-account Nginx cache through Webuzo's native backend.

== Description ==
By Byron Iniotakis

This is an independent third-party integration. It is not affiliated with, endorsed, sponsored, or supported by Webuzo or Softaculous. The Webuzo name is used only to describe compatibility with the hosting platform.

The hosting administrator installs the companion Webuzo Clean Nginx plugin. It provisions a separate signing credential per eligible hosting account, outside website directories. WordPress signs a request to the existing local panel; Webuzo performs its native cache clear. No separate purge daemon, panel password or general hosting API key is needed.

This plugin clears the Nginx cache for all websites in this hosting account. Click Clear Cache directly in the toolbar to purge and return to the current page; Settings is a separate dropdown link. Actions require administrator capability, POST and a nonce. Wait 2 seconds between requests and 10 seconds between clears. No automatic purge, filesystem preview, confirmation checkbox or multisite support.

Single-site WordPress 6.0+, PHP 7.4+ with POSIX, HMAC hashing and a working WordPress HTTP transport. The companion panel plugin requires a supported native Webuzo backend, loopback port 2002, Python 3, working system cron, GNU timeout and separate hosting UIDs/private groups. Customers need no root or SSH after installation by their provider.

This review candidate has developer-run protocol and native deployment checks. It is not an independent penetration test. A completed native command is not an independently verified cache deletion count. Revalidate actual cache behavior after Webuzo upgrades.

== Local Webuzo connection and data ==
This plugin requires the hosting provider to install and activate the companion Webuzo Clean Nginx plugin on the same server. It does not create a Webuzo panel account or require a panel login, panel password or general API key.

Clicking Test connection or Clear Cache sends a signed POST request to http://127.0.0.1:2002/index.php?act=webuzo-clean-nginx-bridge on that same server. The destination is fixed and redirects are disabled. These requests check enrollment or clear the Nginx cache of the entire hosting account; publishing a post does not send an automatic purge.

The JSON body contains the protocol version, requested operation, random account credential identifier, timestamp and a fresh random nonce. The request header carries an HMAC signature. The account signing secret is read from a root-owned file outside website directories and is never sent. WordPress may include its standard HTTP User-Agent with the WordPress version and site URL. No post content, visitor information or WordPress login credentials are included by this plugin.

This connection remains on the hosting server. The plugin does not load remote scripts, styles or images, or send analytics to the plugin author. The provider provisions credentials automatically; WordPress administrators do not need to edit plugin files or paste credentials. This service integration is self-hosted; it is not a subscription to a service operated by the plugin author. See the companion source and installation requirements at https://github.com/orloxgr/webuzo-clean-nginx.

== Installation ==
1. Hosting administrator reviews the source and installation/security documentation.
2. Upload and activate Webuzo Clean Nginx in Webuzo Admin > Plugins.
3. Upload and activate the WordPress ZIP.
4. Open Tools > Clear Nginx for Webuzo and Test connection.
5. Clear Cache. Every site in the account is affected.

The companion Webuzo plugin synchronizes enrollment automatically every ten minutes. New accounts become eligible when native cache/proxy is enabled. Existing credentials and cooldowns are preserved. Busy/failed runs retry at a later scheduled cycle. Deactivate/reactivate only to rotate all credentials or upgrade. Unsupported/missing credentials fail closed; no API fallback exists.

== Upgrade Notice ==
= 0.5.7 =
Clear Cache in the toolbar now purges directly and returns to the current page. Settings remains a separate dropdown link. Simplifies the tools-page introduction. The native cache-clear protocol is unchanged.

= 0.5.6 =
Uses the ClearNginxForWebuzo namespace and matching identifiers, WP_Error and the WordPress Filesystem API. Deactivate earlier development copies before installation; old action and namespace aliases are not provided.

= 0.5.5 =
Removes the Update URI header for WordPress.org submission and relies on WordPress automatic translation loading. The native account cache-clear protocol is unchanged; the existing 0.5.4 server companion remains compatible.

= 0.5.4 =
Aligns the public display name, plugin folder and text domain with Clear Nginx for Webuzo.

= 0.5.3 =
Uses a full plugin-specific prefix for temporary notices and documents the local Webuzo connection and request data.

= 0.5.2 =
Uses the WordPress HTTP API with no redirects, bounded responses and signed account-only requests. Adds translation support, scoped toolbar styles and update identity.

= 0.5.1 =
Adds a root-owned enrollment cron every ten minutes. It never clears cache, has a timeout/overlap lock and is removed on deactivation.

= 0.5.0 =
Uses Webuzo's native clear command and account-scoped capabilities instead of a custom purge daemon or general panel API credentials.

== Changelog ==
= 0.5.7 =
Makes the main toolbar control a nonce-protected purge POST, returns safely to the originating page and shows results on administrator pages. Keeps Settings separate, simplifies the tools page and removes hosting enrollment/backend details from that page.

= 0.5.6 =
Aligns namespace, package, actions, nonces, notices and error codes with the plugin name. Removes obsolete settings migration, uses WP_Error and WP_Filesystem_Direct, and sanitizes validated operation input. Retains one documented native metadata-check annotation.

= 0.5.5 =
Removes the non-directory update identity and redundant explicit translation loader. Uses the standard WordPress update and translation mechanisms.

= 0.5.4 =
New display name, matching slug/text domain/package and duplicate-installation guard. Existing account credentials and notice settings are preserved.

= 0.5.3 =
Unique notice keys, explicit local-connection documentation and WordPress debug-mode verification. Existing notices expire within two minutes.

= 0.5.2 =
WordPress standards cleanup, translatable UI/errors, translation template, HTTP API transport, accessible toolbar focus styling, metadata and automated quality checks.

= 0.5.1 =
Automatic enrollment of new and late-cache-enabled accounts, stale credential cleanup and last-sync status. Preserves existing keys and replay/cooldown state. No automatic purge.

= 0.5.0 =
Native panel bridge with separate account capabilities, signed requests/responses, replay protection, account-generation binding and bounded native execution. No custom cache-file deleter or persistent purge service.

= 0.4.0 =
Historical native plugin installation of the UID-authenticated filesystem broker.

= 0.3.0 =
Historical local broker and optional account API compatibility.
