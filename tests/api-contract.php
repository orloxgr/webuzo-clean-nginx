<?php
/** Isolated WordPress and HTTP stubs. No real credentials or network calls. */
define('ABSPATH', __DIR__);
$options = [];
$notice = null;
$authorized = true;
$multisite = false;
$validNonce = true;
$salt = 'isolated-test-wordpress-salt';
$queue = [];
$calls = [];
$checks = [];
class WP_Error {}
function add_action(...$args) {}
function current_user_can($cap) { return $GLOBALS['authorized'] && $cap === 'manage_options'; }
function is_multisite() { return $GLOBALS['multisite']; }
function get_option($key, $default = false) { return isset($GLOBALS['options'][$key]) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $ttl) { $GLOBALS['notice'] = $value; }
function get_transient($key) { return $GLOBALS['notice']; }
function delete_transient($key) { $GLOBALS['notice'] = null; }
function admin_url($path) { return 'https://www.example.test/wp-admin/' . $path; }
function wp_parse_url($url) { return parse_url($url); }
function home_url($path = '') { return 'https://www.example.test/el/'; }
function wp_salt($scheme) { return $GLOBALS['salt']; }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException('HTTP ' . $args['response'] . ': ' . $message); }
function check_admin_referer($action) { if (!$GLOBALS['validNonce']) { throw new RuntimeException('HTTP 403: invalid nonce'); } }
function wp_safe_redirect($url) { throw new RuntimeException('redirect'); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_create_nonce($action) { return 'test-toolbar-nonce'; }
function submit_button($label, $class = '', $name = '', $wrap = true) { echo '<button type="submit">' . esc_html($label) . '</button>'; }
function wp_remote_post($url, $args) {
    $GLOBALS['calls'][] = ['url' => $url, 'args' => $args];
    if (!$GLOBALS['queue']) { throw new RuntimeException('Unexpected HTTP call in isolated test'); }
    return array_shift($GLOBALS['queue']);
}
function is_wp_error($response) { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
require __DIR__ . '/../webuzo-site-cache/webuzo-site-cache.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks'][] = $message; }
function response($data, $status = 200) { return ['status' => $status, 'body' => is_string($data) ? $data : json_encode($data)]; }
function reset_http(array $responses) { $GLOBALS['queue'] = $responses; $GLOBALS['calls'] = []; }
function throws($callable, $text) { try { $callable(); } catch (Throwable $e) { return strpos($e->getMessage(), $text) !== false; } return false; }
function handle() { try { \WebuzoSiteCache\Plugin::handle(); } catch (RuntimeException $e) { return $e->getMessage(); } throw new RuntimeException('Missing redirect/rejection'); }

