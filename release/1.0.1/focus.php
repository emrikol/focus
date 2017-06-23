<?php
/**
 * Plugin Name: FOCUS Object Cache
 * Plugin URI: http://wordpress.org/plugins/focus-object-cache/
 * Description: File-based Object Cache is Utterly Slow: An Object Caching Dropin for WordPress that uses the local file system.
 * Version: 1.0.1
 * Text Domain: focus-cache
 * Author: Derrick Tennant
 * Author URI: https://emrikol.com/
 * GitHub Plugin URI: https://github.com/emrikol/focus/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Network: true
 *
 * @package WordPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *
 * This implementation of the object cache uses flat files to store objects
 * and overrides the core non-persistent cache.
 *
 * This class includes the non-caching admin functions
 *
 * @since 0.1.0
 */
class FOCUS_Cache {
	/**
	 * The UI page for the admin interface
	 *
	 * @since 0.1.0
	 * @access private
	 * @var string
	 */
	private $page;

	/**
	 * The UI page slug for the admin interface
	 *
	 * @since 0.1.0
	 * @access private
	 * @var string
	 */
	private $screen = 'settings_page_focus-cache';

	/**
	 * Valid actions the admin interface can use
	 *
	 * @since 0.1.0
	 * @access private
	 * @var array
	 */
	private $actions = array( 'enable-cache', 'disable-cache', 'flush-cache', 'update-dropin' );

	/**
	 * Initializes the class
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function __construct() {
		register_deactivation_hook( __FILE__, array( $this, 'on_deactivation' ) );

		$this->page = is_multisite() ? 'settings.php?page=focus-cache' : 'options-general.php?page=focus-cache';

		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_admin_menu_page' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'network_admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'load-' . $this->screen, array( $this, 'do_admin_actions' ) );
		add_action( 'load-' . $this->screen, array( $this, 'add_admin_page_notices' ) );

		add_filter( sprintf(
			'%splugin_action_links_%s',
			is_multisite() ? 'network_admin_' : '',
			plugin_basename( __FILE__ )
		), array( $this, 'add_plugin_actions_links' ) );
	}

	/**
	 * Registers the admin page in the UI
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function add_admin_menu_page() {
		global $wpmu_version;
		if ( is_multisite() && $this->is_user_cache_admin() ) {
			add_submenu_page( 'settings.php', esc_html__( 'FOCUS Cache', 'focus-cache' ), esc_html__( 'FOCUS Cache', 'focus-cache' ), 'manage_network_options', 'focus-cache', array( $this, 'render_admin_page' ) );
		} elseif ( $this->is_user_cache_admin() ) {
			add_options_page( esc_html__( 'FOCUS Cache', 'focus-cache' ), esc_html__( 'FOCUS Cache', 'focus-cache' ), 'manage_options', 'focus-cache', array( $this, 'render_admin_page' ) );
		}
	}

	/**
	 * Determines if a user can manage the cache.
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function is_user_cache_admin() {
		if ( function_exists( 'is_super_admin' ) ) {
			return is_super_admin();
		} elseif ( function_exists( 'is_site_admin' ) ) {
			return is_site_admin();
		} elseif ( current_user_can( 'manage_network_options' ) && is_multisite() ) {
			return true;
		} elseif ( current_user_can( 'manage_options' ) && ! is_multisite() ) {
			return true;
		} else {
			return true;
		}
	}

	/**
	 * Returns the cache key prefix, if it exists
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function get_focus_maxttl() {
		return defined( 'WP_FOCUS_MAXTTL' ) ? WP_FOCUS_MAXTTL : null;
	}

	/**
	 * Returns the cache key prefix, if it exists
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function get_focus_cachekey_prefix() {
		return defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : null;
	}

	/**
	 * Actually does the heavy lifting to render the admin page.
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function render_admin_page() {
		if ( isset( $_GET['action'], $_GET['_wpnonce'] ) ) { // Input var okay.
			$action = in_array( $_GET['action'], $this->actions, true ) ? $_GET['action'] : false; // @codingStandardsIgnoreLine.

			// request filesystem credentials?
			if ( false !== $action  && wp_verify_nonce( $_GET['_wpnonce'], $action ) ) { // @codingStandardsIgnoreLine.
				$url = esc_url_raw( wp_nonce_url( network_admin_url( add_query_arg( 'action', rawurlencode( $action ), $this->page ) ), $action ) );
				if ( false === $this->initialize_filesystem( $url ) ) {
					return; // request filesystem credentials.
				}
			}
		}

		// show admin page.
		require_once( plugin_dir_path( __FILE__ ) . 'includes/admin-page.php' );
	}

	/**
	 * Add settings link to plugin actions.
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @param array $links The plugin action links to filter.
	 *
	 * @return array Filtered plugin action links.
	 */
	public function add_plugin_actions_links( $links ) {
		return array_merge(
			array( sprintf( '<a href="%s">Settings</a>', esc_url( network_admin_url( $this->page ) ) ) ),
			$links
		);
	}

