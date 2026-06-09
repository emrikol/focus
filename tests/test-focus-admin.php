<?php
declare(strict_types=1);

/**
 * Test FOCUS admin UI and lifecycle behavior.
 *
 * @group focus
 * @group admin
 */

class Tests_Focus_Admin_Page_Double extends FOCUS_Cache {
	public bool $test_dropin_exists = true;
	public bool $test_dropin_valid = true;
	public bool $test_is_admin = true;
	public bool $test_initialize_filesystem = false;
	public string $test_status = 'Enabled';
	public string $test_prefix = 'unit-test-prefix';
	public string $test_backend = 'file';
	public int $test_maxttl = 123;

	public function object_cache_dropin_exists(): bool {
		return $this->test_dropin_exists;
	}

	public function validate_object_cache_dropin(): bool {
		return $this->test_dropin_valid;
	}

	public function is_user_cache_admin(): bool {
		return $this->test_is_admin;
	}

	public function initialize_filesystem( string $url, bool $silent = false ): bool {
		return $this->test_initialize_filesystem;
	}

	public function get_status(): string {
		return $this->test_status;
	}

	public function get_focus_cachekey_prefix(): string {
		return $this->test_prefix;
	}

	public function get_focus_backend(): string {
		return $this->test_backend;
	}

	public function get_focus_maxttl(): int {
		return $this->test_maxttl;
	}
}

class Tests_Focus_Admin_Database_Lifecycle_Double extends Tests_Focus_Admin_Page_Double {
	public int $install_database_tables_calls = 0;
	public int $schedule_database_gc_calls = 0;

	public function __construct( string $plugin_file = '' ) {
		parent::__construct( $plugin_file );
		$this->test_backend = 'database';
	}

	public function install_database_tables(): bool {
		++$this->install_database_tables_calls;
		return true;
	}

	public function schedule_database_gc(): void {
		++$this->schedule_database_gc_calls;
	}
}

class Tests_Focus_Admin_Filesystem_Double {
	public array $deleted = array();

	public function delete( string $path ): bool {
		$this->deleted[] = $path;
		return true;
	}
}

class Tests_Focus_Admin_Object_Cache_Double {
	public int $install_database_tables_calls = 0;
	public int $run_database_gc_calls = 0;

	public function install_database_tables(): bool {
		++$this->install_database_tables_calls;
		return true;
	}

	public function run_database_gc(): int {
		++$this->run_database_gc_calls;
		return 1;
	}
}

if ( ! class_exists( 'QM_Data' ) ) {
	abstract class QM_Data {
		public $prefetch;
	}
}

if ( ! class_exists( 'QM_Data_Fallback' ) ) {
	class QM_Data_Fallback extends QM_Data {}
}

