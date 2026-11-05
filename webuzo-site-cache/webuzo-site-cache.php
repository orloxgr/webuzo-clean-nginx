<?php
/**
 * Plugin Name: Webuzo Site Cache
 * Description: Clear account Nginx cache through Webuzo's native backend with a purge-only account credential.
 * Version: 0.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Byron Iniotakis
 * License: GPL-2.0-or-later
 * Text Domain: webuzo-site-cache
 */
namespace WebuzoSiteCache;
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-native-panel.php';

final class Plugin {
    const OPTION = 'webuzo_site_cache_api'; // Retired settings, removed during admin migration.
    const SLUG = 'webuzo-site-cache';

    public static function boot() {
        add_action('admin_init', [__CLASS__, 'retire_legacy_credentials']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_bar_menu', [__CLASS__, 'bar'], 100);
        add_action('admin_post_webuzo_site_cache', [__CLASS__, 'handle']);
    }

    public static function retire_legacy_credentials() {
        if (!current_user_can('manage_options')) { return; }
        $old = get_option(self::OPTION, []);
        if (is_array($old) && array_intersect(['key', 'panel', 'user', 'transport'], array_keys($old))) {
            delete_option(self::OPTION);
            if (!empty($old['key'])) {
                set_transient('wsc_notice_' . get_current_user_id(), ['type' => 'warning', 'text' =>
                    'Legacy API settings were removed from WordPress. Revoke the previous account API key in Webuzo as well; deleting its saved copy does not revoke it.'], 120);
            }
        }
    }

    public static function menu() {
        add_management_page('Webuzo Site Cache', 'Webuzo Site Cache', 'manage_options', self::SLUG, [__CLASS__, 'page']);
    }

    public static function bar($bar) {
        if (current_user_can('manage_options') && !is_multisite()) {
            $bar->add_node(['id' => self::SLUG, 'title' => 'Clear Cache', 'href' => admin_url('tools.php?page=' . self::SLUG)]);
            $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">'
                . '<input type="hidden" name="action" value="webuzo_site_cache">'
                . '<input type="hidden" name="operation" value="purge">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('webuzo_site_cache_purge')) . '">'
                . '<button type="submit" title="Clears Nginx cache for all websites in this hosting account." style="display:block;width:100%;border:0;background:transparent;color:inherit;font:inherit;line-height:inherit;text-align:left;cursor:pointer;padding:0">Clear Cache</button></form>';
            $bar->add_node(['id' => self::SLUG . '-purge', 'parent' => self::SLUG, 'title' => $form]);
            $bar->add_node(['id' => self::SLUG . '-settings', 'parent' => self::SLUG, 'title' => 'Settings', 'href' => admin_url('tools.php?page=' . self::SLUG)]);
        }
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
        echo '<p>The Native Webuzo bridge uses the hosting provider’s existing panel to clear cache. The companion Webuzo plugin provisions a separate credential for each hosting account. No panel password or general API key is needed.</p>';
        echo '<div class="notice notice-warning inline"><p><strong>This plugin clears the Nginx cache for all websites in this hosting account.</strong></p></div>';
        echo '<h2>Check connection</h2><p>Checks which hosting account will be used. This action does not clear cache.</p>';
        self::start_form('connection'); submit_button('Test connection', 'secondary'); echo '</form>';
        echo '<h2>Clear account cache</h2>';
        self::start_form('purge'); submit_button('Clear Nginx cache for this account', 'primary'); echo '</form>';
        echo '<p>Wait 2 seconds between connection checks and 10 seconds between cache clears.</p>';
        echo '<p>Version 0.5.0 · Manual account-wide clearing through Webuzo. No automatic purge or change to whether caching is enabled.</p></div>';
    }

    public static function handle() {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage this site’s cache.', '', ['response' => 403]); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Use the cache management form.', '', ['response' => 405]); }
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? wp_unslash($_POST['operation']) : '';
        if (!in_array($operation, ['connection', 'purge'], true)) { wp_die('Unknown operation.', '', ['response' => 400]); }
        check_admin_referer('webuzo_site_cache_' . $operation);
        try {
            if (is_multisite()) { throw new \RuntimeException('Multisite is not supported by this release.'); }
            $client = new NativePanel();
            if ($operation === 'connection') {
                $r = $client->check_site(home_url('/'));
                $text = sprintf('Connected to the native Webuzo bridge for hosting account %s. Connection test only; no cache was cleared.', $r['account']);
            } else {
                $r = $client->purge(home_url('/'));
                $text = sprintf('The native Webuzo cache-clear command completed for hosting account %s. This affects every site in that account. New visits rebuild cache.', $r['account']);
            }
            $type = 'success';
        } catch (\Throwable $error) { $text = $error->getMessage(); $type = 'error'; }
        set_transient('wsc_notice_' . get_current_user_id(), ['type' => $type, 'text' => $text], 120);
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit;
    }
}
Plugin::boot();
