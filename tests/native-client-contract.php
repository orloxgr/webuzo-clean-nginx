<?php
/** Signed HTTP API client fixtures. No network, privileged commands or real credentials. */
namespace ClearNginxForWebuzo {
    function function_exists($name) { return $name === 'posix_geteuid' ? !empty($GLOBALS['has_posix']) : \function_exists($name); }
    function posix_geteuid() { return $GLOBALS['uid']; }
    function lstat($path) { return $GLOBALS['stat']; }
    function realpath($path) { return $path; }
}
namespace {
    define('ABSPATH', __DIR__);
    $has_posix = true; $credential_raw = null;
    $scenario = 'success'; $uid = 1001; $stat = ['uid' => 0, 'mode' => 0100440, 'nlink' => 1, 'size' => 200];
    $credential = ['v' => 1, 'id' => str_repeat('a', 32), 'key' => str_repeat('b', 64)];
    function __($text, $domain = '') { return $text; }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    class WP_Error {
        private $code;
        private $message;
        public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    class WP_Filesystem_Direct {
        public function __construct($args) { $GLOBALS['filesystem_args'] = $args; }
        public function get_contents($path) {
            $GLOBALS['filesystem_reads'][] = $path;
            return $GLOBALS['credential_raw'] ?? json_encode($GLOBALS['credential']);
        }
    }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    function wp_remote_retrieve_header($r, $name) { return $r['headers'][$name] ?? ''; }
    function wp_remote_retrieve_response_code($r) { return $r['status']; }
    function wp_remote_post($url, $args) {
        $GLOBALS['target'] = $url; $GLOBALS['args'] = $args;
        if ($GLOBALS['scenario'] === 'transport') { return new WP_Error('http_request_failed', 'transport failure detail'); }
        $request = json_decode($args['body'], true);
        $data = ['v' => 1, 'ok' => true, 'code' => $request['op'] === 'purge' ? 'native_completed' : 'ready',
            'nonce' => $request['nonce'], 'account' => 'account1', 'scope' => 'account', 'op' => $request['op'], 'backend' => 'webuzo-native'];
        if ($GLOBALS['scenario'] === 'nonce') { $data['nonce'] = str_repeat('0', 32); }
        if ($GLOBALS['scenario'] === 'scope') { $data['scope'] = 'server'; }
        if ($GLOBALS['scenario'] === 'operation') { $data['op'] = 'upload'; }
        if ($GLOBALS['scenario'] === 'account') { $data['account'] = '../another'; }
        if ($GLOBALS['scenario'] === 'failed') { $data['ok'] = false; $data['code'] = 'native_failed'; }
        if ($GLOBALS['scenario'] === 'rate') { $data['ok'] = false; $data['code'] = 'rate_limited'; }
        if ($GLOBALS['scenario'] === 'confirmation') { $data['code'] = 'unconfirmed'; }
        $raw = json_encode($data, JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', "WCN-RESPONSE\n" . $raw, hex2bin($GLOBALS['credential']['key']));
        if ($GLOBALS['scenario'] === 'signature') { $sig = str_repeat('0', 64); }
        $response = ['body' => $raw, 'status' => 200, 'headers' => ['content-length' => (string) strlen($raw), 'x-wcn-signature' => $sig]];
        if ($GLOBALS['scenario'] === 'duplicate') { $response['headers']['content-length'] = [(string) strlen($raw), (string) strlen($raw)]; }
        if ($GLOBALS['scenario'] === 'duplicate-signature') { $response['headers']['x-wcn-signature'] = [$sig, $sig]; }
        if ($GLOBALS['scenario'] === 'missing-length') { unset($response['headers']['content-length']); }
        if ($GLOBALS['scenario'] === 'truncated') { $response['body'] = substr($raw, 0, -5); }
        if ($GLOBALS['scenario'] === 'oversized') { $response['body'] = str_repeat('a', 8193); $response['headers']['content-length'] = '8193'; }
        if ($GLOBALS['scenario'] === 'redirect') { $response['status'] = 302; }
        if ($GLOBALS['scenario'] === 'chunked') { $response['headers']['transfer-encoding'] = 'chunked'; }
        if ($GLOBALS['scenario'] === 'compressed') { $response['headers']['content-encoding'] = 'gzip'; }
        return $response;
    }
    require __DIR__ . '/../clear-nginx-for-webuzo/includes/class-native-panel.php';
    $checks = [];
    function check($ok, $label) { if (!$ok) { throw new \RuntimeException($label); } $GLOBALS['checks'][] = $label; }
    function fails($fn, $text, $code = null) { $result = $fn(); return is_wp_error($result) && strpos($result->get_error_code(), 'clear_nginx_for_webuzo_') === 0 && strpos($result->get_error_message(), $text) !== false && ($code === null || $result->get_error_code() === $code); }
    $wp_filesystem = (object) ['method' => 'ftpext', 'marker' => 'other-plugin'];
    $original_filesystem = $wp_filesystem;
    $filesystem_reads = [];
    $client = new \ClearNginxForWebuzo\Native_Panel();
    check($client->check_connection()['account'] === 'account1', 'Account derived from authenticated response');
    check($target === 'http://127.0.0.1:2002/index.php?act=webuzo-clean-nginx-bridge', 'Fixed loopback URL only');
    check($filesystem_args === null && $filesystem_reads === ['/var/lib/webuzo-clean-nginx/clients/1001.json'], 'WordPress direct filesystem reads only the account credential');
    check($wp_filesystem === $original_filesystem && $wp_filesystem->method === 'ftpext', 'Existing global filesystem transport is not changed');
    check($args['redirection'] === 0 && $args['timeout'] === 20 && $args['limit_response_size'] === 8193, 'No redirects and bounded request/response');
    check($args['cookies'] === [] && $args['decompress'] === false && $args['headers']['Accept-Encoding'] === 'identity', 'No session cookies or automatic decompression');
    $wire = json_decode($args['body'], true);
    check(array_keys($wire) === ['v', 'op', 'id', 'ts', 'nonce'] && $wire['op'] === 'status', 'No account, path or command in request');
    check(hash_equals(hash_hmac('sha256', "WCN-REQUEST\n" . $args['body'], hex2bin($credential['key'])), $args['headers']['X-WCN-Signature']), 'Exact request bytes signed');
    check(strpos($args['body'], $credential['key']) === false, 'Signing secret never transmitted');
    check($client->purge()['code'] === 'native_completed', 'Authenticated native completion accepted');
    foreach (['signature' => 'signature', 'nonce' => 'confirm', 'scope' => 'scope', 'operation' => 'confirm', 'account' => 'scope', 'failed' => 'did not complete',
        'rate' => 'Wait 2', 'confirmation' => 'rejected', 'duplicate' => 'complete', 'duplicate-signature' => 'complete', 'missing-length' => 'complete',
        'truncated' => 'complete', 'oversized' => 'complete', 'redirect' => 'rejected', 'chunked' => 'complete', 'compressed' => 'complete', 'transport' => 'unknown'] as $scenario => $message) {
        check(fails(function () use ($client) { return $client->purge(); }, $message), 'Response rejected: ' . $scenario);
    }
    $scenario = 'success'; $uid = 999;
    check(fails(function () use ($client) { return $client->purge(); }, 'own hosting'), 'System UID rejected');
    $uid = 1001; $stat['mode'] = 0100660;
    check(fails(function () use ($client) { return $client->purge(); }, 'activate'), 'Writable credential rejected');
    $stat['mode'] = 0100440; $stat['uid'] = 1001;
    check(fails(function () use ($client) { return $client->purge(); }, 'activate'), 'Tenant-owned credential rejected');
    $stat['uid'] = 0; $has_posix = false;
    $before = $args;
    check(fails(function () use ($client) { return $client->purge(); }, 'POSIX', 'clear_nginx_for_webuzo_posix_required'), 'Missing POSIX returns WP_Error');
    check($args === $before, 'Credential failure does not send an HTTP request');
    $has_posix = true; $credential_raw = '{';
    check(fails(function () use ($client) { return $client->purge(); }, 'invalid', 'clear_nginx_for_webuzo_credential_invalid'), 'Malformed credential JSON returns WP_Error');
    $credential_raw = null; $stat['size'] = 1025;
    check(fails(function () use ($client) { return $client->check_connection(); }, 'activate', 'clear_nginx_for_webuzo_credential_unavailable'), 'Oversized credential returns WP_Error');
    $stat['size'] = 200;
    $credential_raw = false;
    check(fails(function () use ($client) { return $client->purge(); }, 'invalid', 'clear_nginx_for_webuzo_credential_invalid'), 'WordPress filesystem read failure returns WP_Error');
    $credential_raw = str_repeat('x', 1025);
    check(fails(function () use ($client) { return $client->purge(); }, 'invalid', 'clear_nginx_for_webuzo_credential_invalid'), 'Oversized content after metadata check returns WP_Error');
    $credential_raw = null;
    $reads = count($filesystem_reads); $stat['nlink'] = 2;
    check(fails(function () use ($client) { return $client->purge(); }, 'activate') && count($filesystem_reads) === $reads, 'Hard-linked credential rejected before WordPress filesystem read');
    $stat['nlink'] = 1; $stat['mode'] = 0120777;
    check(fails(function () use ($client) { return $client->purge(); }, 'activate') && count($filesystem_reads) === $reads, 'Symlink credential rejected before WordPress filesystem read');
    $stat['mode'] = 0100444;
    check(fails(function () use ($client) { return $client->purge(); }, 'activate') && count($filesystem_reads) === $reads, 'World-readable credential rejected before WordPress filesystem read');
    $stat['mode'] = 0100440; $stat = false;
    check(fails(function () use ($client) { return $client->purge(); }, 'activate') && count($filesystem_reads) === $reads, 'Missing credential metadata returns WP_Error without reading');
    $stat = ['uid' => 0, 'mode' => 0100440, 'nlink' => 1, 'size' => 200]; $scenario = 'transport';
    $result = $client->purge();
    check(is_wp_error($result) && $result->get_error_code() === 'clear_nginx_for_webuzo_transport_unconfirmed' && strpos($result->get_error_message(), 'transport failure detail') === false, 'Transport error returns a safe WP_Error without leaking internal detail');
    echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
}