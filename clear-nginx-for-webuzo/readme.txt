=== Clear Nginx for Webuzo ===
Contributors: orloxgr
Tested up to: 7.1
Tags: webuzo, nginx, cache, purge
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clear Nginx cache manually or automatically after public site changes on Webuzo hosting.

== Description ==
Clear Nginx for Webuzo adds a Clear Cache button to the WordPress toolbar so you can clear cached pages after updating your website.

Click Clear Cache to clear the cache and return to the page you were viewing. Open Settings from its dropdown to access the plugin's tools page.

Clearing cache affects all websites in the same hosting account.

Enable automatic clearing in Tools > Clear Nginx for Webuzo to clear cache after public posts, pages, attachments, menus, themes and permalink changes. Comment changes have their own optional switch. Automatic clearing is off by default, including after an upgrade.

Multiple changes in one request are grouped. Pending changes survive the account cooldown and are retried using WP-Cron. Only confirmed temporary busy/rate-limit rejections are retried, up to four times. Other failures appear to administrators. Deferred work requires functioning WP-Cron.

You can hide the toolbar button, customize its text, view saved diagnostics under Tools > Site Health > Info, and use WP-CLI:

* `wp --user=ADMIN webuzo-cache purge`
* `wp --user=ADMIN webuzo-cache status`
* `wp --user=ADMIN webuzo-cache settings`
* `wp --user=ADMIN webuzo-cache settings automatic_purge true`

Run WP-CLI as the hosting Unix account and select a WordPress administrator with `--user`. Status only checks the connection. No manual API key setup is needed.

This project is an independent third-party integration. It is not affiliated with, endorsed, sponsored, or supported by Webuzo or Softaculous. The Webuzo name is used only to describe compatibility with the hosting platform.

== Installation ==
Requires a single-site WordPress installation, WordPress 6.0 or later, and PHP 7.4 or later with the POSIX extension enabled.

1. Your hosting provider enables Nginx caching for your hosting account and installs and activates the companion Clean Nginx Cache Wordpress Connector plugin on the same server. The companion plugin is available from https://github.com/orloxgr/webuzo-clean-nginx/releases.
2. In WordPress, open Plugins > Add New > Upload Plugin. Upload the Clear Nginx for Webuzo ZIP and activate it.
3. Sign in as a WordPress administrator, open Tools > Clear Nginx for Webuzo and click Test connection.
4. Click Clear Cache in the toolbar or use the clear-cache button on the tools page.
5. Optionally enable automatic clearing and public-comment clearing in Preferences. Every clear still affects all websites in your hosting account.

== Frequently Asked Questions ==

= Do I need to upgrade the Webuzo connector? =
Connector 0.6.0 adds signed version/capability information and retry timing. The WordPress plugin remains compatible with the existing 0.5.9 connector; older WordPress clients also work with connector 0.6.0. There is no protocol or credential migration. Updating the connector first is recommended.

= Can I clear only one domain or URL? =
This release clears the whole hosting account. A domain-scoped Webuzo operation has not been verified.

= Why is automatic clearing disabled by default? =
It affects every website in the hosting account. Enable it explicitly when that behavior is appropriate for your hosting setup.

= What happens if a request times out? =
Completion is unknown. The plugin reports the failure without automatically repeating that operation. Check the panel and connection before a manual retry.

== Changelog ==

= 1.1.0 =
* Add optional automatic clearing for public content and rendering changes, with a separate comments switch.
* Consolidate events, retain pending changes through cooldowns and retry only confirmed temporary rejections.
* Add toolbar preferences, frontend result messages and a plugin-list Settings link.
* Add safe Site Health information and WP-CLI purge, status and settings commands.
* Support the existing connector and the additive metadata in connector 0.6.0.

= 1.0.0 =
* Initial WordPress.org release with account-wide manual clearing and automatic native connection.

== Upgrade Notice ==

= 1.1.0 =
Automatic clearing is optional and remains disabled after upgrade. All cache clears affect every website in the hosting account. No API keys or credential migration are required.
