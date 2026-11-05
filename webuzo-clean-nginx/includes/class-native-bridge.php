<?php
/** Account-scoped capability endpoint, loaded only by the active Webuzo plugin. */
final class WCN_Native_Bridge {
    const CONFIG = '/etc/webuzo-clean-nginx/bridge.json';
    const STATE = '/var/lib/webuzo-clean-nginx/state';
    const ACTION = 'webuzo-clean-nginx-bridge';

    private static function native_generation($account) {
        $webuzo = posix_getpwnam('webuzo');
        if (!$webuzo || $webuzo['uid'] <= 0 || $webuzo['uid'] >= 1000 || $webuzo['gid'] <= 0 || $webuzo['gid'] >= 1000) {
            throw new RuntimeException('identity_changed');
        }
        $path = '/var/webuzo/users/' . $account . '/info'; $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $current .= '/' . $part; clearstatcache(true, $current); $s = @lstat($current);
            $kind = $current === $path ? 0100000 : 0040000;
            if (!$s || ($s['mode'] & 0170000) !== $kind || !in_array($s['uid'], [0, $webuzo['uid']], true) ||
                ($s['mode'] & 0002) || (($s['mode'] & 0020) && !in_array($s['gid'], [0, $webuzo['gid']], true))) {
                throw new RuntimeException('identity_changed');
            }
        }
        if ($s['size'] > 1048576) { throw new RuntimeException('identity_changed'); }
        $data = json_decode(file_get_contents($path), true);
        if (is_array($data) && (!empty($data['disable_nginx_cache']) || !empty($data['disable_reverse_proxy']))) {
            throw new RuntimeException('cache_unavailable');
        }
        if (!is_array($data) || ($data['user'] ?? '') !== $account || !isset($data['uuid'], $data['created']) ||
            !is_string($data['uuid']) || !preg_match('/^[A-Za-z0-9-]{8,64}$/D', $data['uuid']) ||
            !is_int($data['created']) || $data['created'] <= 0) { throw new RuntimeException('identity_changed'); }
        return hash('sha256', $data['uuid'] . "\n" . $data['created']);
    }

    public static function trusted($path, $directory = false) {
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') { throw new RuntimeException('unsafe_path'); }
            $current .= '/' . $part;
            clearstatcache(true, $current);
            $s = @lstat($current);
            $last = $current === $path;
            $kind = $last && !$directory ? 0100000 : 0040000;
            if (!$s || ($s['mode'] & 0170000) !== $kind || $s['uid'] !== 0 || ($s['mode'] & 0022) ||
                ($kind === 0100000 && $s['nlink'] !== 1)) { throw new RuntimeException('unsafe_path'); }
        }
    }

    /** Pure validation; no operation can be chosen outside this exact protocol. */
    public static function authenticate($raw, $signature, array $keys, $now) {
        if (!is_string($raw) || strlen($raw) > 512 || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            throw new RuntimeException('bad_request');
        }
        $r = json_decode($raw, true);
        if (!is_array($r) || json_encode($r, JSON_UNESCAPED_SLASHES) !== $raw) { throw new RuntimeException('bad_request'); }
        $fields = array_keys($r); sort($fields);
        if ($fields !== ['id', 'nonce', 'op', 'ts', 'v'] || $r['v'] !== 1 || !is_int($r['ts']) ||
            !is_string($r['id']) || !preg_match('/^[a-f0-9]{32}$/D', $r['id']) ||
            !is_string($r['nonce']) || !preg_match('/^[a-f0-9]{32}$/D', $r['nonce']) ||
            !in_array($r['op'], ['status', 'purge'], true)) { throw new RuntimeException('bad_request'); }
        if (!isset($keys[$r['id']])) { throw new RuntimeException('not_allowed'); }
        $entry = $keys[$r['id']];
        if (!is_array($entry) || !isset($entry['key'], $entry['account'], $entry['uid'], $entry['gid'], $entry['generation']) ||
            !is_string($entry['key']) || !preg_match('/^[a-f0-9]{64}$/D', $entry['key']) ||
            !is_string($entry['account']) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/iD', $entry['account']) ||
            !is_int($entry['uid']) || $entry['uid'] < 1000 || !is_int($entry['gid']) ||
            !is_string($entry['generation']) || !preg_match('/^[a-f0-9]{64}$/D', $entry['generation'])) { throw new RuntimeException('bad_registry'); }
        if (!hash_equals(hash_hmac('sha256', "WCN-REQUEST\n" . $raw, hex2bin($entry['key'])), $signature)) {
            throw new RuntimeException('not_allowed');
        }
        if ($r['ts'] < $now - 30 || $r['ts'] > $now + 30) { throw new RuntimeException('expired'); }
        return [$r, $entry];
    }

    private static function respond(array $data, $key = null, $status = 200) {
        $raw = json_encode($data, JSON_UNESCAPED_SLASHES);
        // Discard any panel buffering: this action returns only its own response.
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($raw));
        if ($key !== null) { header('X-WCN-Signature: ' . hash_hmac('sha256', "WCN-RESPONSE\n" . $raw, hex2bin($key))); }
        echo $raw;
        exit;
    }

    private static function run_native($account) {
        // Only the validated ROOT registry supplies this account. No shell here.
        $argv = ['/usr/bin/timeout', '--signal=TERM', '--kill-after=2s', '15s',
            '/usr/local/emps/bin/php', '/usr/local/webuzo/cli.php', '--clear_cache', '--users=' . $account];
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/root', 'LANG' => 'C']);
        if (!is_resource($process)) { throw new RuntimeException('native_failed'); }
        stream_set_blocking($pipes[1], false);
        $output = ''; $oversize = false;
        do {
            $chunk = stream_get_contents($pipes[1]);
            if (strlen($output) + strlen($chunk) > 4096) { $oversize = true; }
            $output = substr($output . $chunk, 0, 4096);
            $status = proc_get_status($process);
            if ($status['running']) { usleep(20000); }
        } while ($status['running']);
        $output .= stream_get_contents($pipes[1], max(1, 4097 - strlen($output)));
        fclose($pipes[1]);
        $closed = proc_close($process);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        // Native CLI can return zero even when refusing access. Do not trust zero alone.
        if ($exit !== 0 || $oversize || trim($output) !== '') { throw new RuntimeException('native_failed'); }
    }

    public static function serve() {
        $key = null; $answer = ['v' => 1, 'ok' => false, 'code' => 'not_allowed'];
        $accountLock = null; $slot = null; $lifecycle = null;
        try {
            if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 ||
                !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { throw new RuntimeException('not_allowed'); }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
                ($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/json' ||
                !isset($_SERVER['CONTENT_LENGTH']) || !ctype_digit((string) $_SERVER['CONTENT_LENGTH']) ||
                (int) $_SERVER['CONTENT_LENGTH'] > 512) { throw new RuntimeException('bad_request'); }
            $lifecyclePath = '/var/lib/webuzo-clean-nginx/lifecycle.lock';
            self::trusted($lifecyclePath);
            $lifecycle = fopen($lifecyclePath, 'r');
            if (!$lifecycle || !flock($lifecycle, LOCK_SH | LOCK_NB)) { throw new RuntimeException('busy'); }
            self::trusted(self::CONFIG);
            if (filesize(self::CONFIG) > 1048576) { throw new RuntimeException('bad_registry'); }
            $config = json_decode(file_get_contents(self::CONFIG), true);
            if (!is_array($config) || ($config['v'] ?? null) !== 1 || !isset($config['keys']) || !is_array($config['keys'])) {
                throw new RuntimeException('bad_registry');
            }
            $raw = file_get_contents('php://input', false, null, 0, 513);
            if (strlen($raw) !== (int) $_SERVER['CONTENT_LENGTH']) { throw new RuntimeException('bad_request'); }
            list($r, $entry) = self::authenticate($raw, $_SERVER['HTTP_X_WCN_SIGNATURE'] ?? '', $config['keys'], time());
            $key = $entry['key'];
            $answer += ['nonce' => $r['nonce'], 'account' => $entry['account'], 'scope' => 'account', 'op' => $r['op'], 'backend' => 'webuzo-native'];
            $identity = posix_getpwuid($entry['uid']);
            if (!$identity || $identity['name'] !== $entry['account'] || $identity['gid'] !== $entry['gid'] ||
                !hash_equals($entry['generation'], self::native_generation($entry['account']))) {
                throw new RuntimeException('identity_changed');
            }
            self::trusted(self::STATE, true);
            $path = self::STATE . '/' . $r['id'] . '.json';
            self::trusted($path);
            $accountLock = fopen($path, 'r+');
            if (!$accountLock || !flock($accountLock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('busy'); }
            $state = json_decode(stream_get_contents($accountLock, 16385), true);
            if (!is_array($state) || !isset($state['nonces'], $state['last_purge'], $state['last_request']) || !is_array($state['nonces'])) {
                throw new RuntimeException('bad_state');
            }
            $now = time();
            $state['nonces'] = array_filter($state['nonces'], function ($ts) use ($now) { return is_int($ts) && $ts >= $now - 65; });
            if (isset($state['nonces'][$r['nonce']])) { throw new RuntimeException('replayed'); }
            if ($now - $state['last_request'] < 2 || ($r['op'] === 'purge' && $now - $state['last_purge'] < 10)) {
                throw new RuntimeException('rate_limited');
            }
            $state['nonces'][$r['nonce']] = $now;
            $state['last_request'] = $now;
            if ($r['op'] === 'purge') {
                // At most two native clear jobs across the server; no persistent daemon.
                for ($i = 0; $i < 2; ++$i) {
                    $slotPath = self::STATE . '/slot-' . $i;
                    self::trusted($slotPath);
                    $candidate = fopen($slotPath, 'r+');
                    if ($candidate && flock($candidate, LOCK_EX | LOCK_NB)) { $slot = $candidate; break; }
                    if ($candidate) { fclose($candidate); }
                }
                if (!$slot) { throw new RuntimeException('busy'); }
                $cache = '/var/webuzo-data/nginx_proxy_cache/' . $entry['account'];
                if (realpath($cache) !== $cache || !is_dir($cache)) { throw new RuntimeException('cache_unavailable'); }
                $state['last_purge'] = $now;
            }
            rewind($accountLock);
            if (!ftruncate($accountLock, 0) || fwrite($accountLock, json_encode($state)) === false || !fflush($accountLock)) {
                throw new RuntimeException('bad_state');
            }
            if ($r['op'] === 'purge') { self::run_native($entry['account']); }
            $answer['ok'] = true;
            $answer['code'] = $r['op'] === 'purge' ? 'native_completed' : 'ready';
        } catch (Throwable $e) {
            $allowed = ['not_allowed', 'bad_request', 'expired', 'identity_changed', 'busy', 'replayed', 'rate_limited', 'cache_unavailable', 'native_failed'];
            $answer['code'] = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'unavailable';
            if ($answer['code'] === 'unavailable' || $answer['code'] === 'native_failed') { error_log('Webuzo Clean Nginx: ' . $answer['code']); }
        } finally {
            if (is_resource($slot)) { fclose($slot); }
            if (is_resource($accountLock)) { fclose($accountLock); }
            if (is_resource($lifecycle)) { fclose($lifecycle); }
        }
        self::respond($answer, $key, $answer['ok'] ? 200 : 403);
    }
}
