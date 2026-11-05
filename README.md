# Clear Nginx for Webuzo

Clear your hosting account's Nginx cache directly from the WordPress dashboard or toolbar.

**Clearing cache affects all websites in the same hosting account.**

## Downloads

Download both plugins from [release v1.0.0](https://github.com/orloxgr/webuzo-clean-nginx/releases/tag/v1.0.0).

| Package | Version | Install in |
| --- | --- | --- |
| [Clear Nginx for Webuzo](https://github.com/orloxgr/webuzo-clean-nginx/releases/download/v1.0.0/clear-nginx-for-webuzo-1.0.0.zip) | 1.0.0 | Each WordPress site |
| [Clean Nginx Cache Wordpress Connector](https://github.com/orloxgr/webuzo-clean-nginx/releases/download/v1.0.0/webuzo-clean-nginx-0.5.8.zip) | 0.5.8 | Webuzo Admin, once per server |

Use these ZIPs to install the plugins. The release includes `SHA256SUMS.txt` to verify the downloads.

## Requirements

- Webuzo hosting with Nginx caching enabled for the hosting account.
- The companion Clean Nginx Cache Wordpress Connector installed and active on the same server.
- A single-site WordPress installation, WordPress 6.0 or later, and PHP 7.4 or later with POSIX enabled.
- WordPress administrator access to use the cache controls.

## Installation and use

1. The hosting administrator uploads and activates the connector ZIP in **Webuzo Admin > Plugins > Installed Plugins > Add New**.
2. Open **Clean Nginx Cache Wordpress Connector** in Webuzo to check that the account is connected.
3. In WordPress, open **Plugins > Add New > Upload Plugin**, upload the WordPress ZIP and activate it.
4. Open **Tools > Clear Nginx for Webuzo** and click **Test connection**.
5. Click **Clear Cache** in the toolbar to clear cache and return to the current page. Its dropdown contains a separate **Settings** link. You can also clear cache from the tools page.

## Documentation

- [Server setup, requirements and removal](docs/INSTALLATION.md)
- [Technical security documentation](docs/SECURITY-REVIEW.md)
- [WordPress development checks](docs/WORDPRESS-QUALITY.md)

## Building the ZIPs

```sh
python scripts/build.py
```

Use `--only wordpress` or `--only webuzo` to build a single plugin. Packages are written to `dist/` with separate version numbers.

This project is an independent third-party integration. It is not affiliated with, endorsed, sponsored, or supported by Webuzo or Softaculous. The Webuzo name is used only to describe compatibility with the hosting platform.
