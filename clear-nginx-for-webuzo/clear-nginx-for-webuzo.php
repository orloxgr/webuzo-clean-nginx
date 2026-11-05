<?php
/**
 * Plugin Name: Clear Nginx for Webuzo
 * Plugin URI: https://github.com/orloxgr/webuzo-clean-nginx
 * Description: Clear account Nginx cache through Webuzo's native backend with a purge-only account credential.
 * Version: 0.5.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Byron Iniotakis
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/orloxgr/webuzo-clean-nginx
 * Text Domain: clear-nginx-for-webuzo
 * Domain Path: /languages
 *
 * @package WebuzoSiteCache
 */

namespace WebuzoSiteCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent a fatal class collision when an older installation is still active.
if ( class_exists( __NAMESPACE__ . '\\Plugin', false ) || class_exists( __NAMESPACE__ . '\\Native_Panel', false ) ) {
	wp_die(
		esc_html__( 'Deactivate the previous Webuzo Site Cache installation before activating Clear Nginx for Webuzo. Only one copy may be active.', 'clear-nginx-for-webuzo' ),
		'',
		array( 'response' => 409 )
	);
}

require_once __DIR__ . '/includes/class-native-panel.php';
require_once __DIR__ . '/includes/class-plugin.php';

Plugin::boot( __FILE__ );
