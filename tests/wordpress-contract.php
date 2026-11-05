<?php
/** WordPress authorization/UI/migration stubs. No network or native commands. */
define('ABSPATH', __DIR__);
$authorized = true; $multisite = false; $validNonce = true; $options = []; $notice = null; $checks = []; $transients = [];
function add_action(...$args) {}
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function sanitize_text_field($value) { return strip_tags($value); }
function is_admin_bar_showing() { return $GLOBALS['toolbar_visible'] ?? true; }
function plugins_url($path, $file) { return 'https://www.example.test/wp-content/plugins/clear-nginx-for-webuzo/' . $path; }
function wp_enqueue_style(...$args) { $GLOBALS['styles'][] = $args; }
function current_user_can($cap) { return $GLOBALS['authorized'] && $cap === 'manage_options'; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $ttl) { $GLOBALS['notice'] = $value; $GLOBALS['transients'][$key] = $value; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient($key) { $GLOBALS['notice'] = null; unset($GLOBALS['transients'][$key]); }
function admin_url($path) { return 'https://www.example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://www.example.test/'; }
function wp_unslash($value) { return $value; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException('HTTP ' . $args['response'] . ': ' . $message); }
function check_admin_referer($action) { $GLOBALS['nonce_action'] = $action; if (!$GLOBALS['validNonce']) { throw new RuntimeException('HTTP 403: invalid nonce'); } }
function wp_safe_redirect($url) { throw new RuntimeException('redirect'); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_create_nonce($action) { return 'test-toolbar-nonce'; }
function submit_button($label, $class = '') { echo '<button type="submit">' . esc_html($label) . '</button>'; }
require __DIR__ . '/../clear-nginx-for-webuzo/clear-nginx-for-webuzo.php';
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } $GLOBALS['checks'][] = $label; }
function handle() { try { \WebuzoSiteCache\Plugin::handle(); } catch (RuntimeException $e) { return $e->getMessage(); } throw new RuntimeException('Missing rejection/redirect'); }
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = ['operation' => 'purge'];
check(strpos(handle(), 'HTTP 405') === 0, 'Mutating GET rejected');
$_SERVER['REQUEST_METHOD'] = 'POST'; $authorized = false;
check(strpos(handle(), 'HTTP 403') === 0, 'Nonadministrator rejected');
$authorized = true; $validNonce = false;
check(strpos(handle(), 'HTTP 403') === 0, 'Nonce required');
$validNonce = true;
foreach (['upload', 'delete_account', 'preview', 'settings', ['purge'], 'PURGE'] as $op) {
    $_POST = ['operation' => $op]; check(strpos(handle(), 'HTTP 400') === 0, 'Unsupported or malformed operation rejected');
}
$multisite = true;
foreach (['connection', 'purge'] as $op) {
    $_POST = ['operation' => $op]; check(handle() === 'redirect' && $notice['type'] === 'error', 'Multisite rejected before transport');
    check($nonce_action === 'webuzo_site_cache_' . $op, 'Operation-specific nonce required');
}
$multisite = false;
set_transient('webuzo_site_cache_notice_1', ['type' => 'error', 'text' => '<script>test</script>'], 120);
ob_start(); \WebuzoSiteCache\Plugin::page(); $html = ob_get_clean();
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Notices escaped');
check(strpos($html, 'all websites in this hosting account') !== false, 'Account-wide warning visible');
check(strpos($html, 'confirm_account') === false, 'No purge confirmation checkbox');
check(strpos($html, 'name="apikey"') === false && strpos($html, 'name="panel"') === false, 'No broad API credential or destination fields');
check(strpos($html, 'Preview account cache') === false, 'No obsolete filesystem preview');
set_transient('wsc_notice_1', ['type' => 'warning', 'text' => 'foreign-plugin-notice'], 120);
set_transient('webuzo_site_cache_notice_2', ['type' => 'warning', 'text' => 'other-user-notice'], 120);
ob_start(); \WebuzoSiteCache\Plugin::page(); $isolated = ob_get_clean();
check(strpos($isolated, 'foreign-plugin-notice') === false && strpos($isolated, 'other-user-notice') === false, 'Foreign and other-user notices are not rendered');
check(isset($transients['wsc_notice_1'], $transients['webuzo_site_cache_notice_2']), 'Foreign and other-user notices are preserved');
$toolbar = new class { public $nodes = []; public function add_node($n) { $this->nodes[$n['id']] = $n; } };
\WebuzoSiteCache\Plugin::bar($toolbar);
$node = $toolbar->nodes['clear-nginx-for-webuzo-purge'];
check($node['parent'] === 'clear-nginx-for-webuzo' && strpos($node['title'], '>Clear Cache</button>') !== false, 'Clear Cache dropdown action');
check(strpos($node['title'], 'method="post"') !== false && strpos($node['title'], 'test-toolbar-nonce') !== false && !isset($node['href']), 'Toolbar uses POST and nonce');
$styles = []; $toolbar_visible = true;
\WebuzoSiteCache\Plugin::styles();
check(count($styles) === 1 && $styles[0][1] === 'https://www.example.test/wp-content/plugins/clear-nginx-for-webuzo/assets/admin-bar.css', 'Styles use the main plugin path');
check($styles[0][2] === ['admin-bar'] && $styles[0][3] === '0.5.5', 'Toolbar styles declare dependency and cache version');
foreach (['visitor', 'hidden', 'multisite'] as $case) {
    $styles = []; $authorized = $case !== 'visitor'; $toolbar_visible = $case !== 'hidden'; $multisite = $case === 'multisite';
    \WebuzoSiteCache\Plugin::styles(); check($styles === [], 'No unnecessary styles: ' . $case);
}
$authorized = true; $toolbar_visible = true; $multisite = false;
check(strpos($node['title'], 'style=') === false, 'Toolbar uses enqueued CSS rather than inline styles');
$options[\WebuzoSiteCache\Plugin::OPTION] = ['key' => 'fake-legacy-ciphertext', 'panel' => 'https://panel.example.test:2003', 'user' => 'account1'];
$authorized = false; \WebuzoSiteCache\Plugin::retire_legacy_credentials();
check(isset($options[\WebuzoSiteCache\Plugin::OPTION]), 'Only administrator migrates old settings');
$authorized = true; \WebuzoSiteCache\Plugin::retire_legacy_credentials();
check(!isset($options[\WebuzoSiteCache\Plugin::OPTION]), 'Legacy broad API credential settings removed');
check($notice['type'] === 'warning' && strpos($notice['text'], 'Revoke') !== false, 'Migration explains separate Webuzo key revocation');
ob_start(); \WebuzoSiteCache\Plugin::page(); $html = ob_get_clean();
check(strpos($html, 'fake-legacy-ciphertext') === false, 'Legacy credential never rendered');
try {
    include __DIR__ . '/../clear-nginx-for-webuzo/clear-nginx-for-webuzo.php';
    throw new RuntimeException('Duplicate installation was loaded');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'HTTP 409: Deactivate the previous') === 0, 'Duplicate active implementation rejected before redeclaring classes');
}
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
