<?php
/**
 * Local, non-secret plugin preferences.
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validated settings shared by the UI, automation and CLI. */
final class Settings {

	const OPTION = 'clear_nginx_for_webuzo_settings';

	/**
	 * Default preferences, including safe upgrade behavior.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'automatic_purge' => false,
			'purge_comments'  => false,
			'show_toolbar'    => true,
			'toolbar_label'   => '',
		);
	}

	/**
	 * Read supported preferences only.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() ) );
	}

	/** Register the WordPress Settings API entry. */
	public static function register() {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize a complete settings form, never accepting transport credentials.
	 *
	 * @param mixed $input Submitted preferences.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$result = self::defaults();
		foreach ( array( 'automatic_purge', 'purge_comments', 'show_toolbar' ) as $key ) {
			$result[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( true, 1, '1' ), true );
		}
		$result['toolbar_label'] = isset( $input['toolbar_label'] ) && is_string( $input['toolbar_label'] ) ? wp_html_excerpt( sanitize_text_field( $input['toolbar_label'] ), 80, '' ) : '';
		return $result;
	}

	/**
	 * React after a real settings change, including changes from WP-CLI.
	 *
	 * @param mixed $old Previous preferences.
	 * @param mixed $updated New preferences.
	 */
	public static function changed( $old, $updated ) {
		if ( ! is_array( $updated ) || empty( $updated['automatic_purge'] ) ) {
			Auto_Purge::cancel();
		}
	}
}
