<?php
/**
 * Plugin Name: Webuzo Site Cache
 * Description: Clear account Nginx cache through a local UID-scoped service, with optional Webuzo API compatibility.
 * Version: 0.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Byron Iniotakis
 * License: GPL-2.0-or-later
 * Text Domain: webuzo-site-cache
 */
namespace WebuzoSiteCache;
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-webuzo-api.php';
require_once __DIR__ . '/includes/class-local-service.php';

final class Plugin {
    const OPTION = 'webuzo_site_cache_api';
    const SLUG = 'webuzo-site-cache';

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_bar_menu', [__CLASS__, 'bar'], 100);
        add_action('admin_post_webuzo_site_cache', [__CLASS__, 'handle']);
    }

    public static function menu() {
        add_management_page('Webuzo Site Cache', 'Webuzo Site Cache', 'manage_options', self::SLUG, [__CLASS__, 'page']);
    }

    public static function bar($bar) {
        if (current_user_can('manage_options') && !is_multisite()) {
            $bar->add_node(['id' => self::SLUG, 'title' => 'Nginx cache', 'href' => admin_url('tools.php?page=' . self::SLUG)]);
            $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">'
                . '<input type="hidden" name="action" value="webuzo_site_cache">'
                . '<input type="hidden" name="operation" value="purge">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('webuzo_site_cache_purge')) . '">'
                . '<button type="submit" title="Clears Nginx cache for all websites in this hosting account." style="display:block;width:100%;border:0;background:transparent;color:inherit;font:inherit;line-height:inherit;text-align:left;cursor:pointer;padding:0">Clear Cache</button></form>';
            $bar->add_node(['id' => self::SLUG . '-purge', 'parent' => self::SLUG, 'title' => $form]);
            $bar->add_node(['id' => self::SLUG . '-settings', 'parent' => self::SLUG, 'title' => 'Settings', 'href' => admin_url('tools.php?page=' . self::SLUG)]);
        }
    }

    public static function client() {
        $s = get_option(self::OPTION, []);
        if (self::mode() === 'local') { return new LocalService(); }
        return new WebuzoApi(isset($s['panel']) ? $s['panel'] : '', isset($s['user']) ? $s['user'] : '',
            Secret::decrypt(isset($s['key']) ? $s['key'] : ''));
    }

    public static function mode() {
        $s = get_option(self::OPTION, []);
        return isset($s['transport']) && $s['transport'] === 'api' ? 'api' : 'local';
    }

    private static function start_form($operation) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="webuzo_site_cache"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        wp_nonce_field('webuzo_site_cache_' . $operation);
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>Webuzo Site Cache</h1>';
        if (is_multisite()) { echo '<p>This release supports single-site WordPress only.</p></div>'; return; }
        $notice = get_transient('wsc_notice_' . get_current_user_id());
        if (is_array($notice) && isset($notice['type'], $notice['text'])) {
            delete_transient('wsc_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['type']) . '"><p>' . esc_html($notice['text']) . '</p></div>';
        }
        echo '<p>Use the hosting provider’s local cache service to clear cache without giving WordPress a hosting API key. The provider installs the service once per server and enrolls each hosting account.</p>';
        echo '<div class="notice notice-warning inline"><p><strong>This plugin clears the Nginx cache for all websites in this hosting account.</strong></p></div>';
        echo '<h2>Connection</h2>';
        $s = get_option(self::OPTION, []);
        self::start_form('settings');
        echo '<p><label for="wsc-transport">Connection method</label> <select id="wsc-transport" name="transport"><option value="local" ' . (self::mode() === 'local' ? 'selected' : '') . '>Local cache service (recommended)</option><option value="api" ' . (self::mode() === 'api' ? 'selected' : '') . '>Webuzo account API (compatibility)</option></select></p>';
        echo '<p>The local service determines the account from the PHP process. No username, API key or server URL is sent. It never falls back to the API automatically.</p>';
        echo '<details' . (self::mode() === 'api' ? ' open' : '') . '><summary>Optional Webuzo API connection</summary><p>Only used when Webuzo account API is selected above. An unrestricted account key can authorize actions beyond cache clearing. Create a dedicated key, restrict its IP, and revoke it in Webuzo when no longer needed. Previously saved keys remain stored until removed; local mode does not read or send them.</p>';
        echo '<table class="form-table"><tr><th><label for="wsc-panel">End-user panel URL</label></th><td><input class="regular-text" id="wsc-panel" name="panel" type="url" placeholder="https://panel.example.com:2003" value="' . esc_attr(isset($s['panel']) ? $s['panel'] : '') . '"><p class="description">Use the hostname covered by the panel’s HTTPS certificate and its actual end-user port. Do not paste a /sess…/ URL.</p></td></tr>';
        echo '<tr><th><label for="wsc-user">Hosting account username</label></th><td><input id="wsc-user" name="apiuser" type="text" autocomplete="off" value="' . esc_attr(isset($s['user']) ? $s['user'] : '') . '"></td></tr>';
        echo '<tr><th><label for="wsc-key">Account API key</label></th><td><input class="regular-text" id="wsc-key" name="apikey" type="password" autocomplete="new-password" value="" placeholder="' . esc_attr(empty($s['key']) ? 'Enter an account API key' : 'Saved; leave blank to keep') . '"><p class="description">The saved key is encrypted using your WordPress authentication salt and is never displayed here. Changing those salts requires re-entering the key.</p><label><input type="checkbox" name="forget_key" value="1"> Remove the saved API key from WordPress</label></td></tr></table>';
        echo '</details>';
        submit_button('Save connection');
        echo '</form><h2>Check connection</h2><p>Checks which hosting account will be used. This action does not clear cache.</p>';
        self::start_form('connection');
        submit_button('Test connection', 'secondary');
        echo '</form>';
        if (self::mode() === 'local') {
            self::start_form('preview');
            submit_button('Preview account cache', 'secondary');
            echo '</form><p>Wait 10 seconds between cache previews or purges.</p>';
        }
        echo '<h2>Clear account cache</h2>';
        self::start_form('purge');
        submit_button('Clear Nginx cache for this account', 'primary');
        echo '</form><p>Version 0.3.0 · Manual account-wide clearing. No automatic purge or change to whether caching is enabled.</p></div>';
    }

    private static function posted($name) {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? trim(wp_unslash($_POST[$name])) : '';
    }

    public static function handle() {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage this site’s cache.', '', ['response' => 403]); }
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') { wp_die('Use the cache management form.', '', ['response' => 405]); }
        $operation = sanitize_key(self::posted('operation'));
        if (!in_array($operation, ['settings', 'connection', 'preview', 'purge'], true)) { wp_die('Unknown operation.', '', ['response' => 400]); }
        check_admin_referer('webuzo_site_cache_' . $operation);
        try {
            if (is_multisite()) { throw new \RuntimeException('Multisite is not supported by this release.'); }
            if ($operation === 'settings') {
                $current = get_option(self::OPTION, []);
                $mode = self::posted('transport');
                if (!in_array($mode, ['local', 'api'], true)) { throw new \RuntimeException('Select a supported connection method.'); }
                if ($mode === 'local') {
                    $current['transport'] = 'local';
                    if (self::posted('forget_key') === '1') { $current['key'] = ''; }
                    update_option(self::OPTION, $current, false);
                    $text = 'Local cache service selected. No API credentials will be sent. Use Test connection to verify account enrollment.';
                } else {
                    $panel = WebuzoApi::normalize_panel(self::posted('panel'));
                    $user = self::posted('apiuser');
                    $newKey = self::posted('apikey');
                    $stored = isset($current['key']) ? $current['key'] : '';
                    new WebuzoApi($panel, $user, 'validation-placeholder');
                    if (self::posted('forget_key') === '1') {
                        $stored = '';
                    } elseif ($newKey !== '') {
                        new WebuzoApi($panel, $user, $newKey);
                        $stored = Secret::encrypt($newKey);
                    } elseif ($stored && (empty($current['panel']) || $current['panel'] !== $panel || empty($current['user']) || $current['user'] !== $user)) {
                        throw new \RuntimeException('Re-enter the API key when changing the panel URL or account. A saved key is never sent to a newly configured destination.');
                    }
                    update_option(self::OPTION, ['transport' => 'api', 'panel' => $panel, 'user' => $user, 'key' => $stored], false);
                    $text = $stored ? 'Connection saved. Use Test connection before clearing cache.' : 'Connection saved without an API key. Add an account API key to connect.';
                }
            } elseif ($operation === 'connection') {
                $r = self::client()->check_site(home_url('/'));
                $text = self::mode() === 'local'
                    ? sprintf('Connected to the local cache service for hosting account %s. Connection test only; no cache was cleared.', $r['account'])
                    : sprintf('Connected to Webuzo account %s. This site belongs to the account. Listed domains: %d. Connection test only; no cache was cleared.', $r['account'], $r['domain_count']);
            } elseif ($operation === 'preview') {
                if (self::mode() !== 'local') { throw new \RuntimeException('Preview is available with the local service only.'); }
                $r = self::client()->preview();
                $text = self::local_notice($r, true);
            } else {
                $r = self::client()->purge(home_url('/'));
                $text = self::mode() === 'local' ? self::local_notice($r, false)
                    : sprintf('Webuzo confirmed that Nginx cache was cleared for account %s. This affects every site in that account. New visits will rebuild cache.', $r['account']);
            }
            $type = isset($r['result']) && (!$r['result']['complete'] || $r['result']['unknown'] || !$r['result']['scanned']) ? 'warning' : 'success';
        } catch (\Throwable $error) { $text = $error->getMessage(); $type = 'error'; }
        set_transient('wsc_notice_' . get_current_user_id(), ['type' => $type, 'text' => $text], 120);
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit;
    }

    public static function local_notice(array $response, $preview) {
        $r = $response['result'];
        $text = sprintf('%s Account: %s. Scanned: %d. Recognized cache files: %d. Deleted: %d. Skipped: %d. Errors: %d.',
            $preview ? 'Preview only; nothing deleted.' : 'Account cache scan finished.', $response['account'],
            $r['scanned'], $r['matched'], $r['deleted'], $r['unknown'], $r['errors']);
        if (!$r['complete']) { $text .= ' The scan is incomplete; this is not a full cache-clear confirmation. Large caches may need administrator review.'; }
        if (!$r['scanned']) { $text .= ' No cache files were inspected. This does not verify whether Nginx caching is enabled.'; }
        return $text;
    }
}
Plugin::boot();
