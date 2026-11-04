=== Webuzo Site Cache ===
Tags: webuzo, nginx, cache, purge
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clear account Nginx cache through your hosting provider's local cache service.

== Description ==
By Byron Iniotakis

REVIEW CANDIDATE: root-service deployment and real PHP-FPM acceptance remain pending. The hosting administrator must review and install the separate Unix socket service once per server and enroll accounts. This WordPress plugin cannot provide access on an unprepared server by itself.

Local mode needs no hosting API key. Linux supplies the PHP process UID to the service. All sites sharing that Unix hosting account share account-wide purge authority. The warning reads: This plugin clears the Nginx cache for all websites in this hosting account.

Nginx cache > Clear Cache and Settings appear in the toolbar. Actions require administrator capability, POST and a nonce. No purge confirmation checkbox. Preview is read-only; wait 10 seconds between scans.

Optional Webuzo API compatibility requires explicit selection and a broad account API key. No automatic fallback. Previous encrypted credentials remain stored until removed but are unused in local mode. Remove obsolete keys and revoke them separately in Webuzo.

Single-site WordPress 6.0+, PHP 7.4+ with Unix stream sockets. Local service requires Linux/systemd/Python 3, separate hosting account UIDs and the supported cache format/layout. PHP restrictions may block socket access. Manual clearing only; no automatic purge or multisite support. Large/unsupported caches can report incomplete scans. Visits refill cache.

== Installation ==
1. Hosting administrator reviews docs/INSTALLATION.md and the server bundle before privileged installation.
2. Hosting administrator installs the service and enrolls the account.
3. Upload the WordPress ZIP, activate, and open Tools > Webuzo Site Cache.
4. Select Local cache service, save, and Test connection.
5. Preview, wait 10 seconds, then Clear Cache. All sites in the account are affected.

== Changelog ==
= 0.3.0 =
Adds local UID-authenticated service, preview and explicit API compatibility. No automatic API fallback. Review candidate pending privileged deployment acceptance.

= 0.2.1 =
Byron Iniotakis author metadata, account warning and toolbar dropdown.

= 0.2.0 =
Native Webuzo API, encrypted credentials and domain/account validation.

= 0.1.0 =
Historical filesystem prototype, superseded after production permission failures.
