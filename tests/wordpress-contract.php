<?php
namespace ClearNginxForWebuzo {
    function function_exists($name) { return $name === 'posix_geteuid' ? false : \function_exists($name); }
}
namespace {
/** WordPress authorization/UI stubs. No network or native commands. */
define('ABSPATH', __DIR__);
$authorized = true; $multisite = false; $validNonce = true; $notice = null; $checks = []; $transients = [];
function add_action($hook, ...$args) { $GLOBALS['hooks'][$hook] = $args; }
    class WP_Error {
        private $code;
        private $message;
        public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain = '') { return $GLOBALS['translations'][$text] ?? $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function sanitize_text_field($value) { return strip_tags($value); }
function is_admin_bar_showing() { return $GLOBALS['toolbar_visible'] ?? true; }
function plugins_url($path, $file) { return 'https://www.example.test/wp-content/plugins/clear-nginx-for-webuzo/' . $path; }
function wp_enqueue_style(...$args) { $GLOBALS['styles'][] = $args; }
function current_user_can($cap) { return $GLOBALS['authorized'] && $cap === 'manage_options'; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $ttl) { $GLOBALS['notice'] = $value; $GLOBALS['transients'][$key] = $value; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function delete_transient($key) { $GLOBALS['notice'] = null; unset($GLOBALS['transients'][$key]); }
function admin_url($path) { return 'https://www.example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://www.example.test/'; }
function wp_unslash($value) { return $value; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException('HTTP ' . $args['response'] . ': ' . $message); }
function check_admin_referer($action) { $GLOBALS['nonce_action'] = $action; if (!$GLOBALS['validNonce']) { throw new RuntimeException('HTTP 403: invalid nonce'); } }
function wp_safe_redirect($url) { $GLOBALS['redirect_url'] = $url; throw new RuntimeException('redirect'); }
function wp_get_referer() { return $GLOBALS['referer'] ?? false; }
function wp_referer_field($display = true) { $html = '<input type="hidden" name="_wp_http_referer" value="/wp-admin/index.php">'; if ($display) { echo $html; } return $html; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_create_nonce($action) { $GLOBALS['toolbar_nonce_action'] = $action; return 'test-toolbar-nonce'; }
function submit_button($label, $class = '') { echo '<button type="submit">' . esc_html($label) . '</button>'; }
require __DIR__ . '/../clear-nginx-for-webuzo/clear-nginx-for-webuzo.php';
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } $GLOBALS['checks'][] = $label; }
function handle() { try { \ClearNginxForWebuzo\Plugin::handle(); } catch (RuntimeException $e) { return $e->getMessage(); } throw new RuntimeException('Missing rejection/redirect'); }
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
foreach (['<b>purge</b>', '<b>connection</b>', ' purge', 'purge ', "purge\n", "connec\ttion", "purge\0", 'pur%67e', 1, true, null] as $op) {
    $_POST = ['operation' => $op];
    $GLOBALS['nonce_action'] = null;
    check(strpos(handle(), 'HTTP 400') === 0 && $GLOBALS['nonce_action'] === null, 'Altered or non-string operation rejected before nonce/transport: ' . json_encode($op));
}
$_POST = [];
check(strpos(handle(), 'HTTP 400') === 0, 'Missing operation rejected');
$multisite = true;
foreach (['connection', 'purge'] as $op) {
    $_POST = ['operation' => $op]; check(handle() === 'redirect' && $notice['type'] === 'error', 'Multisite rejected before transport');
    check($nonce_action === 'clear_nginx_for_webuzo_' . $op, 'Operation-specific nonce required');
}
$multisite = false;
$source = 'PHP POSIX support is required for the native panel connection.';
$translated = '<b>Missing POSIX</b> & "quoted"';
$GLOBALS['translations'][$source] = $translated;
$_POST = ['operation' => 'connection'];
check(handle() === 'redirect' && $notice['type'] === 'error' && $notice['text'] === $translated, 'Client WP_Error becomes an error notice without pre-escaping');
ob_start(); \ClearNginxForWebuzo\Plugin::page(); $error_html = ob_get_clean();
check(strpos($error_html, esc_html($translated)) !== false && strpos($error_html, '<b>Missing POSIX</b>') === false, 'WP_Error HTML is escaped at display');
check(strpos($error_html, '&amp;lt;b&amp;gt;') === false, 'WP_Error message is not double escaped');
unset($GLOBALS['translations'][$source]);
$referer = 'https://www.example.test/wp-admin/edit.php';
check(handle() === 'redirect' && $redirect_url === $referer, 'Operation returns to the current page instead of settings');
$referer = false;
check(handle() === 'redirect' && strpos($redirect_url, 'tools.php?page=clear-nginx-for-webuzo') !== false, 'Missing safe referer falls back to the tools page');
$_REQUEST['_wp_http_referer'] = ['malformed'];
$referer = 'https://www.example.test/wp-admin/edit.php';
check(strpos(handle(), 'HTTP 400') === 0, 'Malformed return field rejected before nonce and transport');
unset($_REQUEST['_wp_http_referer']); $referer = false;
set_transient('clear_nginx_for_webuzo_notice_1', ['type' => 'success', 'text' => 'result on originating page'], 120);
ob_start(); \ClearNginxForWebuzo\Plugin::notices(); $notice_html = ob_get_clean();
check(strpos($notice_html, 'result on originating page') !== false && get_transient('clear_nginx_for_webuzo_notice_1') === false, 'Result notice is rendered and consumed on the originating admin page');

set_transient('clear_nginx_for_webuzo_notice_1', ['type' => 'error', 'text' => '<script>test</script>'], 120);
ob_start(); \ClearNginxForWebuzo\Plugin::page(); $html = ob_get_clean();
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Notices escaped');
check(strpos($html, 'all websites in this hosting account') !== false, 'Account-wide warning visible');
check(strpos($html, 'confirm_account') === false, 'No purge confirmation checkbox');
check(strpos($html, 'name="apikey"') === false && strpos($html, 'name="panel"') === false, 'No broad API credential or destination fields');
check(strpos($html, 'Preview account cache') === false, 'No obsolete filesystem preview');
check(strpos($html, 'provider’s next enrollment sync') === false && strpos($html, 'Manual account-wide clearing through Webuzo.') === false && strpos($html, 'The Native Webuzo bridge uses') === false, 'Hosting implementation details removed from settings');
check(strpos($html, 'name="action" value="clear_nginx_for_webuzo"') !== false, 'Tools forms use the matching plugin action');
check(isset($hooks['admin_post_clear_nginx_for_webuzo']), 'Authenticated handler registered under the matching plugin action');
set_transient('other_plugin_notice_1', ['type' => 'warning', 'text' => 'foreign-plugin-notice'], 120);
set_transient('clear_nginx_for_webuzo_notice_2', ['type' => 'warning', 'text' => 'other-user-notice'], 120);
ob_start(); \ClearNginxForWebuzo\Plugin::page(); $isolated = ob_get_clean();
check(strpos($isolated, 'foreign-plugin-notice') === false && strpos($isolated, 'other-user-notice') === false, 'Foreign and other-user notices are not rendered');
check(isset($transients['other_plugin_notice_1'], $transients['clear_nginx_for_webuzo_notice_2']), 'Foreign and other-user notices are preserved');
$toolbar = new class { public $nodes = []; public function add_node($n) { $this->nodes[$n['id']] = $n; } };
\ClearNginxForWebuzo\Plugin::bar($toolbar);
$node = $toolbar->nodes['clear-nginx-for-webuzo'];
check(!isset($node['parent']) && !isset($node['href']) && strpos($node['title'], '>Clear Cache</button>') !== false, 'Primary Clear Cache item is the POST button, not a settings link');
check($toolbar->nodes['clear-nginx-for-webuzo-settings']['parent'] === 'clear-nginx-for-webuzo' && strpos($toolbar->nodes['clear-nginx-for-webuzo-settings']['href'], 'tools.php?page=') !== false, 'Settings remains a separate submenu link');
check(strpos($node['title'], 'name="_wp_http_referer"') !== false, 'Toolbar includes the current page return field');
check(strpos($node['title'], 'method="post"') !== false && strpos($node['title'], 'test-toolbar-nonce') !== false && !isset($node['href']), 'Toolbar uses POST and nonce');
check($toolbar_nonce_action === 'clear_nginx_for_webuzo_purge' && strpos($node['title'], 'name="action" value="clear_nginx_for_webuzo"') !== false, 'Toolbar action and nonce use the matching plugin prefix');
$styles = []; $toolbar_visible = true;
\ClearNginxForWebuzo\Plugin::styles();
check(count($styles) === 1 && $styles[0][1] === 'https://www.example.test/wp-content/plugins/clear-nginx-for-webuzo/assets/admin-bar.css', 'Styles use the main plugin path');
check($styles[0][2] === ['admin-bar'] && $styles[0][3] === '0.5.7', 'Toolbar styles declare dependency and cache version');
foreach (['visitor', 'hidden', 'multisite'] as $case) {
    $styles = []; $authorized = $case !== 'visitor'; $toolbar_visible = $case !== 'hidden'; $multisite = $case === 'multisite';
    \ClearNginxForWebuzo\Plugin::styles(); check($styles === [], 'No unnecessary styles: ' . $case);
}
$authorized = true; $toolbar_visible = true; $multisite = false;
check(strpos($node['title'], 'style=') === false, 'Toolbar uses enqueued CSS rather than inline styles');
try {
    include __DIR__ . '/../clear-nginx-for-webuzo/clear-nginx-for-webuzo.php';
    throw new RuntimeException('Duplicate installation was loaded');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'HTTP 409: Only one copy of Clear Nginx for Webuzo') === 0, 'Duplicate active implementation rejected before redeclaring classes');
}
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
}