if ( ! class_exists( 'QM_Collector' ) ) {
	class QM_Collector {
		public $id = 'stub';
		public $data;

		public function __construct() {
			$this->data = $this->get_storage();
		}

		public function get_storage(): QM_Data {
			return new QM_Data_Fallback();
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'QM_Output_Html' ) ) {
	class QM_Output_Html {
		protected $collector;

		public function __construct( QM_Collector $collector ) {
			$this->collector = $collector;
		}

		protected function before_non_tabular_output() {
			echo '<div class="qm qm-non-tabular">';
		}

		protected function after_non_tabular_output() {
			echo '</div>';
		}

		protected function menu( array $args ) {
			return $args;
		}
	}
}

if ( ! class_exists( 'QM_Collectors' ) ) {
	class QM_Collectors {
		public static array $collectors = array();

		public static function get( string $id ) {
			return self::$collectors[ $id ] ?? null;
		}
	}
}

class Tests_Focus_Admin extends WP_UnitTestCase {
	private string $dropin_path;
	private bool $dropin_existed = false;
	private bool $dropin_was_link = false;
	private string|false $dropin_link_target = false;
	private string|false $dropin_contents = false;
	private array $original_get = array();
	private int $user_id = 0;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->dropin_path = WP_CONTENT_DIR . '/object-cache.php';
		$this->original_get = $_GET;
		$this->backup_dropin();

		$this->user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->user_id );
		}
		wp_set_current_user( $this->user_id );

		$_GET = array();
		$this->clear_settings_errors();
	}

	public function tear_down() {
		$_GET = $this->original_get;
		if ( is_multisite() && $this->user_id ) {
			revoke_super_admin( $this->user_id );
		}
		$this->restore_dropin();
		$this->clear_settings_errors();

		parent::tear_down();
	}

	private function admin(): FOCUS_Cache {
		return new FOCUS_Cache( FOCUS_PLUGIN_FILE );
	}

	private function backup_dropin(): void {
		$this->dropin_existed = file_exists( $this->dropin_path ) || is_link( $this->dropin_path );
		$this->dropin_was_link = is_link( $this->dropin_path );
		$this->dropin_link_target = $this->dropin_was_link ? readlink( $this->dropin_path ) : false;
		$this->dropin_contents = ( $this->dropin_existed && ! $this->dropin_was_link ) ? file_get_contents( $this->dropin_path ) : false;
	}

	private function restore_dropin(): void {
		if ( file_exists( $this->dropin_path ) || is_link( $this->dropin_path ) ) {
			unlink( $this->dropin_path );
		}

		if ( ! $this->dropin_existed ) {
			return;
		}

		if ( $this->dropin_was_link && is_string( $this->dropin_link_target ) ) {
			if ( ! symlink( $this->dropin_link_target, $this->dropin_path ) ) {
				copy( $this->dropin_link_target, $this->dropin_path );
			}
			return;
		}

		if ( is_string( $this->dropin_contents ) ) {
			file_put_contents( $this->dropin_path, $this->dropin_contents );
		}
	}

	private function remove_dropin(): void {
		if ( file_exists( $this->dropin_path ) || is_link( $this->dropin_path ) ) {
			unlink( $this->dropin_path );
		}
	}

	private function write_dropin( string $contents ): void {
		$this->remove_dropin();
		file_put_contents( $this->dropin_path, $contents );
	}

	private function clear_settings_errors(): void {
		global $wp_settings_errors;
		$wp_settings_errors = array();
	}

		public function test_constructor_registers_hooks_and_default_links() {
			$admin = $this->admin();

			$this->assertNotFalse( has_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $admin, 'add_admin_menu_page' ) ) );
			$this->assertNotFalse( has_action( 'admin_notices', array( $admin, 'show_admin_notices' ) ) );
			$this->assertNotFalse( has_action( 'network_admin_notices', array( $admin, 'show_admin_notices' ) ) );
			$this->assertSame( 0, has_action( 'plugins_loaded', array( $admin, 'maybe_register_query_monitor' ) ) );
			$admin->maybe_register_query_monitor();

			$links = $admin->add_plugin_actions_links( array( 'deactivate' => 'Deactivate' ) );

			$this->assertStringContainsString( 'Settings', $links[0] );
			$this->assertSame( 'Deactivate', $links['deactivate'] );
		}

		public function test_query_monitor_prefetch_collector_and_output() {
			require_once dirname( __DIR__ ) . '/includes/class-focus-query-monitor.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-data-prefetch.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-collector-prefetch.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-prefetch.php';

			global $wp_object_cache;

			$original_cache = $wp_object_cache;
			$original_prefetch_stats = $wp_object_cache->prefetch_stats ?? null;
			$original_prefetched_keys = $wp_object_cache->prefetched_keys ?? null;
			$original_prefetch_requested_keys = $wp_object_cache->prefetch_requested_keys ?? null;
			$original_test_prefetch_enabled = $wp_object_cache->test_prefetch_enabled ?? null;

			try {
				$empty_collector = new FOCUS_QM_Collector_Prefetch();
				$this->assertSame( 'FOCUS Prefetch', $empty_collector->name() );
				$this->assertInstanceOf( QM_Data::class, $empty_collector->get_storage() );

				$wp_object_cache = new stdClass(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$empty_collector->process();
				$this->assertSame( array(), $empty_collector->get_data()->prefetch );

				$wp_object_cache = $original_cache; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$wp_object_cache->test_prefetch_enabled = true;
				$wp_object_cache->prefetch_requested_keys = array(
					'qm_prefetch_group' => array(
						'used_key' => 'used_key',
						'unused_key' => 'unused_key',
						'missing_key' => 'missing_key',
					),
				);
				$wp_object_cache->prefetched_keys = array(
					'qm_prefetch_group' => array(
						'used_key' => true,
						'unused_key' => false,
					),
				);
				$wp_object_cache->prefetch_stats = array_merge(
					$wp_object_cache->prefetch_stats,
					array(
						'enabled'                  => true,
						'backend'                  => 'file',
						'key'                      => 'prefetch-key',
						'manifest_found'           => true,
						'manifest_groups'          => 1,
						'manifest_keys'            => 3,
						'requested_keys'           => 3,
						'loaded_keys'              => 2,
						'missing_keys'             => 1,
						'used_keys'                => 1,
						'unused_keys'              => 1,
						'calls_saved'              => 1,
						'net_calls_saved'          => 0,
						'load_operations'          => 1,
						'load_time'                => 0.001,
						'estimated_time_saved'     => 0.0,
						'saved_manifest_groups'    => 1,
						'saved_manifest_keys'      => 3,
						'saved_manifest_time'      => 0.001,
						'saved_manifest_succeeded' => true,
					)
				);

				$collector = new FOCUS_QM_Collector_Prefetch();
				$this->assertInstanceOf( FOCUS_QM_Data_Prefetch::class, $collector->get_storage() );
				$collector->process();
				$this->assertSame( 'file', $collector->get_data()->prefetch['backend'] );

				FOCUS_Query_Monitor::register();
				$this->assertNotFalse( has_filter( 'qm/collectors', array( 'FOCUS_Query_Monitor', 'register_collectors' ) ) );
				$this->assertNotFalse( has_filter( 'qm/outputter/html', array( 'FOCUS_Query_Monitor', 'register_outputters' ) ) );

				$collectors = FOCUS_Query_Monitor::register_collectors( array() );
				$this->assertInstanceOf( FOCUS_QM_Collector_Prefetch::class, $collectors['focus_prefetch'] );

				QM_Collectors::$collectors = array();
				$this->assertSame( array(), FOCUS_Query_Monitor::register_outputters( array() ) );

				QM_Collectors::$collectors = $collectors;
				$outputters = FOCUS_Query_Monitor::register_outputters( array() );
				$this->assertInstanceOf( FOCUS_QM_Output_Html_Prefetch::class, $outputters['focus_prefetch'] );

				$outputter = new FOCUS_QM_Output_Html_Prefetch( $collector );
				$this->assertContains( 'qm-focus-prefetch', $outputter->admin_class( array() ) );

				$menu = $outputter->panel_menu(
					array(
						'cache' => array(
							'children' => array(),
						),
					)
				);
				$this->assertSame( 'qm-focus_prefetch', $menu['cache']['children'][0]['id'] );

				$menu = $outputter->panel_menu(
					array(
						'object_cache' => array(
							'children' => array(),
						),
					)
				);
				$this->assertSame( 'qm-focus_prefetch', $menu['object_cache']['children'][0]['id'] );

				$fallback_menu = $outputter->panel_menu( array() );
				$this->assertSame( 'qm-focus_prefetch', $fallback_menu['focus_prefetch']['id'] );

				ob_start();
				$outputter->output();
				$output = ob_get_clean();

				$this->assertStringContainsString( 'Prefetch Summary', $output );
				$this->assertStringContainsString( 'Individual Calls Avoided', $output );
				$this->assertStringContainsString( 'Prefetched And Used', $output );
				$this->assertStringContainsString( 'Prefetched But Unused', $output );
				$this->assertStringContainsString( 'unused_key', $output );

				$empty_outputter = new FOCUS_QM_Output_Html_Prefetch( new FOCUS_QM_Collector_Prefetch() );
				ob_start();
				$empty_outputter->output();
				$this->assertSame( '', ob_get_clean() );

				$collector->data->prefetch['used_groups'] = array();
				$collector->data->prefetch['unused_groups'] = array();
				ob_start();
				$outputter->output();
				$summary_only_output = ob_get_clean();
				$this->assertStringContainsString( 'Prefetch Summary', $summary_only_output );
				$this->assertStringNotContainsString( 'Prefetched But Unused', $summary_only_output );
			} finally {
				if ( null !== $original_prefetch_stats && isset( $original_cache->prefetch_stats ) ) {
					$original_cache->prefetch_stats = $original_prefetch_stats;
				}
				if ( null !== $original_prefetched_keys && isset( $original_cache->prefetched_keys ) ) {
					$original_cache->prefetched_keys = $original_prefetched_keys;
				}
				if ( null !== $original_prefetch_requested_keys && isset( $original_cache->prefetch_requested_keys ) ) {
					$original_cache->prefetch_requested_keys = $original_prefetch_requested_keys;
				}
				if ( null !== $original_test_prefetch_enabled && isset( $original_cache->test_prefetch_enabled ) ) {
					$original_cache->test_prefetch_enabled = $original_test_prefetch_enabled;
				}
				$wp_object_cache = $original_cache; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				QM_Collectors::$collectors = array();
				remove_filter( 'qm/collectors', array( 'FOCUS_Query_Monitor', 'register_collectors' ), 10 );
				remove_filter( 'qm/outputter/html', array( 'FOCUS_Query_Monitor', 'register_outputters' ), 10 );
			}
		}

		public function test_constructor_uses_plugin_constant_when_file_is_omitted() {
			$admin = new FOCUS_Cache();
		$links = $admin->add_plugin_actions_links( array() );

		$this->assertStringContainsString( 'Settings', $links[0] );
	}

	public function test_admin_menu_and_getters() {
		$admin = $this->admin();

		$admin->add_admin_menu_page();

		$this->assertTrue( $admin->is_user_cache_admin() );
		$this->assertSame( WP_FOCUS_MAXTTL, $admin->get_focus_maxttl() );
		$this->assertSame( WP_CACHE_KEY_SALT, $admin->get_focus_cachekey_prefix() );
		$this->assertSame( WP_FOCUS_BACKEND, $admin->get_focus_backend() );
	}

	public function test_dropin_status_detection() {
		$admin = $this->admin();

		$this->remove_dropin();

		$this->assertFalse( $admin->object_cache_dropin_exists() );
		$this->assertFalse( $admin->validate_object_cache_dropin() );
		$this->assertSame( 'Disabled', $admin->get_status() );

		$this->write_dropin(
			"<?php\n" .
			"/**\n" .
			" * Plugin URI: https://example.com/not-focus-cache/\n" .
			" * Version: 1.0.0\n" .
			" */\n"
		);

		$this->assertTrue( $admin->object_cache_dropin_exists() );
		$this->assertFalse( $admin->validate_object_cache_dropin() );
		$this->assertSame( 'Unknown', $admin->get_status() );

		$this->restore_dropin();

		$this->assertTrue( $admin->object_cache_dropin_exists() );
		$this->assertTrue( $admin->validate_object_cache_dropin() );
		$this->assertSame( 'Enabled', $admin->get_status() );
	}

	public function test_render_admin_page_for_enabled_disabled_and_unknown_states() {
		$admin = new Tests_Focus_Admin_Page_Double( FOCUS_PLUGIN_FILE );

		ob_start();
		$admin->render_admin_page();
		$enabled_output = ob_get_clean();

		$this->assertStringContainsString( 'FOCUS Object Cache', $enabled_output );
		$this->assertStringContainsString( 'Enabled', $enabled_output );
		$this->assertStringContainsString( 'Backend:', $enabled_output );
		$this->assertStringContainsString( 'file', $enabled_output );
		$this->assertStringContainsString( 'unit-test-prefix', $enabled_output );
		$this->assertStringContainsString( '123', $enabled_output );
		$this->assertStringContainsString( 'Flush Cache', $enabled_output );
		$this->assertStringContainsString( 'Disable Object Cache', $enabled_output );

		$admin->test_dropin_exists = false;
		$admin->test_status = 'Disabled';

		ob_start();
		$admin->render_admin_page();
		$disabled_output = ob_get_clean();

		$this->assertStringContainsString( 'Disabled', $disabled_output );
		$this->assertStringContainsString( 'Enable Object Cache', $disabled_output );
		$this->assertStringNotContainsString( 'Flush Cache', $disabled_output );

		$admin->test_dropin_exists = true;
		$admin->test_dropin_valid = false;
		$admin->test_status = 'Unknown';

		ob_start();
		$admin->render_admin_page();
		$unknown_output = ob_get_clean();

		$this->assertStringContainsString( 'Unknown', $unknown_output );
		$this->assertStringContainsString( 'Flush Cache', $unknown_output );
		$this->assertStringNotContainsString( 'Disable Object Cache', $unknown_output );
		$this->assertStringNotContainsString( 'Enable Object Cache', $unknown_output );
	}

	public function test_render_admin_page_returns_when_filesystem_credentials_are_needed() {
		$admin = new Tests_Focus_Admin_Page_Double( FOCUS_PLUGIN_FILE );
		$admin->test_initialize_filesystem = false;

		$_GET = array(
			'action' => 'enable-cache',
			'_wpnonce' => wp_create_nonce( 'enable-cache' ),
		);

		ob_start();
		$admin->render_admin_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_admin_page_notices_register_expected_messages() {
		$admin = $this->admin();
		$cases = array(
			'cache-enabled' => array( 'Object Cache enabled.', 'updated' ),
			'enable-cache-failed' => array( 'Object Cache could not be enabled.', 'error' ),
			'cache-disabled' => array( 'Object Cache disabled.', 'updated' ),
			'disable-cache-failed' => array( 'Object Cache could not be disabled.', 'error' ),
			'cache-flushed' => array( 'Object Cache flushed.', 'updated' ),
			'flush-cache-failed' => array( 'Object Cache could not be flushed.', 'error' ),
			'dropin-updated' => array( 'Drop-in updated.', 'updated' ),
			'update-dropin-failed' => array( 'Drop-in could not be updated.', 'error' ),
		);

		foreach ( $cases as $message => $expected ) {
			$this->clear_settings_errors();
			$_GET['message'] = $message;

			$admin->add_admin_page_notices();

			$errors = get_settings_errors();
			$notice = array_pop( $errors );

			$this->assertSame( 'focus-cache', $notice['code'] );
			$this->assertSame( $expected[0], $notice['message'] );
			$this->assertSame( $expected[1], $notice['type'] );
		}
	}

	public function test_show_admin_notices_respects_permissions_and_dropin_state() {
		$admin = new Tests_Focus_Admin_Page_Double( FOCUS_PLUGIN_FILE );
		$admin->test_is_admin = false;

		ob_start();
		$admin->show_admin_notices();
		$no_permission_output = ob_get_clean();

		$this->assertSame( '', $no_permission_output );

		$admin->test_is_admin = true;
		$admin->test_dropin_exists = true;
		$admin->test_dropin_valid = false;

		ob_start();
		$admin->show_admin_notices();
		$invalid_dropin_output = ob_get_clean();

		$this->assertStringContainsString( 'Another object cache drop-in was found.', $invalid_dropin_output );
	}

	public function test_show_admin_notices_reports_outdated_valid_dropin() {
		$this->write_dropin(
			"<?php\n" .
			"/**\n" .
			" * Plugin URI: http://wordpress.org/plugins/focus-object-cache/\n" .
			" * Version: 0.0.1\n" .
			" */\n"
		);

		$admin = $this->admin();

		ob_start();
		$admin->show_admin_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'The FOCUS cache drop-in is outdated.', $output );
	}

	public function test_do_admin_actions_noops_without_valid_request() {
		$admin = $this->admin();

		$_GET = array();
		$admin->do_admin_actions();

		$_GET = array(
			'action' => 'flush-cache',
			'_wpnonce' => 'not-a-valid-nonce',
		);
		$admin->do_admin_actions();

		$this->assertTrue( true );
	}

	public function test_do_admin_actions_valid_action_without_filesystem_does_not_redirect() {
		$admin = new Tests_Focus_Admin_Page_Double( FOCUS_PLUGIN_FILE );
		$admin->test_initialize_filesystem = false;

		$_GET = array(
			'action' => 'enable-cache',
			'_wpnonce' => wp_create_nonce( 'enable-cache' ),
		);

		$admin->do_admin_actions();

		$this->assertTrue( true );
	}

	public function test_initialize_filesystem_silent_mode_closes_output_buffer() {
		$admin = $this->admin();
		$level = ob_get_level();

		$result = $admin->initialize_filesystem( '', true );

		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		$this->assertIsBool( $result );
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_lifecycle_hooks_flush_and_delete_dropin() {
		global $wp_filesystem;

		$admin = new Tests_Focus_Admin_Page_Double( FOCUS_PLUGIN_FILE );
		$admin->test_dropin_valid = true;
		$admin->test_initialize_filesystem = true;
		$wp_filesystem = new Tests_Focus_Admin_Filesystem_Double();

		wp_cache_set( 'activation_key', 'activation_value', 'activation_group' );
		$this->assertSame( 'activation_value', wp_cache_get( 'activation_key', 'activation_group' ) );

		$admin->on_activation();

		$this->assertFalse( wp_cache_get( 'activation_key', 'activation_group' ) );

		$admin->on_deactivation();

		$this->assertSame( array( WP_CONTENT_DIR . '/object-cache.php' ), $wp_filesystem->deleted );
	}

	public function test_database_backend_activation_installs_tables_and_schedules_gc() {
		$admin = new Tests_Focus_Admin_Database_Lifecycle_Double( FOCUS_PLUGIN_FILE );

		wp_cache_set( 'database_activation_key', 'database_activation_value', 'database_activation_group' );
		$this->assertSame( 'database_activation_value', wp_cache_get( 'database_activation_key', 'database_activation_group' ) );

		$admin->on_activation();

		$this->assertSame( 1, $admin->install_database_tables_calls );
		$this->assertSame( 1, $admin->schedule_database_gc_calls );
		$this->assertFalse( wp_cache_get( 'database_activation_key', 'database_activation_group' ) );
	}

	public function test_database_lifecycle_methods_delegate_to_active_object_cache() {
		global $wp_object_cache;

		$admin = $this->admin();
		$original_cache = $wp_object_cache;
		$cache_double = new Tests_Focus_Admin_Object_Cache_Double();

		try {
			$wp_object_cache = $cache_double; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertTrue( $admin->install_database_tables() );
			$admin->run_database_gc();

			$this->assertSame( 1, $cache_double->install_database_tables_calls );
			$this->assertSame( 1, $cache_double->run_database_gc_calls );

			$wp_object_cache = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->assertFalse( $admin->install_database_tables() );
		} finally {
			$wp_object_cache = $original_cache; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_schedule_database_gc_schedules_only_one_event() {
		$admin = $this->admin();

		wp_clear_scheduled_hook( 'focus_cache_database_gc' );

		$admin->schedule_database_gc();
		$first_scheduled = wp_next_scheduled( 'focus_cache_database_gc' );

		$admin->schedule_database_gc();
		$second_scheduled = wp_next_scheduled( 'focus_cache_database_gc' );

		wp_clear_scheduled_hook( 'focus_cache_database_gc' );

		$this->assertNotFalse( $first_scheduled );
		$this->assertSame( $first_scheduled, $second_scheduled );
	}
}
