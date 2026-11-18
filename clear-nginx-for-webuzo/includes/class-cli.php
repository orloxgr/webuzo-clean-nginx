<?php
/**
 * WP-CLI commands using the same native connection and preferences.
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manage the hosting account's Nginx cache. Use --user with an administrator. */
final class CLI {

	/** Require the same site support and administrator authority as the UI. */
	private function authorize() {
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( __( 'Use a single-site installation and --user with a WordPress administrator.', 'clear-nginx-for-webuzo' ) );
		}
	}

	/**
	 * Clear Nginx cache for ALL websites in this hosting account.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --user=admin webuzo-cache purge
	 */
	public function purge() {
		$this->authorize();
		$result = Cache_Service::run( 'purge' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( __( 'Nginx cache cleared for all websites in this hosting account.', 'clear-nginx-for-webuzo' ) );
	}

	/** Check the native connection without clearing cache. */
	public function status() {
		$this->authorize();
		$result = Cache_Service::run( 'status' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line(
			wp_json_encode(
				array(
					'account'           => $result['account'],
					'scope'             => $result['scope'],
					'backend'           => $result['backend'],
					'connector_version' => $result['connector_version'] ?? null,
					'capabilities'      => $result['capabilities'] ?? array( 'status', 'purge' ),
				)
			)
		);
		\WP_CLI::success( __( 'Connection confirmed; no cache was cleared.', 'clear-nginx-for-webuzo' ) );
	}

	/**
	 * Read or set one local preference; omit arguments to list all preferences.
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : automatic_purge, purge_comments, show_toolbar or toolbar_label.
	 *
	 * [<value>]
	 * : Boolean preferences accept true, false, 1 or 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --user=admin webuzo-cache settings
	 *     wp --user=admin webuzo-cache settings automatic_purge true
	 *     wp --user=admin webuzo-cache settings toolbar_label "Clear Cache"
	 *
	 * @param array $args Positional arguments.
	 */
	public function settings( $args ) {
		$this->authorize();
		$settings = Settings::get();
		if ( ! $args ) {
			\WP_CLI::line( wp_json_encode( $settings ) );
			return;
		}
		if ( count( $args ) > 2 || ! array_key_exists( $args[0], $settings ) ) {
			\WP_CLI::error( __( 'Unknown setting. Use webuzo-cache settings to list supported names.', 'clear-nginx-for-webuzo' ) );
		}
		if ( 1 === count( $args ) ) {
			\WP_CLI::line( wp_json_encode( $settings[ $args[0] ] ) );
			return;
		}
		$value = $args[1];
		if ( 'toolbar_label' !== $args[0] ) {
			if ( ! in_array( $value, array( 'true', 'false', '1', '0' ), true ) ) {
				\WP_CLI::error( __( 'Boolean settings accept true, false, 1 or 0.', 'clear-nginx-for-webuzo' ) );
			}
			$value = in_array( $value, array( 'true', '1' ), true );
		}
		$settings[ $args[0] ] = $value;
		update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
		\WP_CLI::success( __( 'Setting saved.', 'clear-nginx-for-webuzo' ) );
	}
}
