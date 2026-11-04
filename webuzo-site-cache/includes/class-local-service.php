<?php
namespace WebuzoSiteCache;

/** One fixed Unix socket; no credentials or caller-selected account/path. */
final class LocalService {
    const SOCKET = '/run/webuzo-cache-purge/control.sock';

    private function request($operation) {
        if (!in_array($operation, ['status', 'preview', 'purge'], true)) {
            throw new \RuntimeException('Unsupported local cache operation.');
        }
        $parent = dirname(self::SOCKET);
        clearstatcache(true, self::SOCKET);
        $dir = @lstat($parent);
        $sock = @lstat(self::SOCKET);
        if (!$dir || !$sock || ($dir['mode'] & 0170000) !== 0040000 || ($sock['mode'] & 0170000) !== 0140000 ||
            $dir['uid'] !== 0 || $sock['uid'] !== 0 || ($dir['mode'] & 0022) || realpath($parent) !== $parent) {
            throw new \RuntimeException('The trusted local cache service is not installed or is inaccessible. Ask the hosting administrator to install/enroll it. No API fallback was attempted.');
        }
        if (!function_exists('stream_socket_client')) { throw new \RuntimeException('PHP stream sockets are unavailable.'); }
        $stream = @stream_socket_client('unix://' . self::SOCKET, $errorNumber, $errorMessage, 3);
        if (!$stream) { throw new \RuntimeException('Cannot connect to the local cache service. Check service status, PHP restrictions and account enrollment.'); }
        try {
            stream_set_timeout($stream, 20);
            $payload = json_encode(['v' => 1, 'op' => $operation]) . "\n";
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($stream, substr($payload, $offset));
                if (!$written) { throw new \RuntimeException('Could not send the local cache request.'); }
                $offset += $written;
            }
            $line = fgets($stream, 8193);
            $metadata = stream_get_meta_data($stream);
            if ($metadata['timed_out']) { throw new \RuntimeException('The local cache request timed out. Its completion is unknown; inspect the service log before retrying.'); }
            if (!is_string($line) || substr($line, -1) !== "\n") { throw new \RuntimeException('The local cache service did not return a complete response.'); }
            $data = json_decode($line, true);
            if (!is_array($data) || !isset($data['v']) || $data['v'] !== 1 || !array_key_exists('ok', $data)) {
                throw new \RuntimeException('The local cache service returned an invalid protocol response.');
            }
            if ($data['ok'] !== true) {
                $messages = ['rate_limited' => 'Wait 10 seconds between cache scans for this hosting account.',
                    'busy' => 'The local cache service is busy. Try again shortly.',
                    'not_allowed' => 'This hosting account is not enrolled in the local cache service.',
                    'identity_changed' => 'The hosting administrator must review changed account identity.',
                    'cache_unavailable' => 'The account cache folder is missing or unsafe.'];
                $code = isset($data['code']) && is_string($data['code']) ? $data['code'] : '';
                throw new \RuntimeException(isset($messages[$code]) ? $messages[$code] : 'The local cache service rejected the request.');
            }
            if (empty($data['account']) || !is_string($data['account']) || !preg_match('/^[a-z_][a-z0-9_-]*$/iD', $data['account']) ||
                !isset($data['scope']) || $data['scope'] !== 'account') {
                throw new \RuntimeException('The local cache service did not confirm the account scope.');
            }
            if ($operation !== 'status') {
                if (!isset($data['result']) || !is_array($data['result'])) { throw new \RuntimeException('The local cache service did not return scan results.'); }
                foreach (['visited', 'scanned', 'matched', 'deleted', 'unknown', 'errors'] as $field) {
                    if (!isset($data['result'][$field]) || !is_int($data['result'][$field]) || $data['result'][$field] < 0) {
                        throw new \RuntimeException('The local cache service returned invalid counters.');
                    }
                }
                if (!isset($data['result']['complete']) || !is_bool($data['result']['complete'])) {
                    throw new \RuntimeException('The local cache service did not confirm scan completeness.');
                }
            }
            return $data;
        } finally { fclose($stream); }
    }

    public function check_site($homeUrl) { return $this->request('status'); }
    public function preview() { return $this->request('preview'); }
    public function purge($homeUrl) { return $this->request('purge'); }
}
