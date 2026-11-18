<?php
/**
 * Secret-free diagnostic information; reading it never makes a native request.
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reports saved, timestamped results rather than claiming a live connection. */
final class Site_Health {

	/**
	 * Add safe plugin information to Tools > Site Health > Info.
	 *
	 * @param array $info WordPress debug sections.
	 * @return array
	 */
	public static function information( $info ) {
		$settings                   = Settings::get();
		$last                       = get_option( 'clear_nginx_for_webuzo_last_result', array() );
		$automatic                  = get_option( 'clear_nginx_for_webuzo_auto_result', array() );
		$fields                     = array(
			'version'   => array(
				'label' => __( 'Version', 'clear-nginx-for-webuzo' ),
				'value' => Plugin::VERSION,
			),
			'scope'     => array(
				'label' => __( 'Cache scope', 'clear-nginx-for-webuzo' ),
				'value' => __( 'All websites in this hosting account', 'clear-nginx-for-webuzo' ),
			),
			'automatic' => array(
				'label' => __( 'Automatic purge', 'clear-nginx-for-webuzo' ),
				'value' => $settings['automatic_purge'] ? __( 'Enabled', 'clear-nginx-for-webuzo' ) : __( 'Disabled', 'clear-nginx-for-webuzo' ),
			),
			'comments'  => array(
				'label' => __( 'Purge on comment changes', 'clear-nginx-for-webuzo' ),
				'value' => $settings['purge_comments'] ? __( 'Enabled', 'clear-nginx-for-webuzo' ) : __( 'Disabled', 'clear-nginx-for-webuzo' ),
			),
			'pending'   => array(
				'label' => __( 'Pending automatic clear', 'clear-nginx-for-webuzo' ),
				'value' => get_option( Auto_Purge::PENDING ) ? __( 'Yes; requires working WP-Cron', 'clear-nginx-for-webuzo' ) : __( 'No', 'clear-nginx-for-webuzo' ),
			),
			'last'      => array(
				'label' => __( 'Last saved connection or clear result', 'clear-nginx-for-webuzo' ),
				'value' => $last['message'] ?? __( 'Not checked. Use Test connection in the plugin settings.', 'clear-nginx-for-webuzo' ),
			),
			'checked'   => array(
				'label' => __( 'Result time (UTC)', 'clear-nginx-for-webuzo' ),
				'value' => isset( $last['time'] ) ? gmdate( 'Y-m-d H:i:s', $last['time'] ) : __( 'Never', 'clear-nginx-for-webuzo' ),
			),
			'supported' => array(
				'label' => __( 'Installation support', 'clear-nginx-for-webuzo' ),
				'value' => is_multisite() ? __( 'Multisite is unsupported', 'clear-nginx-for-webuzo' ) : __( 'Single site', 'clear-nginx-for-webuzo' ),
			),
		);
		$fields['connector']        = array(
			'label' => __( 'Connector version from last successful request', 'clear-nginx-for-webuzo' ),
			'value' => $last['connector_version'] ?? __( 'Not reported by the connector', 'clear-nginx-for-webuzo' ),
		);
		$fields['automatic_result'] = array(
			'label' => __( 'Last automatic clear result', 'clear-nginx-for-webuzo' ),
			'value' => $automatic['message'] ?? __( 'No automatic clear recorded', 'clear-nginx-for-webuzo' ),
		);
		$info[ Plugin::SLUG ]       = array(
			'label'  => __( 'Clear Nginx for Webuzo', 'clear-nginx-for-webuzo' ),
			'fields' => $fields,
		);
		return $info;
	}
}
