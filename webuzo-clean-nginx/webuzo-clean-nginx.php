<?php
/*
Plugin Name: Webuzo Clean Nginx
Version: 0.4.0
Description: Install a local, Unix UID authenticated Nginx cache service for WordPress.
Author: Byron Iniotakis
License: GPL-2.0-or-later
*/
if (!defined('SOFTACULOUS')) { http_response_code(403); exit; }

// Only native administrator activation/deactivation invokes these fixed commands.
// No request value, account name, path or shell fragment is interpolated.
register_activation_hook(__FILE__, 'wcn_activate');
function wcn_activate() {
    global $W, $error;
    $W->wexec('/usr/bin/python3 -I -B /usr/local/webuzo/plugins/webuzo-clean-nginx/service/lifecycle.py install');
    if (wcn_state()['state'] !== 'ready') {
        $error[] = 'Cache service installation did not complete. Inspect the root-only installation log.';
        return false;
    }
    return true;
}

register_deactivation_hook(__FILE__, 'wcn_deactivate');
function wcn_deactivate() {
    global $W, $error;
    $W->wexec('/usr/bin/python3 -I -B /usr/local/webuzo/plugins/webuzo-clean-nginx/service/lifecycle.py remove');
    $state = wcn_state();
    if ($state['state'] !== 'disabled') {
        $error[] = 'Cache service removal did not complete. The hosting administrator must inspect the service log.';
        return false;
    }
    return true;
}

function wcn_state() {
    $path = '/var/lib/webuzo-cache-purge/status.json';
    clearstatcache(true, $path);
    $info = @lstat($path);
    if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['uid'] !== 0 || ($info['mode'] & 0022) || $info['size'] > 4096) {
        return ['state' => 'pending', 'accounts' => 0];
    }
    $value = json_decode((string) @file_get_contents($path), true);
    return is_array($value) && isset($value['state'], $value['accounts']) ? $value : ['state' => 'unknown', 'accounts' => 0];
}

add_filter('admin_menu', 'wcn_admin_menu', 10, 1);
function wcn_admin_menu($menu) {
    global $globals;
    $menu['webuzo-clean-nginx'] = ['name' => 'Nginx Cache', 'href' => $globals['ind'] . 'act=webuzo-clean-nginx', 'icon' => 'fas fa-broom'];
    return $menu;
}

// Intentionally no reseller or end-user administrative action and no HTTP purge endpoint.
admin_act('webuzo-clean-nginx', 'wcn_admin');
function wcn_admin() { theme_for_act('wcn_admin_theme', 'webuzo-clean-nginx'); }
function wcn_admin_theme() {
    $state = wcn_state();
    $socket = @lstat('/run/webuzo-cache-purge/control.sock');
    $ready = $state['state'] === 'ready' && $socket && ($socket['mode'] & 0170000) === 0140000 && $socket['uid'] === 0;
    echo '<div class="soft-smbox p-4"><h2>Webuzo Clean Nginx</h2>';
    echo '<p>By Byron Iniotakis &middot; Version 0.4.0</p>';
    echo '<p><strong>Service: ' . ($ready ? 'installed; socket available' : htmlspecialchars((string) $state['state'], ENT_QUOTES, 'UTF-8')) . '</strong>. Enrolled hosting accounts: ' . (int) $state['accounts'] . '.</p>';
    echo '<p>Install the companion Webuzo Site Cache WordPress plugin, select Local cache service, then use Test connection. No hosting API key is required.</p>';
    echo '<p><strong>Clearing affects the Nginx cache of every website in the caller\'s hosting account.</strong> Linux identifies the PHP process; a website cannot choose another account.</p>';
    echo '<p>Activation enrolls eligible existing accounts with Nginx cache directories. After adding accounts or enabling their cache, deactivate and reactivate this plugin to refresh enrollment. Deactivation removes only this plugin\'s service and registry; it does not clear any cache.</p>';
    echo '<p>This page is read only. Installation errors are recorded in /var/log/webuzo-cache-purge-install.log. Service activity is recorded in the system journal. Socket presence alone does not verify a working connection; test from WordPress.</p></div>';
}
