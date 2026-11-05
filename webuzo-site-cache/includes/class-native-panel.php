<?php
namespace WebuzoSiteCache;

/** Only signed, fixed loopback requests; no panel password or broad API key. */
final class NativePanel {
    private $deadline;

    private function budget($stream) {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) { throw new \RuntimeException('The native cache request timed out; completion is unknown. Check the panel before retrying.'); }
        $seconds = (int) $remaining;
        stream_set_timeout($stream, $seconds, (int) (($remaining - $seconds) * 1000000));
    }

    private function credentials() {
        if (!function_exists('posix_geteuid')) { throw new \RuntimeException('PHP POSIX support is required for the native panel connection.'); }
        $uid = posix_geteuid();
        if ($uid < 1000) { throw new \RuntimeException('WordPress must run as its own hosting account.'); }
        $dir = '/var/lib/webuzo-clean-nginx/clients';
        $path = $dir . '/' . $uid . '.json';
        clearstatcache(true, $path);
        $s = @lstat($path);
        if (realpath($dir) !== $dir || !$s || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== 0 ||
            ($s['mode'] & 0027) || $s['nlink'] !== 1 || $s['size'] > 1024) {
            throw new \RuntimeException('The hosting administrator must activate Webuzo Clean Nginx and enroll this account.');
        }
        $value = json_decode((string) @file_get_contents($path), true);
        if (!is_array($value) || ($value['v'] ?? null) !== 1 || !isset($value['id'], $value['key']) ||
            !is_string($value['id']) || !preg_match('/^[a-f0-9]{32}$/D', $value['id']) ||
            !is_string($value['key']) || !preg_match('/^[a-f0-9]{64}$/D', $value['key'])) {
            throw new \RuntimeException('The native panel credential is unavailable or invalid. Ask the hosting administrator to refresh enrollment.');
        }
        return $value;
    }

    private function request($operation) {
        if (!in_array($operation, ['status', 'purge'], true)) { throw new \RuntimeException('Unsupported native panel operation.'); }
        $c = $this->credentials();
        $nonce = bin2hex(random_bytes(16));
        $body = json_encode(['v' => 1, 'op' => $operation, 'id' => $c['id'], 'ts' => time(), 'nonce' => $nonce], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', "WCN-REQUEST\n" . $body, hex2bin($c['key']));
        $this->deadline = microtime(true) + 20;
        $stream = @stream_socket_client('tcp://127.0.0.1:2002', $errno, $error, 3);
        if (!$stream) { throw new \RuntimeException('Cannot connect to the local Webuzo panel on port 2002. No API fallback was attempted.'); }
        try {
            $this->budget($stream);
            $wire = "POST /index.php?act=webuzo-clean-nginx-bridge HTTP/1.0\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\nAccept-Encoding: identity\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\nX-WCN-Signature: " . $signature . "\r\n\r\n" . $body;
            for ($offset = 0; $offset < strlen($wire); $offset += $written) {
                $this->budget($stream);
                $written = fwrite($stream, substr($wire, $offset));
                if (!$written) { throw new \RuntimeException('Could not send the native cache request.'); }
            }
            $this->budget($stream);
            $line = fgets($stream, 1025);
            if (!is_string($line) || !preg_match('~^HTTP/1\.[01] ([0-9]{3}) ~', $line, $matches)) { throw new \RuntimeException('Invalid native panel response.'); }
            $status = (int) $matches[1]; $headers = []; $bytes = strlen($line);
            while (($line = fgets($stream, 1025)) !== false && $line !== "\r\n") {
                $bytes += strlen($line);
                if ($bytes > 8192 || substr($line, -2) !== "\r\n" || strpos($line, ':') === false) { throw new \RuntimeException('Invalid native panel headers.'); }
                list($name, $value) = explode(':', $line, 2);
                $name = strtolower(trim($name));
                if (isset($headers[$name]) && in_array($name, ['content-length', 'x-wcn-signature', 'transfer-encoding'], true)) {
                    throw new \RuntimeException('Ambiguous native panel response.');
                }
                $headers[$name] = trim($value);
                $this->budget($stream);
            }
            if ($line !== "\r\n" || isset($headers['transfer-encoding']) || !isset($headers['content-length'], $headers['x-wcn-signature']) ||
                !ctype_digit($headers['content-length']) || (int) $headers['content-length'] > 8192 ||
                !preg_match('/^[a-f0-9]{64}$/D', $headers['x-wcn-signature'])) { throw new \RuntimeException('The panel did not return an authenticated cache response. Check plugin activation and account enrollment.'); }
            $length = (int) $headers['content-length']; $raw = '';
            while (strlen($raw) < $length && !feof($stream)) {
                $this->budget($stream);
                $chunk = fread($stream, $length - strlen($raw));
                if ($chunk === false || $chunk === '') { break; }
                $raw .= $chunk;
            }
            $meta = stream_get_meta_data($stream);
            if ($meta['timed_out'] || strlen($raw) !== $length) { throw new \RuntimeException('Native cache request completion is unknown. Check the panel before retrying.'); }
            if (!hash_equals(hash_hmac('sha256', "WCN-RESPONSE\n" . $raw, hex2bin($c['key'])), $headers['x-wcn-signature'])) {
                throw new \RuntimeException('The native panel response signature is invalid.');
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || ($data['v'] ?? null) !== 1 || ($data['nonce'] ?? '') !== $nonce ||
                ($data['op'] ?? '') !== $operation || ($data['scope'] ?? '') !== 'account' ||
                ($data['backend'] ?? '') !== 'webuzo-native' || !isset($data['account']) ||
                !is_string($data['account']) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/iD', $data['account'])) {
                throw new \RuntimeException('The native panel did not confirm this request and its account scope.');
            }
            if (($data['ok'] ?? null) !== true || $status !== 200 || ($data['code'] ?? '') !== ($operation === 'purge' ? 'native_completed' : 'ready')) {
                $messages = ['rate_limited' => 'Wait 2 seconds between connection checks and 10 seconds between cache clears.',
                    'busy' => 'A cache clear is already running. Try again shortly.',
                    'identity_changed' => 'The hosting administrator must refresh enrollment after an account identity change.',
                    'native_failed' => 'The native Webuzo clear command did not complete normally. Check the panel log; cache clearing is not confirmed.',
                    'cache_unavailable' => 'The account cache directory is missing or unsafe.',
                    'replayed' => 'The panel rejected a repeated request. Try again with a fresh request.'];
                throw new \RuntimeException($messages[$data['code'] ?? ''] ?? 'The native panel rejected the cache request.');
            }
            return $data;
        } finally { fclose($stream); }
    }

    public function check_site($homeUrl) { return $this->request('status'); }
    public function purge($homeUrl) { return $this->request('purge'); }
}
