<?php
namespace WebuzoSiteCache;

/** Enduser API transport: HTTPS POST credentials, no redirect following. */
final class WebuzoApi {
    private $panel;
    private $user;
    private $key;

    public function __construct($panel, $user, $key) {
        $this->panel = self::normalize_panel($panel);
        if (!is_string($user) || !preg_match('/^[a-z_][a-z0-9_-]*$/iD', $user) || strtolower($user) === 'root') {
            throw new \RuntimeException('Enter the Webuzo hosting account username, not root.');
        }
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 512 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new \RuntimeException('Enter a valid Webuzo end-user API key.');
        }
        $this->user = $user;
        $this->key = $key;
    }

    public static function normalize_panel($panel) {
        $panel = is_string($panel) ? trim($panel) : '';
        $p = wp_parse_url($panel);
        if (!$p || empty($p['host']) || empty($p['scheme']) || strtolower($p['scheme']) !== 'https' ||
            isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            throw new \RuntimeException('Use the permanent HTTPS end-user panel URL with its port; no credentials, query string or session URL.');
        }
        if (preg_match('~(?:^|/)sess[a-z0-9]+(?:/|$)~i', isset($p['path']) ? $p['path'] : '') || preg_match('/[\x00-\x20\\\\]/', $panel)) {
            throw new \RuntimeException('Use the permanent end-user panel URL, not a temporary logged-in session URL.');
        }
        $panel = rtrim($panel, '/');
        if (substr($panel, -10) === '/index.php') { $panel = substr($panel, 0, -10); }
        return $panel;
    }

    private function request($action, $clear = false) {
        $url = $this->panel . '/index.php?api=json&act=' . rawurlencode($action) . ($clear ? '&clear_cache=1' : '');
        $response = wp_remote_post($url, ['timeout' => 25, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 2097152, 'headers' => ['Accept' => 'application/json'],
            'body' => ['apiuser' => $this->user, 'apikey' => $this->key]]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('Cannot connect securely to Webuzo. Check the panel hostname, HTTPS certificate, port and firewall.');
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            throw new \RuntimeException('Webuzo returned HTTP ' . (int)$status . '. Check the end-user panel URL, API credentials and allowed IP addresses. Redirects are not followed.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Webuzo did not return a JSON API response. Check the panel URL and API availability.');
        }
        if (!empty($data['error']) || !empty($data['errors'])) {
            throw new \RuntimeException('Webuzo rejected the API request. Check the account API key, IP restrictions and whether this action is allowed.');
        }
        return $data;
    }

    public function check_site($homeUrl) {
        $data = $this->request('domainmanage');
        if (empty($data['primary_domain']) || !is_string($data['primary_domain']) || !isset($data['domains_list']) || !is_array($data['domains_list'])) {
            throw new \RuntimeException('The response is not a supported Webuzo end-user domain list. No cache was cleared.');
        }
        $home = wp_parse_url($homeUrl);
        $host = $home && isset($home['host']) ? preg_replace('/^www\./', '', strtolower($home['host'])) : '';
        $domains = array_merge([$data['primary_domain']], array_keys($data['domains_list']));
        $domains = array_map(static function ($domain) { return preg_replace('/^www\./', '', strtolower((string)$domain)); }, $domains);
        if (!$host || !in_array($host, $domains, true)) {
            throw new \RuntimeException('This WordPress site is not listed in the configured Webuzo account. No cache was cleared.');
        }
        return ['account' => $this->user, 'primary_domain' => $data['primary_domain'], 'domain_count' => count(array_unique($domains))];
    }

    public function purge($homeUrl) {
        $account = $this->check_site($homeUrl);
        $data = $this->request('nginx_proxy', true);
        if (empty($data['done']) || !is_array($data['done']) || empty($data['done']['msg']) || !is_string($data['done']['msg'])) {
            throw new \RuntimeException('Webuzo did not confirm cache clearing. The nginx_proxy clear-cache API may be unavailable on this server.');
        }
        return $account;
    }
}

final class Secret {
    public static function encrypt($key) {
        if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('PHP OpenSSL is required to store an API key.'); }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) { throw new \RuntimeException('Could not encrypt the API key.'); }
        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt($stored) {
        if (!function_exists('openssl_decrypt')) { throw new \RuntimeException('PHP OpenSSL is required to read the stored API key.'); }
        $raw = is_string($stored) ? base64_decode($stored, true) : false;
        if ($raw === false || strlen($raw) < 29) { throw new \RuntimeException('Save a Webuzo end-user API key first.'); }
        $key = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($key === false) { throw new \RuntimeException('Cannot decrypt the API key. Re-enter it after changing WordPress authentication salts.'); }
        return $key;
    }
}
