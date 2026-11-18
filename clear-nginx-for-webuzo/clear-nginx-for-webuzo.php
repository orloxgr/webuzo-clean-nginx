<?php
/**
 * Plugin Name: Clear Nginx for Webuzo
 * Plugin URI: https://github.com/orloxgr/webuzo-clean-nginx
 * Description: Clear your hosting account's Nginx cache manually or automatically after public site changes.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Byron Iniotakis
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clear-nginx-for-webuzo
 * Domain Path: /languages
 *
 * @package ClearNginxForWebuzo
 */

namespace ClearNginxForWebuzo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Reject a duplicate loaded copy before declaring the plugin classes.
if ( class_exists( __NAMESPACE__ . '\\Plugin', false ) || class_exists( __NAMESPACE__ . '\\Native_Panel', false ) ) {
	wp_die(
		esc_html__( 'Only one copy of Clear Nginx for Webuzo may be active. Deactivate the duplicate installation before continuing.', 'clear-nginx-for-webuzo' ),
		'',
		array( 'response' => 409 )
	);
}

require_once __DIR__ . '/includes/class-native-panel.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-state.php';
require_once __DIR__ . '/includes/class-cache-service.php';
require_once __DIR__ . '/includes/class-auto-purge.php';
require_once __DIR__ . '/includes/class-site-health.php';
require_once __DIR__ . '/includes/class-plugin.php';

Plugin::boot( __FILE__ );
Auto_Purge::boot();
register_deactivation_hook( __FILE__, array( Auto_Purge::class, 'cancel' ) );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
	\WP_CLI::add_command( 'webuzo-cache', CLI::class );
}
