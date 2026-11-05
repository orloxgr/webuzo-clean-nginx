<?php
/**
 * Account-scoped client for the native Webuzo bridge.
 *
 * @package WebuzoSiteCache
 */

namespace WebuzoSiteCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Signs fixed loopback requests without a general panel API key. */
final class Native_Panel {

	/**
	 * Read the credential provisioned for the current Unix account.
	 *
	 * @return array Validated credential.
	 * @throws \RuntimeException When the credential is unavailable or unsafe.
	 */
	private function credentials() {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'PHP POSIX support is required for the native panel connection.', 'clear-nginx-for-webuzo' ) );
		}
		$uid = posix_geteuid();
		if ( $uid < 1000 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'WordPress must run as its own hosting account.', 'clear-nginx-for-webuzo' ) );
		}
		$dir  = '/var/lib/webuzo-clean-nginx/clients';
		$path = $dir . '/' . $uid . '.json';
		clearstatcache( true, $path );
		// Atomic provider enrollment/removal can race a read; report a controlled error.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing private credential must not expose filesystem warnings.
		$stat = @lstat( $path );
		if ( realpath( $dir ) !== $dir || ! $stat || ( $stat['mode'] & 0170000 ) !== 0100000 || 0 !== $stat['uid'] ||
			( $stat['mode'] & 0027 ) || 1 !== $stat['nlink'] || $stat['size'] > 1024 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'The hosting administrator must activate Webuzo Clean Nginx. If this account or its Nginx cache is new, wait for the next enrollment sync, scheduled every 10 minutes, then retry.', 'clear-nginx-for-webuzo' ) );
		}
		// Local, root-owned capability file; never a URL or user-selected path.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Direct read preserves OS account isolation and handles credential rotation safely.
		$raw   = @file_get_contents( $path );
		$value = json_decode( (string) $raw, true );
		if ( ! is_array( $value ) || ( $value['v'] ?? null ) !== 1 || ! isset( $value['id'], $value['key'] ) ||
			! is_string( $value['id'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value['id'] ) ||
			! is_string( $value['key'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value['key'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'The native panel credential is unavailable or invalid. Wait for the next enrollment sync (every 10 minutes); if it persists, ask the hosting administrator to check account eligibility.', 'clear-nginx-for-webuzo' ) );
		}
		return $value;
	}

	/**
	 * Perform a bounded request and verify the exact signed response bytes.
	 *
	 * @param string $operation Either status or purge.
	 * @return array Authenticated native result.
	 * @throws \RuntimeException For rejected or unconfirmed requests.
	 */
	private function request( $operation ) {
		if ( ! in_array( $operation, array( 'status', 'purge' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'Unsupported native panel operation.', 'clear-nginx-for-webuzo' ) );
		}
		$credential = $this->credentials();
		$nonce      = bin2hex( random_bytes( 16 ) );
		$body       = wp_json_encode(
			array(
				'v'     => 1,
				'op'    => $operation,
				'id'    => $credential['id'],
				'ts'    => time(),
				'nonce' => $nonce,
			),
			JSON_UNESCAPED_SLASHES
		);
		$signature  = hash_hmac( 'sha256', "WCN-REQUEST\n" . $body, hex2bin( $credential['key'] ) );
		// A fixed loopback URL is intentional. Never accept a caller-selected URL.
		$response = wp_remote_post(
			'http://127.0.0.1:2002/index.php?act=webuzo-clean-nginx-bridge',
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'httpversion'         => '1.0',
				'blocking'            => true,
				'cookies'             => array(),
				'decompress'          => false,
				'limit_response_size' => 8193,
				'headers'             => array(
					'Content-Type'    => 'application/json',
					'Accept-Encoding' => 'identity',
					'X-WCN-Signature' => $signature,
				),
				'body'                => $body,
				'data_format'         => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'The local Webuzo request failed or timed out; completion is unknown. Check the panel before retrying.', 'clear-nginx-for-webuzo' ) );
		}
		$raw       = wp_remote_retrieve_body( $response );
		$length    = wp_remote_retrieve_header( $response, 'content-length' );
		$signature = wp_remote_retrieve_header( $response, 'x-wcn-signature' );
		if ( ! is_string( $raw ) || strlen( $raw ) > 8192 || ! is_string( $length ) || ! ctype_digit( $length ) ||
			strlen( $raw ) !== (int) $length || ! is_string( $signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ||
			wp_remote_retrieve_header( $response, 'transfer-encoding' ) || wp_remote_retrieve_header( $response, 'content-encoding' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'The panel did not return a complete authenticated cache response. Check plugin activation and account enrollment.', 'clear-nginx-for-webuzo' ) );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', "WCN-RESPONSE\n" . $raw, hex2bin( $credential['key'] ) ), $signature ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'The native panel response signature is invalid.', 'clear-nginx-for-webuzo' ) );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ( $data['v'] ?? null ) !== 1 || ( $data['nonce'] ?? '' ) !== $nonce ||
			( $data['op'] ?? '' ) !== $operation || ( $data['scope'] ?? '' ) !== 'account' ||
			( $data['backend'] ?? '' ) !== 'webuzo-native' || ! isset( $data['account'] ) ||
			! is_string( $data['account'] ) || ! preg_match( '/^[a-z_][a-z0-9_-]{0,31}$/iD', $data['account'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( __( 'The native panel did not confirm this request and its account scope.', 'clear-nginx-for-webuzo' ) );
		}
		if ( ( $data['ok'] ?? null ) !== true || 200 !== wp_remote_retrieve_response_code( $response ) ||
			( $data['code'] ?? '' ) !== ( 'purge' === $operation ? 'native_completed' : 'ready' ) ) {
			$messages = array(
				'rate_limited'      => __( 'Wait 2 seconds between connection checks and 10 seconds between cache clears.', 'clear-nginx-for-webuzo' ),
				'busy'              => __( 'A cache clear is already running. Try again shortly.', 'clear-nginx-for-webuzo' ),
				'identity_changed'  => __( 'The hosting administrator must refresh enrollment after an account identity change.', 'clear-nginx-for-webuzo' ),
				'native_failed'     => __( 'The native Webuzo clear command did not complete normally. Check the panel log; cache clearing is not confirmed.', 'clear-nginx-for-webuzo' ),
				'cache_unavailable' => __( 'Account Nginx caching or reverse proxy is disabled, or its cache directory is missing or unsafe.', 'clear-nginx-for-webuzo' ),
				'replayed'          => __( 'The panel rejected a repeated request. Try again with a fresh request.', 'clear-nginx-for-webuzo' ),
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions carry plain text; Plugin::page() escapes the final notice.
			throw new \RuntimeException( $messages[ $data['code'] ?? '' ] ?? __( 'The native panel rejected the cache request.', 'clear-nginx-for-webuzo' ) );
		}
		return $data;
	}

	/**
	 * Check account enrollment without clearing cache.
	 *
	 * @return array Authenticated account status.
	 */
	public function check_connection() {
		return $this->request( 'status' );
	}

	/**
	 * Request an account-wide native cache clear.
	 *
	 * @return array Authenticated native completion result.
	 */
	public function purge() {
		return $this->request( 'purge' );
	}
}