$api = new \WebuzoSiteCache\WebuzoApi('https://panel.example.test:2003/', 'account1', 'fake-test-api-key');
$domainList = response(['primary_domain' => 'example.test', 'domains_list' => ['example.test' => ['path' => '/home/account1/public_html']]]);
reset_http([$domainList]);
$account = $api->check_site(home_url('/'));
check($account['account'] === 'account1' && $account['domain_count'] === 1, 'Connection validates the www site against the account domain list');
check(count($calls) === 1 && strpos($calls[0]['url'], 'clear_cache') === false, 'Connection test never clears cache');
check($calls[0]['args']['sslverify'] === true && $calls[0]['args']['redirection'] === 0, 'TLS verification is required and redirects are disabled');
check(strpos($calls[0]['url'], 'fake-test-api-key') === false && $calls[0]['args']['body'] === ['apiuser' => 'account1', 'apikey' => 'fake-test-api-key'], 'API credentials are sent in the POST body only');
reset_http([$domainList, response(['done' => ['msg' => 'Cache cleared successfully']])]);
$api->purge(home_url('/'));
check(count($calls) === 2 && strpos($calls[1]['url'], 'act=nginx_proxy&clear_cache=1') !== false, 'Purge uses the observed Webuzo account clear-cache endpoint after ownership validation');
check(strpos($calls[1]['url'], 'action=') === false, 'Purge does not enable or disable caching');
reset_http([$domainList]);
check(throws(function () use ($api) { $api->purge('https://unrelated.test/'); }, 'not listed') && count($calls) === 1, 'Wrong-account site is rejected before any purge');
reset_http([$domainList, response(['title' => 'Nginx'])]);
check(throws(function () use ($api) { $api->purge(home_url('/')); }, 'did not confirm'), 'Missing done confirmation is not reported as success');
reset_http([response(['error' => ['Invalid API key']])]);
check(throws(function () use ($api) { $api->check_site(home_url('/')); }, 'rejected'), 'Authentication/action errors are rejected');
reset_http([response('<html>Login</html>')]);
check(throws(function () use ($api) { $api->check_site(home_url('/')); }, 'JSON'), 'HTML login pages are not accepted as API success');
reset_http([response('', 302)]);
check(throws(function () use ($api) { $api->check_site(home_url('/')); }, 'HTTP 302') && count($calls) === 1, 'Redirect response does not trigger a credential-bearing follow-up');
reset_http([new WP_Error]);
check(throws(function () use ($api) { $api->check_site(home_url('/')); }, 'securely'), 'Transport or certificate failures are reported');
foreach (['http://panel.test:2002', 'https://user:password@panel.test:2003', 'https://panel.test:2003/sessFake123/', 'https://panel.test:2003/?apikey=secret'] as $badUrl) {
    check(throws(function () use ($badUrl) { new \WebuzoSiteCache\WebuzoApi($badUrl, 'account1', 'fake-test-key'); }, 'URL'), 'Unsafe or temporary panel URL rejected: ' . preg_replace('~://.*@~', '://[redacted]@', $badUrl));
}
check(throws(function () { new \WebuzoSiteCache\WebuzoApi('https://panel.test:2003', 'root', 'fake-test-key'); }, 'not root'), 'Root credentials are refused');
check(\WebuzoSiteCache\WebuzoApi::normalize_panel('https://panel.test:2443/index.php') === 'https://panel.test:2443', 'Custom HTTPS end-user ports and index.php URLs are supported');
$encrypted = \WebuzoSiteCache\Secret::encrypt('fake-test-api-key');
check(\WebuzoSiteCache\Secret::decrypt($encrypted) === 'fake-test-api-key' && strpos($encrypted, 'fake-test-api-key') === false, 'Saved API key encrypts and decrypts without plaintext storage');
check(\WebuzoSiteCache\Secret::encrypt('fake-test-api-key') !== $encrypted, 'Each save uses a fresh encryption nonce');
$salt = 'changed-test-salt';
check(throws(function () use ($encrypted) { \WebuzoSiteCache\Secret::decrypt($encrypted); }, 'decrypt'), 'Changing WordPress salts requires key re-entry');
$salt = 'isolated-test-wordpress-salt';
reset_http([]);
$_SERVER['REQUEST_METHOD'] = 'GET';
check(strpos(handle(), 'HTTP 405') === 0, 'GET admin mutations are rejected');
$_SERVER['REQUEST_METHOD'] = 'POST';
$authorized = false;
check(strpos(handle(), 'HTTP 403') === 0, 'Non-administrators are rejected');
$authorized = true;
$_POST = ['operation' => 'purge'];
$validNonce = false;
check(strpos(handle(), 'HTTP 403') === 0, 'Nonce is required');
$validNonce = true;
check(handle() === 'redirect' && $notice['type'] === 'error' && !$calls, 'An unconfigured purge is rejected before network access');
$_POST = ['operation' => 'settings', 'transport' => 'api', 'panel' => 'https://panel.example.test:2003', 'apiuser' => 'account1', 'apikey' => 'fake-test-api-key'];
check(handle() === 'redirect' && $notice['type'] === 'success', 'API connection can be saved without network access');
$saved = $options[\WebuzoSiteCache\Plugin::OPTION];
check($saved['key'] !== 'fake-test-api-key', 'Settings do not persist the API key as plaintext');
$_POST['apikey'] = '';
$_POST['panel'] = 'https://different.test:2003';
check(handle() === 'redirect' && $notice['type'] === 'error' && $options[\WebuzoSiteCache\Plugin::OPTION] === $saved, 'Changing destination cannot reuse an existing secret');
$_POST = ['operation' => 'connection'];
reset_http([$domainList]);
check(handle() === 'redirect' && $notice['type'] === 'success' && count($calls) === 1, 'Admin connection action validates credentials without clearing');
$_POST = ['operation' => 'purge'];
reset_http([$domainList, response(['done' => ['msg' => 'Cache cleared successfully']])]);
check(handle() === 'redirect' && $notice['type'] === 'success' && count($calls) === 2, 'Admin purge checks membership and clears through API without a confirmation checkbox');
$notice = ['type' => 'error', 'text' => '<script>alert(1)</script>'];
ob_start(); \WebuzoSiteCache\Plugin::page(); $html = ob_get_clean();
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'API/admin messages are escaped');
check(strpos($html, 'fake-test-api-key') === false && strpos($html, $saved['key']) === false, 'Neither decrypted nor encrypted key is rendered in the settings page');
check(strpos($html, 'all websites in this hosting account') !== false && strpos($html, 'confirm_account') === false, 'UI displays the account-wide warning without a confirmation checkbox');
$toolbar = new class {
    public $nodes = [];
    public function add_node($node) { $this->nodes[$node['id']] = $node; }
};
\WebuzoSiteCache\Plugin::bar($toolbar);
$purgeItem = $toolbar->nodes[\WebuzoSiteCache\Plugin::SLUG . '-purge'];
check($purgeItem['parent'] === \WebuzoSiteCache\Plugin::SLUG && strpos($purgeItem['title'], '>Clear Cache</button>') !== false,
    'Admin bar provides a Clear Cache dropdown action');
check(strpos($purgeItem['title'], 'method="post"') !== false && strpos($purgeItem['title'], 'test-toolbar-nonce') !== false && !isset($purgeItem['href']),
    'Toolbar purge submits POST with a nonce, without a mutating GET link');
$_POST = ['operation' => 'settings', 'transport' => 'api', 'panel' => $saved['panel'], 'apiuser' => $saved['user'], 'forget_key' => '1'];
check(handle() === 'redirect' && $options[\WebuzoSiteCache\Plugin::OPTION]['key'] === '', 'Stored credentials can be removed');
$multisite = true;
$_POST = ['operation' => 'purge', 'confirm_account' => '1'];
reset_http([]);
check(handle() === 'redirect' && $notice['type'] === 'error' && !$calls, 'Unsupported multisite is rejected before API access');
$multisite = false;
$_POST = ['operation' => 'settings', 'transport' => 'local'];
reset_http([]);
check(handle() === 'redirect' && $notice['type'] === 'success' && !$calls && \WebuzoSiteCache\Plugin::mode() === 'local',
    'Local mode saves without API configuration or network access');
$_POST = ['operation' => 'connection'];
handle();
check(!$calls, 'Unavailable local service never falls back to the Webuzo API');
echo json_encode(['passed' => count($checks), 'checks' => $checks, 'network_calls' => 'stubbed only'], JSON_PRETTY_PRINT) . "\n";
