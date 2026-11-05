<?php
/**
 * WordPress administrator integration.
 *
 * @package WebuzoSiteCache
 */

namespace WebuzoSiteCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress administrator UI and authorized manual actions. */
final class Plugin {

	const VERSION = '0.5.4';
	const OPTION  = 'webuzo_site_cache_api';
	const SLUG    = 'clear-nginx-for-webuzo';

	/**
	 * Main plugin file used for assets and translations.
	 *
	 * @var string
	 */
	private static $plugin_file;

	/**
	 * Register WordPress integration hooks.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public static function boot( $plugin_file ) {
		self::$plugin_file = $plugin_file;
		add_action( 'init', array( __CLASS__, 'translations' ) );
		add_action( 'admin_init', array( __CLASS__, 'retire_legacy_credentials' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'bar' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_post_webuzo_site_cache', array( __CLASS__, 'handle' ) );
	}

	/** Load bundled translations after WordPress initializes. */
	public static function translations() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- GitHub-distributed translations need a local language path; this plugin is not hosted on WordPress.org.
		load_plugin_textdomain( 'clear-nginx-for-webuzo', false, dirname( plugin_basename( self::$plugin_file ) ) . '/languages' );
	}

	/** Load toolbar styles only for an authorized, visible toolbar. */
	public static function styles() {
		if ( current_user_can( 'manage_options' ) && ! is_multisite() && is_admin_bar_showing() ) {
			wp_enqueue_style( 'clear-nginx-for-webuzo', plugins_url( 'assets/admin-bar.css', self::$plugin_file ), array( 'admin-bar' ), self::VERSION );
		}
	}

	/** Remove settings left by the retired general API transport. */
	public static function retire_legacy_credentials() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$old = get_option( self::OPTION, array() );
		if ( is_array( $old ) && array_intersect( array( 'key', 'panel', 'user', 'transport' ), array_keys( $old ) ) ) {
			delete_option( self::OPTION );
			if ( ! empty( $old['key'] ) ) {
				set_transient(
					'webuzo_site_cache_notice_' . get_current_user_id(),
					array(
						'type' => 'warning',
						'text' => __( 'Legacy API settings were removed from WordPress. Revoke the previous account API key in Webuzo as well; deleting its saved copy does not revoke it.', 'clear-nginx-for-webuzo' ),
					),
					120
				);
			}
		}
	}

	/** Register the tools page with an administrator capability. */
	public static function menu() {
		add_management_page( __( 'Clear Nginx for Webuzo', 'clear-nginx-for-webuzo' ), __( 'Clear Nginx for Webuzo', 'clear-nginx-for-webuzo' ), 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	/**
	 * Add an account-wide POST action and settings link to the toolbar.
	 *
	 * @param \WP_Admin_Bar $bar WordPress toolbar.
	 */
	public static function bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => self::SLUG,
				'title' => esc_html__( 'Clear Cache', 'clear-nginx-for-webuzo' ),
				'href'  => admin_url( 'tools.php?page=' . self::SLUG ),
			)
		);
		$form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="clear-nginx-cache-form">'
			. '<input type="hidden" name="action" value="webuzo_site_cache">'
			. '<input type="hidden" name="operation" value="purge">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'webuzo_site_cache_purge' ) ) . '">'
			. '<button type="submit" class="clear-nginx-cache-button" title="' . esc_attr__( 'Clears Nginx cache for all websites in this hosting account.', 'clear-nginx-for-webuzo' ) . '">'
			. esc_html__( 'Clear Cache', 'clear-nginx-for-webuzo' ) . '</button></form>';
		$bar->add_node(
			array(
				'id'     => self::SLUG . '-purge',
				'parent' => self::SLUG,
				'title'  => $form,
			)
		);
		$bar->add_node(
			array(
				'id'     => self::SLUG . '-settings',
				'parent' => self::SLUG,
				'title'  => esc_html__( 'Settings', 'clear-nginx-for-webuzo' ),
				'href'   => admin_url( 'tools.php?page=' . self::SLUG ),
			)
		);
	}

	/**
	 * Open a POST form with its operation-specific CSRF token.
	 *
	 * @param string $operation Valid internal action.
	 */
	private static function start_form( $operation ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="webuzo_site_cache"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'webuzo_site_cache_' . $operation );
	}

	/** Render the account cache tools page. */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Clear Nginx for Webuzo', 'clear-nginx-for-webuzo' ) . '</h1>';
		if ( is_multisite() ) {
			echo '<p>' . esc_html__( 'This release supports single-site WordPress only.', 'clear-nginx-for-webuzo' ) . '</p></div>';
			return;
		}
		$notice = get_transient( 'webuzo_site_cache_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && isset( $notice['type'], $notice['text'] ) ) {
			delete_transient( 'webuzo_site_cache_notice_' . get_current_user_id() );
			$type = in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'error';
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'The Native Webuzo bridge uses the hosting provider’s existing panel to clear cache. The companion Webuzo plugin provisions a separate credential for each hosting account. No panel password or general API key is needed.', 'clear-nginx-for-webuzo' ) . '</p>';
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'This plugin clears the Nginx cache for all websites in this hosting account.', 'clear-nginx-for-webuzo' ) . '</strong></p></div>';
		echo '<h2>' . esc_html__( 'Check connection', 'clear-nginx-for-webuzo' ) . '</h2><p>' . esc_html__( 'Checks which hosting account will be used. This action does not clear cache.', 'clear-nginx-for-webuzo' ) . '</p>';
		self::start_form( 'connection' );
		submit_button( __( 'Test connection', 'clear-nginx-for-webuzo' ), 'secondary' );
		echo '</form><h2>' . esc_html__( 'Clear account cache', 'clear-nginx-for-webuzo' ) . '</h2>';
		self::start_form( 'purge' );
		submit_button( __( 'Clear Nginx cache for this account', 'clear-nginx-for-webuzo' ), 'primary' );
		echo '</form><p>' . esc_html__( 'Wait 2 seconds between connection checks and 10 seconds between cache clears.', 'clear-nginx-for-webuzo' ) . '</p>';
		echo '<p>' . esc_html__( 'New accounts become available after the provider’s next enrollment sync, scheduled every 10 minutes.', 'clear-nginx-for-webuzo' ) . '</p>';
		echo '<p>' . esc_html__( 'Manual account-wide clearing through Webuzo. No automatic purge or change to whether caching is enabled.', 'clear-nginx-for-webuzo' ) . '</p></div>';
	}

	/**
	 * Authorize and process a connection check or manual cache clear.
	 *
	 * @throws \RuntimeException Caught locally and escaped on the tools page.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage this site’s cache.', 'clear-nginx-for-webuzo' ), '', array( 'response' => 403 ) );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Use the cache management form.', 'clear-nginx-for-webuzo' ), '', array( 'response' => 405 ) );
		}
		// The exact safelist below rejects malformed input before any operation.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact type-checked safelist validation follows immediately.
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? wp_unslash( $_POST['operation'] ) : '';
		if ( ! in_array( $operation, array( 'connection', 'purge' ), true ) ) {
			wp_die( esc_html__( 'Unknown operation.', 'clear-nginx-for-webuzo' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'webuzo_site_cache_' . $operation );
		try {
			if ( is_multisite() ) {
				throw new \RuntimeException( __( 'Multisite is not supported by this release.', 'clear-nginx-for-webuzo' ) );
			}
			$client = new Native_Panel();
			if ( 'connection' === $operation ) {
				$result = $client->check_connection();
				$text   = sprintf(
					/* translators: %s: Hosting account username confirmed by the native bridge. */
					__( 'Connected to the native Webuzo bridge for hosting account %s. Connection test only; no cache was cleared.', 'clear-nginx-for-webuzo' ),
					$result['account']
				);
			} else {
				$result = $client->purge();
				$text   = sprintf(
					/* translators: %s: Hosting account username confirmed by the native bridge. */
					__( 'The native Webuzo cache-clear command completed for hosting account %s. This affects every site in that account. New visits rebuild cache.', 'clear-nginx-for-webuzo' ),
					$result['account']
				);
			}
			$type = 'success';
		} catch ( \Throwable $error ) {
			$text = $error->getMessage();
			$type = 'error';
		}
		set_transient(
			'webuzo_site_cache_notice_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
			),
			120
		);
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}
}
