=== Webuzo Site Cache ===
Tags: webuzo, nginx, cache, purge
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clear hosting-account Nginx cache through Webuzo's native backend.

== Description ==
By Byron Iniotakis

The hosting administrator installs the companion Webuzo Clean Nginx plugin. It provisions a separate signing credential per eligible hosting account, outside website directories. WordPress signs a request to the existing local panel; Webuzo performs its native cache clear. No separate purge daemon, panel password or general hosting API key is needed.

This plugin clears the Nginx cache for all websites in this hosting account. Clear Cache > Clear Cache and Settings appear in the toolbar. Actions require administrator capability, POST and a nonce. Wait 2 seconds between requests and 10 seconds between clears. No automatic purge, filesystem preview, confirmation checkbox or multisite support.

Single-site WordPress 6.0+, PHP 7.4+ with POSIX, HMAC hashing and TCP stream sockets. The companion panel plugin requires a supported native Webuzo backend, loopback port 2002, Python 3, working system cron, GNU timeout and separate hosting UIDs/private groups. Customers need no root or SSH after installation by their provider.

This review candidate has developer-run protocol and native deployment checks. It is not an independent penetration test. A completed native command is not an independently verified cache deletion count. Revalidate actual cache behavior after Webuzo upgrades.

== Installation ==
1. Hosting administrator reviews the source and installation/security documentation.
2. Upload and activate Webuzo Clean Nginx in Webuzo Admin > Plugins.
3. Upload and activate the WordPress ZIP.
4. Open Tools > Webuzo Site Cache and Test connection.
5. Clear Cache. Every site in the account is affected.

The companion Webuzo plugin synchronizes enrollment automatically every ten minutes. New accounts become eligible when native cache/proxy is enabled. Existing credentials and cooldowns are preserved. Busy/failed runs retry at a later scheduled cycle. Deactivate/reactivate only to rotate all credentials or upgrade. Unsupported/missing credentials fail closed; no API fallback exists.

== Upgrade Notice ==
= 0.5.1 =
Adds a root-owned enrollment cron every ten minutes. It never clears cache, has a timeout/overlap lock and is removed on deactivation.

= 0.5.0 =
Replaces the custom root daemon with Webuzo's native clear command and removes general API compatibility. An administrator visit removes old saved API settings. Revoke any previous broad account API key separately in Webuzo; deleting its saved copy does not revoke it.

== Changelog ==
= 0.5.1 =
Automatic enrollment of new and late-cache-enabled accounts, stale credential cleanup and last-sync status. Preserves existing keys and replay/cooldown state. No automatic purge.

= 0.5.0 =
Native panel bridge with separate account capabilities, signed requests/responses, replay protection, account-generation binding and bounded native execution. No custom cache-file deleter or persistent purge service.

= 0.4.0 =
Historical native plugin installation of the UID-authenticated filesystem broker.

= 0.3.0 =
Historical local broker and optional account API compatibility.
