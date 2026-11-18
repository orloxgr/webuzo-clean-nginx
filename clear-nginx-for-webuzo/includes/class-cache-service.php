<?php
/**
 * Shared native operations and safe diagnostic snapshots.
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Routes all callers through the same authenticated native client. */
final class Cache_Service {

	/**
	 * Run an operation; never persist credentials or raw native responses.
	 *
	 * @param string $operation Status or purge.
	 * @return array|\WP_Error
	 */
	public static function run( $operation ) {
		if ( ! in_array( $operation, array( 'status', 'purge' ), true ) ) {
			return new \WP_Error( 'clear_nginx_for_webuzo_unsupported_operation', __( 'Unsupported native panel operation.', 'clear-nginx-for-webuzo' ) );
		}
		$client = new Native_Panel();
		try {
			$result = 'status' === $operation ? $client->check_connection() : $client->purge();
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'clear_nginx_for_webuzo_unconfirmed', __( 'The cache request could not be confirmed. Check the connection before retrying.', 'clear-nginx-for-webuzo' ) );
		}
		$snapshot = array(
			'time'      => time(),
			'operation' => $operation,
			'ok'        => ! is_wp_error( $result ),
		);
		if ( is_wp_error( $result ) ) {
			$snapshot['message'] = $result->get_error_message();
		} else {
			$snapshot['account'] = $result['account'];
			$snapshot['message'] = 'status' === $operation ? __( 'Connection confirmed.', 'clear-nginx-for-webuzo' ) : __( 'Account cache cleared.', 'clear-nginx-for-webuzo' );
			if ( isset( $result['connector_version'] ) && is_string( $result['connector_version'] ) && preg_match( '/^[0-9.]{1,32}$/D', $result['connector_version'] ) ) {
				$snapshot['connector_version'] = $result['connector_version'];
			}
		}
		update_option( 'clear_nginx_for_webuzo_last_result', $snapshot, false );
		return $result;
	}
}
