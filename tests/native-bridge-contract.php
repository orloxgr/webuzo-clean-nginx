<?php
/** Pure protocol checks: fake capabilities only, no filesystem or native command. */
require __DIR__ . '/../webuzo-clean-nginx/includes/class-native-bridge.php';
$checks = [];
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } $GLOBALS['checks'][] = $label; }
function reject($call, $code) { try { $call(); } catch (Throwable $e) { return $e->getMessage() === $code; } return false; }
$id = str_repeat('a', 32); $other = str_repeat('b', 32);
$entry = ['account' => 'account1', 'uid' => 1001, 'gid' => 1001, 'generation' => str_repeat('1', 64), 'key' => str_repeat('c', 64)];
$keys = [$id => $entry, $other => ['account' => 'account2', 'uid' => 1002, 'gid' => 1002, 'generation' => str_repeat('2', 64), 'key' => str_repeat('d', 64)]];
$request = ['v' => 1, 'op' => 'purge', 'id' => $id, 'ts' => 1000, 'nonce' => str_repeat('e', 32)];
function auth($r, $key = null, $now = 1000) {
    $raw = is_string($r) ? $r : json_encode($r, JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', "WCN-REQUEST\n" . $raw, hex2bin($key ?? $GLOBALS['entry']['key']));
    return WCN_Native_Bridge::authenticate($raw, $signature, $GLOBALS['keys'], $now);
}
check(auth($request)[1]['account'] === 'account1', 'Account comes exclusively from server registry');
$r = $request; $r['op'] = 'status';
check(auth($r)[0]['op'] === 'status', 'Read-only connection operation accepted');
$r = $request; $r['id'] = $other;
check(reject(function () use ($r) { auth($r); }, 'not_allowed'), 'Account A key cannot use account B identifier');
foreach (['account' => 'account2', 'users' => 'account2', 'path' => '/etc', 'command' => 'anything', 'upload' => 'anything'] as $field => $value) {
    $r = $request; $r[$field] = $value;
    check(reject(function () use ($r) { auth($r); }, 'bad_request'), 'Extra field rejected: ' . $field);
}
foreach (['delete', 'upload', 'preview', '--clear_cache', ['purge'], null] as $op) {
    $r = $request; $r['op'] = $op;
    check(reject(function () use ($r) { auth($r); }, 'bad_request'), 'Unknown or malformed operation rejected');
}
foreach (['1000', 1000.5, null, []] as $ts) {
    $r = $request; $r['ts'] = $ts;
    check(reject(function () use ($r) { auth($r); }, 'bad_request'), 'Timestamp must be an integer');
}
check(reject(function () use ($request) { auth($request, null, 1031); }, 'expired'), 'Expired request rejected');
check(reject(function () use ($request) { auth($request, null, 969); }, 'expired'), 'Future request rejected');
check(auth($request, null, 1030)[1]['uid'] === 1001, 'Timestamp boundary accepted');
$raw = json_encode($request);
$signature = hash_hmac('sha256', "WCN-REQUEST\n" . $raw, hex2bin($entry['key']));
check(reject(function () use ($raw, $signature, $keys) {
    WCN_Native_Bridge::authenticate(str_replace('purge', 'status', $raw), $signature, $keys, 1000);
}, 'not_allowed'), 'Operation tampering invalidates signature');
check(reject(function () use ($raw, $keys) { WCN_Native_Bridge::authenticate($raw, str_repeat('0', 64), $keys, 1000); }, 'not_allowed'), 'Forged signature rejected');
check(reject(function () use ($raw) { auth(' ' . $raw); }, 'bad_request'), 'Noncanonical JSON rejected');
check(reject(function () use ($raw) { auth('{"v":1,' . substr($raw, 1)); }, 'bad_request'), 'Duplicate JSON keys rejected');
check(reject(function () { auth(str_repeat('x', 513)); }, 'bad_request'), 'Oversize request rejected');
$r = $request; $r['id'] = str_repeat('f', 32);
check(reject(function () use ($r) { auth($r); }, 'not_allowed'), 'Unknown credential rejected');
$original = $keys[$id]['account']; $keys[$id]['account'] = 'account1;anything';
check(reject(function () use ($request) { auth($request); }, 'bad_registry'), 'Malformed registry account cannot reach native runner');
$keys[$id]['account'] = $original;
$keys[$id]['uid'] = 0;
check(reject(function () use ($request) { auth($request); }, 'bad_registry'), 'Root registry identity rejected');
echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
