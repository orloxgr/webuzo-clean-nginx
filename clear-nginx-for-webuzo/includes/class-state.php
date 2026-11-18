<?php
/**
 * Atomic option updates for durable work and expiring worker ownership.
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Compare-and-swap prevents a worker deleting a newer content change. */
final class State {

	/**
	 * Replace or remove only the exact option value read by the caller.
	 *
	 * @param string $name Internal option name.
	 * @param mixed  $old Expected value.
	 * @param mixed  $replacement Replacement, or null to delete.
	 * @return bool Whether this caller changed the row.
	 */
	public static function compare( $name, $old, $replacement = null ) {
		global $wpdb;
		// The Options API has no atomic compare-and-swap. Exact values protect concurrent workers.
		if ( null === $replacement ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic conditional delete; cache invalidated below.
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s", $name, maybe_serialize( $old ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic conditional update; cache invalidated below.
			$count = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $replacement ), $name, maybe_serialize( $old ) ) );
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $count;
	}

	/**
	 * Acquire an expiring, site-local worker lock without stealing a new owner.
	 *
	 * @return array|false Lock ownership value, or false if busy.
	 */
	public static function lock() {
		global $wpdb;
		$name  = 'clear_nginx_for_webuzo_worker';
		$value = array(
			'token'   => wp_generate_uuid4(),
			'expires' => time() + 60,
		);
		// add_option() can upsert after a concurrent cached miss; ownership needs a true insert-only operation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert-only ownership claim; cache invalidated immediately below.
		$count = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, maybe_serialize( $value ) ) );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 === $count ) {
			return $value;
		}
		$old = get_option( $name );
		if ( is_array( $old ) && isset( $old['expires'] ) && $old['expires'] < time() && self::compare( $name, $old, $value ) ) {
			return $value;
		}
		return false;
	}
}
