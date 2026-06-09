<?php
/**
 *
 * This implementation of the object cache uses flat files to store objects
 * and overrides the core non-persistent cache.
 *
 * This class includes the non-caching admin functions
 *
 * @since 0.1.0
 */

declare(strict_types=1);

/**
 * FOCUS Cache admin interface and plugin lifecycle.
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
	private string $page;

	/**
	 * The UI page slug for the admin interface
	 *
	 * @since 0.1.0
	 * @access private
	 * @var string
	 */
	private string $screen = 'settings_page_focus-cache';

	/**
	 * Valid actions the admin interface can use
	 *
	 * @since 0.1.0
	 * @access private
	 * @var array
	 */
	private array $actions = array( 'enable-cache', 'disable-cache', 'flush-cache', 'update-dropin' );

	/**
	 * Main plugin file path.
	 *
	 * @since 1.0.2
	 * @access private
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Initializes the class
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @param string $plugin_file Main plugin file path.
	 */
	public function __construct( string $plugin_file = '' ) {
		$this->plugin_file = $plugin_file;

		if ( '' === $this->plugin_file && defined( 'FOCUS_PLUGIN_FILE' ) && is_string( FOCUS_PLUGIN_FILE ) ) {
			$this->plugin_file = FOCUS_PLUGIN_FILE;
		}

		// @codeCoverageIgnoreStart
		if ( '' === $this->plugin_file ) {
			$this->plugin_file = dirname( __DIR__ ) . '/focus.php';
		}
		// @codeCoverageIgnoreEnd

		register_activation_hook( $this->plugin_file, array( $this, 'on_activation' ) );
		register_deactivation_hook( $this->plugin_file, array( $this, 'on_deactivation' ) );

		$this->page = is_multisite() ? 'settings.php?page=focus-cache' : 'options-general.php?page=focus-cache';

		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_admin_menu_page' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'network_admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'load-' . $this->screen, array( $this, 'do_admin_actions' ) );
		add_action( 'load-' . $this->screen, array( $this, 'add_admin_page_notices' ) );
		add_action( 'focus_cache_database_gc', array( $this, 'run_database_gc' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_register_query_monitor' ), 0 );

		add_filter(
			sprintf(
				'%splugin_action_links_%s',
				is_multisite() ? 'network_admin_' : '',
				plugin_basename( $this->plugin_file )
			),
			array( $this, 'add_plugin_actions_links' )
		);
	}

	/**
	 * Registers the admin page in the UI
	 *
	 * @since 0.1.0
	 * @access public
	 * @return void
	 */
	public function add_admin_menu_page(): void {
		global $wpmu_version;
		if ( is_multisite() && $this->is_user_cache_admin() ) {
			// @codeCoverageIgnoreStart
			add_submenu_page( 'settings.php', esc_html__( 'FOCUS Cache', 'focus-cache' ), esc_html__( 'FOCUS Cache', 'focus-cache' ), 'manage_network_options', 'focus-cache', array( $this, 'render_admin_page' ) );
			// @codeCoverageIgnoreEnd
		} elseif ( $this->is_user_cache_admin() ) {
			add_options_page( esc_html__( 'FOCUS Cache', 'focus-cache' ), esc_html__( 'FOCUS Cache', 'focus-cache' ), 'manage_options', 'focus-cache', array( $this, 'render_admin_page' ) );
		}
	}

	/**
	 * Determines if a user can manage the cache.
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @return bool True if the user can manage the cache, false otherwise.
	 */
	public function is_user_cache_admin(): bool {
		if ( function_exists( 'is_super_admin' ) ) {
			return is_super_admin();
			// @codeCoverageIgnoreStart
		} elseif ( current_user_can( 'manage_network_options' ) && is_multisite() ) {
			return true;
		} elseif ( current_user_can( 'manage_options' ) && ! is_multisite() ) {
			return true;
		} else {
			return true;
		}
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Returns the maximum TTL constant
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @return int The maximum TTL in seconds.
	 */
	public function get_focus_maxttl(): int {
		return defined( 'WP_FOCUS_MAXTTL' ) ? WP_FOCUS_MAXTTL : YEAR_IN_SECONDS;
	}

	/**
	 * Returns the cache key prefix, if it exists
	 *
	 * @since 0.1.0
	 * @access public
	 *
	 * @return string The cache key prefix.
	 */
	public function get_focus_cachekey_prefix(): string {
		return defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '';
	}

	/**
	 * Returns the configured persistent backend.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return string Configured persistent backend.
	 */
	public function get_focus_backend(): string {
		return defined( 'WP_FOCUS_BACKEND' ) ? (string) WP_FOCUS_BACKEND : 'file';
	}

	/**
	 * Registers FOCUS Query Monitor panels when Query Monitor is available.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return void
	 */
	public function maybe_register_query_monitor(): void {
		// @codeCoverageIgnoreStart
		if ( ! class_exists( 'QM_Collector' ) || ! class_exists( 'QM_Data' ) || ! class_exists( 'QM_Output_Html' ) || ! class_exists( 'QM_Collectors' ) ) {
			return;
		}
		// @codeCoverageIgnoreEnd

		require_once __DIR__ . '/class-focus-query-monitor.php';
		require_once __DIR__ . '/class-focus-qm-collector-prefetch.php';
		require_once __DIR__ . '/class-focus-qm-output-html-prefetch.php';

		FOCUS_Query_Monitor::register();
	}

	/**
	 * Actually does the heavy lifting to render the admin page.
	 *
	 * @since 0.1.0
	 * @access public
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( isset( $_GET['action'], $_GET['_wpnonce'] ) ) { // Input var okay.
			$action = in_array( $_GET['action'], $this->actions, true ) ? $_GET['action'] : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended -- Validated via in_array against allowlist; nonce verified below.

			// request filesystem credentials?
			if ( false !== $action && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
				$url = esc_url_raw( wp_nonce_url( network_admin_url( add_query_arg( 'action', rawurlencode( $action ), $this->page ) ), $action ) );
				if ( false === $this->initialize_filesystem( $url ) ) {
					return; // request filesystem credentials.
				}
			}
		}

		// show admin page.
		require plugin_dir_path( __FILE__ ) . 'admin-page.php';
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
	public function add_plugin_actions_links( array $links ): array {
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
	public function object_cache_dropin_exists(): bool {
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
	public function validate_object_cache_dropin(): bool {
		if ( ! $this->object_cache_dropin_exists() ) {
			return false;
		}

		$dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
		$plugin = get_plugin_data( plugin_dir_path( __FILE__ ) . '/object-cache.php' );

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
	public function get_status(): string {
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
	 * @return void
	 */
	public function show_admin_notices(): void {
		// Only show admin notices to users with the right capability.
		if ( ! $this->is_user_cache_admin() ) {
			return;
		}

		if ( $this->object_cache_dropin_exists() ) {
			$url = wp_nonce_url( network_admin_url( add_query_arg( 'action', 'update-dropin', $this->page ) ), 'update-dropin' );

			if ( $this->validate_object_cache_dropin() ) {
				$dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
				$plugin = get_plugin_data( plugin_dir_path( __FILE__ ) . '/object-cache.php' );

				if ( version_compare( $dropin['Version'], $plugin['Version'], '<' ) ) {
					// translators: %s is the link to update the plugin dropin.
					$message = sprintf( __( 'The FOCUS cache drop-in is outdated. Please <a href="%s">update it now</a>.', 'focus-cache' ), esc_url( $url ) );
				}
			} else {
				// translators: %s is the link to update the plugin dropin.
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
	 * @return void
	 */
	public function add_admin_page_notices(): void {
		// Show action success/failure messages.
		if ( isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			switch ( $_GET['message'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
	 * @return void
	 */
	public function do_admin_actions(): void {
		if ( ! isset( $_GET['_wpnonce'], $_GET['action'] ) ) { // Input var okay.
			return;
		}

		$action = in_array( $_GET['action'], $this->actions, true ) ? sanitize_key( $_GET['action'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated via in_array against allowlist, then sanitized with sanitize_key.

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
			return;
		}

		if ( in_array( $action, $this->actions, true ) ) {
			$url = esc_url_raw( wp_nonce_url( network_admin_url( add_query_arg( 'action', rawurlencode( $action ), $this->page ) ), $action ) );

			// @codeCoverageIgnoreStart
			if ( 'flush-cache' === $action ) {
				$message = wp_cache_flush() ? 'cache-flushed' : 'flush-cache-failed';
			}
			// @codeCoverageIgnoreEnd

			// Do we have filesystem credentials?
			// @codeCoverageIgnoreStart
			if ( $this->initialize_filesystem( $url, true ) ) {
				global $wp_filesystem;

				switch ( $action ) {
					case 'enable-cache':
						$result = $wp_filesystem->copy( plugin_dir_path( __FILE__ ) . '/object-cache.php', WP_CONTENT_DIR . '/object-cache.php', true );
						wp_cache_flush();
						$message = $result ? 'cache-enabled' : 'enable-cache-failed';
						break;
					case 'disable-cache':
						$result  = $wp_filesystem->delete( WP_CONTENT_DIR . '/object-cache.php' );
						$message = $result ? 'cache-disabled' : 'disable-cache-failed';
						wp_cache_flush();
						break;
					case 'update-dropin':
						$result  = $wp_filesystem->copy( plugin_dir_path( __FILE__ ) . '/object-cache.php', WP_CONTENT_DIR . '/object-cache.php', true );
						$message = $result ? 'dropin-updated' : 'update-dropin-failed';
						wp_cache_flush();
						break;
				}
			}
			// @codeCoverageIgnoreEnd

			// Redirect if status `$message` was set.
			// @codeCoverageIgnoreStart
			if ( isset( $message ) ) {
				wp_safe_redirect( network_admin_url( add_query_arg( 'message', rawurlencode( $message ), $this->page ) ) );
				exit;
			}
			// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Initializes the filesystem.
	 *
	 * @since 0.1.0
	 * @access public

	 * @param string $url The URL to request credentials against.
	 * @param bool   $silent Whether or not the user form should be displayed.
	 *
	 * @return bool False if cannot init, true if can init.
	 */
	public function initialize_filesystem( string $url, bool $silent = false ): bool {
		if ( $silent ) {
			ob_start();
		}

		$credentials = request_filesystem_credentials( $url );
		// @codeCoverageIgnoreStart
		if ( false === $credentials ) {
			if ( $silent ) {
				ob_end_clean();
			}

			return false;
		}
		// @codeCoverageIgnoreEnd

		// @codeCoverageIgnoreStart
		if ( ! WP_Filesystem( $credentials ) ) {
			request_filesystem_credentials( $url );

			if ( $silent ) {
				ob_end_clean();
			}

			return false;
		}
		// @codeCoverageIgnoreEnd

		if ( $silent ) {
			ob_end_clean();
		}

		return true;
	}

	/**
	 * Runs when plugin is deactivated.
	 *
	 * @since 0.1.0
	 * @access public
	 * @return void
	 */
	public function on_deactivation(): void {
		if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
			global $wp_filesystem;
			$wp_filesystem->delete( WP_CONTENT_DIR . '/object-cache.php' );
			wp_cache_flush();
		}

		wp_clear_scheduled_hook( 'focus_cache_database_gc' );
	}

	/**
	 * Runs when plugin is activated.
	 *
	 * @since 1.0.2
	 * @access public
	 * @return void
	 */
	public function on_activation(): void {
		if ( $this->is_database_backend_configured() ) {
			$this->install_database_tables();
			$this->schedule_database_gc();
		}
		wp_cache_flush();
	}

	/**
	 * Determines whether the database backend is configured.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return bool Whether the database backend is configured.
	 */
	public function is_database_backend_configured(): bool {
		$backend = strtolower( trim( $this->get_focus_backend() ) );

		return in_array( $backend, array( 'database', 'db' ), true );
	}

	/**
	 * Installs database backend tables when the FOCUS drop-in is active.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return bool Whether tables were installed.
	 */
	public function install_database_tables(): bool {
		global $wp_object_cache;

		if ( ! isset( $wp_object_cache ) || ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'install_database_tables' ) ) {
			return false;
		}

		return (bool) $wp_object_cache->install_database_tables();
	}

	/**
	 * Schedules recurring database garbage collection.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return void
	 */
	public function schedule_database_gc(): void {
		if ( ! wp_next_scheduled( 'focus_cache_database_gc' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'focus_cache_database_gc' );
		}
	}

	/**
	 * Runs database garbage collection through the active object cache.
	 *
	 * @since 1.1.0
	 * @access public
	 *
	 * @return void
	 */
	public function run_database_gc(): void {
		global $wp_object_cache;

		if ( isset( $wp_object_cache ) && is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'run_database_gc' ) ) {
			$wp_object_cache->run_database_gc();
		}
	}
}
