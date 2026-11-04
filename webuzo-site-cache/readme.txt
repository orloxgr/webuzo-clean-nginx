=== Webuzo Site Cache ===
Tags: webuzo, nginx, cache, purge
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clear account Nginx cache through your hosting provider's local cache service.

== Description ==
By Byron Iniotakis

LAB-TESTED RELEASE CANDIDATE: native Webuzo lifecycle, root-service sandbox, real PHP-FPM and two-account isolation passed focused deployment checks. This is not an independent penetration test. The hosting administrator installs the companion Webuzo plugin once per server. This WordPress plugin cannot provide access on an unprepared server by itself.

Local mode needs no hosting API key. Linux supplies the PHP process UID to the service. All sites sharing that Unix hosting account share account-wide purge authority. The warning reads: This plugin clears the Nginx cache for all websites in this hosting account.

Clear Cache > Clear Cache and Settings appear in the toolbar. Actions require administrator capability, POST and a nonce. No purge confirmation checkbox. Preview is read-only; wait 10 seconds between scans.

Optional Webuzo API compatibility requires explicit selection and a broad account API key. No automatic fallback. Previous encrypted credentials remain stored until removed but are unused in local mode. Remove obsolete keys and revoke them separately in Webuzo.

Single-site WordPress 6.0+, PHP 7.4+ with Unix stream sockets. Local service requires Linux/systemd/Python 3, separate hosting account UIDs and the supported cache format/layout. PHP restrictions may block socket access. Manual clearing only; no automatic purge or multisite support. Large/unsupported caches can report incomplete scans. Visits refill cache.

== Installation ==
1. Hosting administrator reviews docs/INSTALLATION.md and the server bundle before privileged installation.
2. Hosting administrator uploads and activates Webuzo Clean Nginx in Webuzo Admin > Plugins. Activation enrolls existing eligible accounts with native cache directories. Deactivate/reactivate after adding accounts or enabling their cache.
3. Upload the WordPress ZIP, activate, and open Tools > Webuzo Site Cache.
4. Select Local cache service, save, and Test connection.
5. Preview, wait 10 seconds, then Clear Cache. All sites in the account are affected.

== Changelog ==
= 0.4.0 =
Companion native Webuzo plugin installs/removes only its own service through official lifecycle hooks. Real PHP-FPM, distinct hosting UIDs and focused adversarial deployment checks completed in an isolated lab. WordPress-compatible package layout and Clear Cache toolbar label.

= 0.3.0 =
Adds local UID-authenticated service, preview and explicit API compatibility. No automatic API fallback. Review candidate pending privileged deployment acceptance.

= 0.2.1 =
Byron Iniotakis author metadata, account warning and toolbar dropdown.

= 0.2.0 =
Native Webuzo API, encrypted credentials and domain/account validation.

= 0.1.0 =
Historical filesystem prototype, superseded after production permission failures.
