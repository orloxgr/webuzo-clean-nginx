<?php
namespace WebuzoSiteCache {
    // Replace only OS/transport primitives; the shipped LocalService parser is exercised intact.
    $fixture = '';
    $sent = '';
    $trusted = true;
    function lstat($path) { return ['uid' => $GLOBALS['trusted'] ? 0 : 1000, 'mode' => substr($path, -5) === '.sock' ? 0140666 : 0040755]; }
    function realpath($path) { return $path; }
    function stream_socket_client($uri, &$errno, &$error, $timeout) {
        $stream = \fopen('php://temp', 'w+');
        \fwrite($stream, $GLOBALS['fixture']);
        \rewind($stream);
        return $stream;
    }
    function fwrite($stream, $payload) { $GLOBALS['sent'] .= $payload; return strlen($payload); }
    function stream_set_timeout($stream, $seconds) { return true; }
    function stream_get_meta_data($stream) { return ['timed_out' => false]; }
}
namespace {
    require __DIR__ . '/../webuzo-site-cache/includes/class-local-service.php';
    $passed = 0;
    function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } ++$GLOBALS['passed']; }
    function fails($fn, $text) { try { $fn(); } catch (\Throwable $e) { return strpos($e->getMessage(), $text) !== false; } return false; }
    $client = new \WebuzoSiteCache\LocalService();
    $response = ['ok' => true, 'v' => 1, 'account' => 'account1', 'scope' => 'account'];
    $fixture = json_encode($response) . "\n";
    check($client->check_site('https://other.test/')['account'] === 'account1', 'Account comes from the service, not caller URL');
    check(json_decode(trim($sent), true) === ['v' => 1, 'op' => 'status'], 'Wire request contains only protocol and fixed operation');
    $response['result'] = ['visited' => 3, 'scanned' => 1, 'matched' => 1, 'deleted' => 1, 'unknown' => 0, 'errors' => 0, 'complete' => true];
    $fixture = json_encode($response) . "\n";
    $sent = '';
    check($client->purge('https://example.test/')['result']['deleted'] === 1, 'Purge response validates counters');
    check(json_decode(trim($sent), true) === ['v' => 1, 'op' => 'purge'], 'No account, path or hostname is sent with purge');
    $trusted = false;
    check(fails(function () use ($client) { $client->purge(''); }, 'trusted local'), 'Tenant-owned socket/runtime directory is rejected');
    $trusted = true;
    $fixture = '{"ok":false,"v":1,"code":"rate_limited","message":"untrusted text"}' . "\n";
    check(fails(function () use ($client) { $client->purge(''); }, 'Wait 10'), 'Rate limit is reported through a fixed local message');
    $response['result']['deleted'] = '1';
    $fixture = json_encode($response) . "\n";
    check(fails(function () use ($client) { $client->purge(''); }, 'invalid counters'), 'Malformed counters are rejected');
    $fixture = '{}';
    check(fails(function () use ($client) { $client->purge(''); }, 'complete response'), 'Truncated response does not claim success');
    echo json_encode(['passed' => $passed, 'transport' => 'stubbed; no live service used'], JSON_PRETTY_PRINT) . "\n";
}
