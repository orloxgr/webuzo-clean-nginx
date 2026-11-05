<?php
namespace WebuzoSiteCache {
    $scenario = 'success'; $sent = ''; $c = ['v' => 1, 'id' => str_repeat('a', 32), 'key' => str_repeat('b', 64)];
    function posix_geteuid() { return 1001; }
    function lstat($path) { return ['uid' => 0, 'mode' => 0100440, 'nlink' => 1, 'size' => 200]; }
    function realpath($path) { return $path; }
    function file_get_contents($path) { return json_encode($GLOBALS['c']); }
    function stream_socket_client($uri, &$errno, &$error, $timeout) {
        $GLOBALS['target'] = $uri;
        return \fopen('php://temp', 'w+');
    }
    function stream_set_timeout($s, $t) { return true; }
    function microtime($float = false) {
        if ($GLOBALS['scenario'] === 'deadline') { $GLOBALS['clock'] = ($GLOBALS['clock'] ?? 0) + 11; return $GLOBALS['clock']; }
        return \microtime($float);
    }
    function stream_get_meta_data($s) { return ['timed_out' => false]; }
    function fwrite($s, $wire) {
        $GLOBALS['sent'] .= $wire;
        list($head, $body) = explode("\r\n\r\n", $wire, 2);
        $r = json_decode($body, true);
        $response = ['v' => 1, 'ok' => true, 'code' => $r['op'] === 'purge' ? 'native_completed' : 'ready',
            'nonce' => $r['nonce'], 'account' => 'account1', 'scope' => 'account', 'op' => $r['op'], 'backend' => 'webuzo-native'];
        if ($GLOBALS['scenario'] === 'nonce') { $response['nonce'] = str_repeat('0', 32); }
        if ($GLOBALS['scenario'] === 'scope') { $response['scope'] = 'server'; }
        if ($GLOBALS['scenario'] === 'failed') { $response['ok'] = false; $response['code'] = 'native_failed'; }
        if ($GLOBALS['scenario'] === 'rate') { $response['ok'] = false; $response['code'] = 'rate_limited'; }
        if ($GLOBALS['scenario'] === 'confirmation') { $response['code'] = 'unconfirmed'; }
        $raw = json_encode($response, JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', "WCN-RESPONSE\n" . $raw, hex2bin($GLOBALS['c']['key']));
        if ($GLOBALS['scenario'] === 'signature') { $sig = str_repeat('0', 64); }
        $extra = $GLOBALS['scenario'] === 'duplicate' ? 'Content-Length: ' . strlen($raw) . "\r\n" : '';
        $reply = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($raw) . "\r\nX-WCN-Signature: " . $sig . "\r\n" . $extra . "\r\n" . $raw;
        if ($GLOBALS['scenario'] === 'truncated') { $reply = substr($reply, 0, -5); }
        \fwrite($s, $reply); \rewind($s);
        return strlen($wire);
    }
}
namespace {
    require __DIR__ . '/../webuzo-site-cache/includes/class-native-panel.php';
    $checks = [];
    function check($ok, $label) { if (!$ok) { throw new \RuntimeException($label); } $GLOBALS['checks'][] = $label; }
    function fails($fn, $text) { try { $fn(); } catch (\Throwable $e) { return strpos($e->getMessage(), $text) !== false; } return false; }
    $client = new \WebuzoSiteCache\NativePanel();
    check($client->check_site('https://unrelated.test/')['account'] === 'account1', 'Account derived from authenticated response, not URL');
    check($target === 'tcp://127.0.0.1:2002', 'Fixed loopback panel only');
    list($head, $body) = explode("\r\n\r\n", $sent, 2);
    $wire = json_decode($body, true);
    check(array_keys($wire) === ['v', 'op', 'id', 'ts', 'nonce'] && $wire['op'] === 'status', 'No account, path or command in request');
    check(strpos($sent, $c['key']) === false && strpos($sent, hex2bin($c['key'])) === false, 'Signing secret never transmitted');
    check($client->purge('')['code'] === 'native_completed', 'Authenticated native completion accepted');
    foreach (['signature' => 'signature', 'nonce' => 'confirm', 'scope' => 'scope', 'failed' => 'did not complete',
        'rate' => 'Wait 2', 'confirmation' => 'rejected', 'duplicate' => 'Ambiguous', 'truncated' => 'unknown', 'deadline' => 'timed out'] as $scenario => $message) {
        check(fails(function () use ($client) { $client->purge(''); }, $message), 'Response rejected: ' . $scenario);
    }
    echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
}
