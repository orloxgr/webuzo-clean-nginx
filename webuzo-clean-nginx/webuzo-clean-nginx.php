<?php
/*
Plugin Name: Webuzo Clean Nginx
Version: 0.5.3
Description: Account-scoped WordPress requests using Webuzo's native cache clear command.
Author: Byron Iniotakis
License: GPL-2.0-or-later
*/
if (!defined('SOFTACULOUS')) { http_response_code(403); exit; }

// Only this action uses separate, narrow authentication before panel login.
// It never authenticates a caller to any other Webuzo action.
if (isset($_GET['act']) && $_GET['act'] === 'webuzo-clean-nginx-bridge') {
    require_once __DIR__ . '/includes/class-native-bridge.php';
    WCN_Native_Bridge::serve();
    exit;
}

register_activation_hook(__FILE__, 'wcn_activate');
function wcn_activate() {
    global $W, $error;
    $W->wexec('/usr/bin/python3 -I -B /usr/local/webuzo/plugins/webuzo-clean-nginx/service/lifecycle.py install');
    if (wcn_state()['state'] !== 'ready') {
        $error[] = 'Native bridge enrollment did not complete. Inspect the Webuzo log and account group isolation.';
        return false;
    }
    return true;
}

register_deactivation_hook(__FILE__, 'wcn_deactivate');
function wcn_deactivate() {
    global $W, $error;
    $W->wexec('/usr/bin/python3 -I -B /usr/local/webuzo/plugins/webuzo-clean-nginx/service/lifecycle.py remove');
    if (wcn_state()['state'] !== 'disabled') {
        $error[] = 'Native bridge removal did not complete. Inspect the Webuzo log.';
        return false;
    }
    return true;
}

function wcn_state() {
    $path = '/var/lib/webuzo-clean-nginx/status.json';
    clearstatcache(true, $path); $s = @lstat($path);
    if (!$s || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== 0 || ($s['mode'] & 0022) || $s['size'] > 4096) {
        return ['state' => 'pending', 'accounts' => 0];
    }
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) && isset($data['state'], $data['accounts']) ? $data : ['state' => 'unknown', 'accounts' => 0];
}

add_filter('admin_menu', 'wcn_admin_menu', 10, 1);
function wcn_admin_menu($menu) {
    global $globals;
    $menu['webuzo-clean-nginx'] = ['name' => 'Nginx Cache', 'href' => $globals['ind'] . 'act=webuzo-clean-nginx', 'icon' => 'fas fa-broom'];
    return $menu;
}
admin_act('webuzo-clean-nginx', 'wcn_admin');
function wcn_admin() { theme_for_act('wcn_admin_theme', 'webuzo-clean-nginx'); }
function wcn_admin_theme() {
    $state = wcn_state();
    echo '<div class="soft-smbox p-4"><h2>Webuzo Clean Nginx</h2><p>By Byron Iniotakis &middot; Version 0.5.3</p>';
    echo '<p><strong>Native bridge: ' . htmlspecialchars((string) $state['state'], ENT_QUOTES, 'UTF-8') . '</strong>. Enrolled accounts: ' . (int) $state['accounts'] . '.</p>';
    echo '<p>WordPress uses an account-specific signing credential to request only a connection check or account cache clear through the existing Webuzo backend. No separate purge daemon or general hosting API key is needed.</p>';
    echo '<p><strong>Clearing affects every website in the hosting account.</strong> Webuzo performs the native filesystem operation. The plugin does not patch Webuzo, Nginx or PHP configuration.</p>';
    echo '<p>Install the companion WordPress plugin and test the connection from each account. Requests use the local panel port 2002.</p>';
    echo '<p>Eligible accounts are synchronized automatically every 10 minutes. This includes new accounts and accounts whose cache is enabled later. Existing account keys and purge cooldowns are preserved. The scheduled task never clears cache.</p>';
    if (!empty($state['last_refresh'])) { echo '<p>Last enrollment sync (UTC): ' . htmlspecialchars(gmdate('Y-m-d H:i:s', (int) $state['last_refresh']), ENT_QUOTES, 'UTF-8') . '.</p>'; }
    echo '<p>Deactivate/reactivate to rotate all credentials. Deactivation removes the scheduled task and revokes credentials without clearing cache.</p></div>';
}
