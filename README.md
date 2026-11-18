# Clear Nginx for Webuzo

Clear your hosting account's Nginx cache directly from the WordPress dashboard or toolbar.

Version 1.1.0 adds optional automatic clearing for public
content, attachments, comments and rendering changes, toolbar preferences,
frontend results, Site Health information and WP-CLI commands. Automatic
clearing remains **off by default**, including on upgrades.

**Clearing cache affects all websites in the same hosting account.**

## Downloads

Download the packages from [release v1.1.0](https://github.com/orloxgr/webuzo-clean-nginx/releases/tag/v1.1.0).
The WordPress plugin is also available from [WordPress.org](https://wordpress.org/plugins/clear-nginx-for-webuzo/).

| Package | Version | Install in |
| --- | --- | --- |
| [Clear Nginx for Webuzo](https://github.com/orloxgr/webuzo-clean-nginx/releases/download/v1.1.0/clear-nginx-for-webuzo-1.1.0.zip) | 1.1.0 | Each WordPress site |
| [Clean Nginx Cache Wordpress Connector](https://github.com/orloxgr/webuzo-clean-nginx/releases/download/v1.1.0/webuzo-clean-nginx-0.6.0.zip) | 0.6.0 | Webuzo Admin, once per server |

Use these ZIPs to install the plugins. The release includes `SHA256SUMS.txt` to verify the downloads.

## Requirements

- Webuzo hosting with Nginx caching enabled for the hosting account.
- The companion Clean Nginx Cache Wordpress Connector installed and active on the same server.
- A single-site WordPress installation, WordPress 6.0 or later, and PHP 7.4 or later with POSIX enabled.
- WordPress administrator access to use the cache controls.

## Installation and use

1. The hosting administrator uploads and activates the connector ZIP in **Webuzo Admin > Plugins > Installed Plugins > Add New**.
2. Open **Clean Nginx Cache** in the Webuzo menu to check that the account is connected.
3. In WordPress, open **Plugins > Add New > Upload Plugin**, upload the WordPress ZIP and activate it.
4. Open **Tools > Clear Nginx for Webuzo** and click **Test connection**.
5. Click **Clear Cache** in the toolbar to clear cache and return to the current page. Its dropdown contains a separate **Settings** link. You can also clear cache from the tools page.
6. Optionally enable **Automatically clear account cache after public site changes**.
   Public-comment clearing has an independent switch. Deferred clears require
   working WP-Cron; explicit temporary rejections receive at most four retries.

## WP-CLI

Run as the hosting Unix account, selecting a WordPress administrator:

```sh
wp --user=ADMIN webuzo-cache status
wp --user=ADMIN webuzo-cache purge
wp --user=ADMIN webuzo-cache settings
wp --user=ADMIN webuzo-cache settings automatic_purge true
wp --user=ADMIN webuzo-cache settings purge_comments true
wp --user=ADMIN webuzo-cache settings show_toolbar false
wp --user=ADMIN webuzo-cache settings toolbar_label "Clear Cache"
```

`status` never clears cache. `purge` clears every website in the hosting account.
Do not run the native client as root: it must use its hosting account identity.

## Documentation

- [Server setup, requirements and removal](docs/INSTALLATION.md)
- [Technical security documentation](docs/SECURITY-REVIEW.md)
- [WordPress development checks](docs/WORDPRESS-QUALITY.md)
- [Hestia comparison, implementation decisions and remaining differences](docs/HESTIA-PARITY.md)
- [Current verification evidence and limits](docs/VERIFICATION-1.1.0.md)

## Changelog

- **WordPress 1.1.0:** optional automatic purges, persistent bounded retries,
  public-comment controls, toolbar preferences, frontend feedback, Site Health
  information and administrator-authorized WP-CLI commands.
- **Connector 0.6.0:** signed version/capability and retry timing metadata within
  the existing v1 protocol. Both compatibility directions are tested. Enrollment,
  signing credentials, native runner and account-wide authority are preserved.

## Building the ZIPs

```sh
python scripts/build.py
```

Use `--only wordpress` or `--only webuzo` to build a single plugin. Packages are written to `dist/` with separate version numbers.

This project is an independent third-party integration. It is not affiliated with, endorsed, sponsored, or supported by Webuzo or Softaculous. The Webuzo name is used only to describe compatibility with the hosting platform.
