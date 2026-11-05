<?php
/** WordPress authorization/UI/migration stubs. No network or native commands. */
define('ABSPATH', __DIR__);
$authorized = true; $multisite = false; $validNonce = true; $options = []; $notice = null; $checks = [];
function add_action(...$args) {}
function current_user_can($cap) { return $GLOBALS['authorized'] && $cap === 'manage_options'; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $ttl) { $GLOBALS['notice'] = $value; }
function get_transient($key) { return $GLOBALS['notice']; }
function delete_transient($key) { $GLOBALS['notice'] = null; }
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
require __DIR__ . '/../webuzo-site-cache/webuzo-site-cache.php';
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
$notice = ['type' => 'error', 'text' => '<script>test</script>'];
ob_start(); \WebuzoSiteCache\Plugin::page(); $html = ob_get_clean();
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'Notices escaped');
check(strpos($html, 'all websites in this hosting account') !== false, 'Account-wide warning visible');
check(strpos($html, 'confirm_account') === false, 'No purge confirmation checkbox');
check(strpos($html, 'name="apikey"') === false && strpos($html, 'name="panel"') === false, 'No broad API credential or destination fields');
check(strpos($html, 'Preview account cache') === false, 'No obsolete filesystem preview');
$toolbar = new class { public $nodes = []; public function add_node($n) { $this->nodes[$n['id']] = $n; } };
\WebuzoSiteCache\Plugin::bar($toolbar);
$node = $toolbar->nodes['webuzo-site-cache-purge'];
check($node['parent'] === 'webuzo-site-cache' && strpos($node['title'], '>Clear Cache</button>') !== false, 'Clear Cache dropdown action');
check(strpos($node['title'], 'method="post"') !== false && strpos($node['title'], 'test-toolbar-nonce') !== false && !isset($node['href']), 'Toolbar uses POST and nonce');
$options[\WebuzoSiteCache\Plugin::OPTION] = ['key' => 'fake-legacy-ciphertext', 'panel' => 'https://panel.example.test:2003', 'user' => 'account1'];
$authorized = false; \WebuzoSiteCache\Plugin::retire_legacy_credentials();
check(isset($options[\WebuzoSiteCache\Plugin::OPTION]), 'Only administrator migrates old settings');
$authorized = true; \WebuzoSiteCache\Plugin::retire_legacy_credentials();
check(!isset($options[\WebuzoSiteCache\Plugin::OPTION]), 'Legacy broad API credential settings removed');
check($notice['type'] === 'warning' && strpos($notice['text'], 'Revoke') !== false, 'Migration explains separate Webuzo key revocation');
ob_start(); \WebuzoSiteCache\Plugin::page(); $html = ob_get_clean();
check(strpos($html, 'fake-legacy-ciphertext') === false, 'Legacy credential never rendered');
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