	/**
	 * Determines if the required dropin is already in place.
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @return bool Existential status of dropin file.
	 */
	public function object_cache_dropin_exists() {
		return file_exists( WP_CONTENT_DIR . '/object-cache.php' );
	}

	/**
	 * Helper function to validate if up-to-date dropin is installed.
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @return bool Existential status of dropin file.
	 */
	public function validate_object_cache_dropin() {
		if ( ! $this->object_cache_dropin_exists() ) {
			return false;
		}

		$dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
		$plugin = get_plugin_data( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php' );

		if ( 0 !== strcmp( $dropin['PluginURI'], $plugin['PluginURI'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the status of the object cache dropin.
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @return string Status of the object cache dropin.
	 */
	public function get_status() {
		if ( ! $this->object_cache_dropin_exists() ) {
			return esc_html__( 'Disabled', 'focus-cache' );
		}

		if ( $this->validate_object_cache_dropin() ) {
			return esc_html__( 'Enabled', 'focus-cache' );
		}

		return esc_html__( 'Unknown', 'focus-cache' );
	}

	/**
	 * Displays admin notifications concerning the dropin file.
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function show_admin_notices() {
		// Only show admin notices to users with the right capability.
		if ( ! $this->is_user_cache_admin() ) {
			return;
		}

		if ( $this->object_cache_dropin_exists() ) {
			$url = wp_nonce_url( network_admin_url( add_query_arg( 'action', 'update-dropin', $this->page ) ), 'update-dropin' );

			if ( $this->validate_object_cache_dropin() ) {
				$dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
				$plugin = get_plugin_data( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php' );

				if ( version_compare( $dropin['Version'], $plugin['Version'], '<' ) ) {
					$message = sprintf( __( 'The FOCUS cache drop-in is outdated. Please <a href="%s">update it now</a>.', 'focus-cache' ), esc_url( $url ) );
				}
			} else {
				$message = sprintf( __( 'Another object cache drop-in was found. To use FOCUS Cache, <a href="%s">please replace it now</a>.', 'focus-cache' ), esc_url( $url ) );
			}

			if ( isset( $message ) ) {
				printf( '<div class="update-nag">%s</div>', wp_kses_post( $message ) );
			}
		}
	}

	/**
	 * Displays admin notifications concerning the plugin status.
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function add_admin_page_notices() {
		// Show action success/failure messages.
		if ( isset( $_GET['message'] ) ) { // Input var okay.
			switch ( $_GET['message'] ) { // Input var okay.
				case 'cache-enabled':
					$message = esc_html__( 'Object Cache enabled.', 'focus-cache' );
					break;
				case 'enable-cache-failed':
					$error = esc_html__( 'Object Cache could not be enabled.', 'focus-cache' );
					break;
				case 'cache-disabled':
					$message = esc_html__( 'Object Cache disabled.', 'focus-cache' );
					break;
				case 'disable-cache-failed':
					$error = esc_html__( 'Object Cache could not be disabled.', 'focus-cache' );
					break;
				case 'cache-flushed':
					$message = esc_html__( 'Object Cache flushed.', 'focus-cache' );
					break;
				case 'flush-cache-failed':
					$error = esc_html__( 'Object Cache could not be flushed.', 'focus-cache' );
					break;
				case 'dropin-updated':
					$message = esc_html__( 'Drop-in updated.', 'focus-cache' );
					break;
				case 'update-dropin-failed':
					$error = esc_html__( 'Drop-in could not be updated.', 'focus-cache' );
					break;
			}
			add_settings_error( '', 'focus-cache', isset( $message ) ? $message : $error, isset( $message ) ? 'updated' : 'error' );
		}
	}

	/**
	 * Runs the specified admin action.
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function do_admin_actions() {
		if ( ! isset( $_GET['_wpnonce'], $_GET['action'] ) ) { // Input var okay.
			return;
		}

		$action = in_array( $_GET['action'], $this->actions, true ) ? sanitize_key( $_GET['action'] ) : false; // @codingStandardsIgnoreLine.

		// Verify nonce.
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], $action ) ) {// @codingStandardsIgnoreLine.
			return;
		}

		if ( in_array( $action, $this->actions, true ) ) {
			$url = esc_url_raw( wp_nonce_url( network_admin_url( add_query_arg( 'action', rawurlencode( $action ), $this->page ) ), $action ) );

			if ( 'flush-cache' === $action ) {
				$message = wp_cache_flush() ? 'cache-flushed' : 'flush-cache-failed';
			}

			// Do we have filesystem credentials?
			if ( $this->initialize_filesystem( $url, true ) ) {
				global $wp_filesystem;

				switch ( $action ) {
					case 'enable-cache':
						$result = $wp_filesystem->copy( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php', WP_CONTENT_DIR . '/object-cache.php', true );
						$message = $result ? 'cache-enabled' : 'enable-cache-failed';
						break;
					case 'disable-cache':
						$result = $wp_filesystem->delete( WP_CONTENT_DIR . '/object-cache.php' );
						$message = $result ? 'cache-disabled' : 'disable-cache-failed';
						break;
					case 'update-dropin':
						$result = $wp_filesystem->copy( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php', WP_CONTENT_DIR . '/object-cache.php', true );
						$message = $result ? 'dropin-updated' : 'update-dropin-failed';
						break;
				}
			}

			// Redirect if status `$message` was set.
			if ( isset( $message ) ) {
				wp_safe_redirect( network_admin_url( add_query_arg( 'message', rawurlencode( $message ), $this->page ) ) );
				exit;
			}
		}
	}

	/**
	 * Initializes the filesystem.
	 *
	 * @since 0.1.0
	 * @access public

	 * @param string $url The URL to request credentials against.
	 * @param bool   $silent Whether or not the user form should be displayed.
	 * @return bool False if cannot init, true if can init.
	 */
	public function initialize_filesystem( $url, $silent = false ) {
		if ( $silent ) {
			ob_start();
		}

		if ( ( $credentials = request_filesystem_credentials( $url ) ) === false ) {
			if ( $silent ) {
				ob_end_clean();
			}

			return false;
		}

		if ( ! WP_Filesystem( $credentials ) ) {
			request_filesystem_credentials( $url );

			if ( $silent ) {
				ob_end_clean();
			}

			return false;
		}

		return true;
	}

	/**
	 * Runs when plugin is deactivated.
	 *
	 * @since 0.1.0
	 * @access public
	 */
	public function on_deactivation() {
		if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
			global $wp_filesystem;
			$wp_filesystem->delete( WP_CONTENT_DIR . '/object-cache.php' );
		}
	}
}

new FOCUS_Cache;
