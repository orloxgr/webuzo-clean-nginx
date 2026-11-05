<?php
/** Signed HTTP API client fixtures. No network, privileged commands or real credentials. */
namespace WebuzoSiteCache {
    function function_exists($name) { return $name === 'posix_geteuid' || \function_exists($name); }
    function posix_geteuid() { return $GLOBALS['uid']; }
    function lstat($path) { return $GLOBALS['stat']; }
    function realpath($path) { return $path; }
    function file_get_contents($path) { return json_encode($GLOBALS['credential']); }
}
namespace {
    define('ABSPATH', __DIR__);
    $scenario = 'success'; $uid = 1001; $stat = ['uid' => 0, 'mode' => 0100440, 'nlink' => 1, 'size' => 200];
    $credential = ['v' => 1, 'id' => str_repeat('a', 32), 'key' => str_repeat('b', 64)];
    function __($text, $domain = '') { return $text; }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    function is_wp_error($value) { return $value === 'transport-error'; }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    function wp_remote_retrieve_header($r, $name) { return $r['headers'][$name] ?? ''; }
    function wp_remote_retrieve_response_code($r) { return $r['status']; }
    function wp_remote_post($url, $args) {
        $GLOBALS['target'] = $url; $GLOBALS['args'] = $args;
        if ($GLOBALS['scenario'] === 'transport') { return 'transport-error'; }
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
    function fails($fn, $text) { try { $fn(); } catch (\Throwable $e) { return strpos($e->getMessage(), $text) !== false; } return false; }
    $client = new \WebuzoSiteCache\Native_Panel();
    check($client->check_connection()['account'] === 'account1', 'Account derived from authenticated response');
    check($target === 'http://127.0.0.1:2002/index.php?act=webuzo-clean-nginx-bridge', 'Fixed loopback URL only');
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
        check(fails(function () use ($client) { $client->purge(); }, $message), 'Response rejected: ' . $scenario);
    }
    $scenario = 'success'; $uid = 999;
    check(fails(function () use ($client) { $client->purge(); }, 'own hosting'), 'System UID rejected');
    $uid = 1001; $stat['mode'] = 0100660;
    check(fails(function () use ($client) { $client->purge(); }, 'activate'), 'Writable credential rejected');
    $stat['mode'] = 0100440; $stat['uid'] = 1001;
    check(fails(function () use ($client) { $client->purge(); }, 'activate'), 'Tenant-owned credential rejected');
    echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
}