<?php
/**
 * Plugin Name: Webuzo Site Cache
 * Plugin URI: https://github.com/orloxgr/webuzo-clean-nginx
 * Description: Clear account Nginx cache through Webuzo's native backend with a purge-only account credential.
 * Version: 0.5.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Byron Iniotakis
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/orloxgr/webuzo-clean-nginx
 * Text Domain: webuzo-site-cache
 * Domain Path: /languages
 *
 * @package WebuzoSiteCache
 */

namespace WebuzoSiteCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-native-panel.php';
require_once __DIR__ . '/includes/class-plugin.php';

Plugin::boot( __FILE__ );
