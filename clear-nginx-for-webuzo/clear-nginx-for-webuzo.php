<?php
/**
 * Plugin Name: Clear Nginx for Webuzo
 * Plugin URI: https://github.com/orloxgr/webuzo-clean-nginx
 * Description: Clear account Nginx cache through Webuzo's native backend with a purge-only account credential.
 * Version: 0.5.7
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
require_once __DIR__ . '/includes/class-plugin.php';

Plugin::boot( __FILE__ );
