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

		public function id() {
			return 'qm-' . $this->id;
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

		protected function before_tabular_output() {
			echo '<table>';
		}

		protected function after_tabular_output() {
			echo '</table>';
		}

		protected function menu( array $args ) {
			return $args;
		}

		protected function build_sorter( string $title ) {
			return '<span class="sort">' . esc_html( $title ) . '</span>';
		}

		protected function build_filter( string $name, array $values, string $title, array $args = array() ) {
			return '<span class="filter" data-name="' . esc_attr( $name ) . '">' . esc_html( $title ) . ':' . esc_html( (string) count( $values ) ) . ':' . esc_html( (string) count( $args ) ) . '</span>';
		}

		protected static function build_toggler() {
			return '<button type="button">toggle</button>';
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

		public function test_query_monitor_object_cache_panels_and_prefetch_subpanel() {
			require_once dirname( __DIR__ ) . '/includes/class-focus-query-monitor.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-data-object-cache.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-data-prefetch.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-collector-object-cache.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-collector-object-cache-ops.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-collector-object-cache-group-stats.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-collector-object-cache-slow-ops.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-collector-prefetch.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-object-cache-base.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-object-cache.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-object-cache-ops.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-object-cache-group-stats.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-object-cache-slow-ops.php';
			require_once dirname( __DIR__ ) . '/includes/class-focus-qm-output-html-prefetch.php';

			global $wp_object_cache;

			$original_cache = $wp_object_cache;

			$prefetch = array(
				'enabled'                  => true,
				'backend'                  => 'database',
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
				'used_groups'              => array(
					'qm_prefetch_group' => array( 'used_key' ),
				),
				'unused_groups'            => array(
					'qm_prefetch_group' => array( 'unused_key' ),
				),
			);

			$stats = array(
				'totals'           => array(
					'query_time' => 0.0123,
					'size'       => 1234,
				),
				'operation_counts' => array(
					'set'      => 4,
					'get'      => 1,
					'zero'     => 0,
					'slow-ops' => 2,
				),
				'operations'       => array(
					'set'              => array(
						array(
							'key'    => 'alpha',
							'size'   => 100,
							'time'   => 0.001,
							'group'  => 'default',
							'result' => 'stored',
						),
						array(
							'key'    => array( 'single' ),
							'size'   => 0,
							'time'   => 0.002,
							'group'  => 'default',
							'result' => 'not_in_memcache',
						),
						array(
							'key'    => array( 'first', 'second', 'third' ),
							'size'   => 20,
							'time'   => 0.003,
							'group'  => 'default',
							'result' => 'memcache',
						),
						array(
							'key'    => array(),
							'size'   => 30,
							'time'   => 0.004,
							'group'  => 'options',
							'result' => '[mc already]',
						),
					),
					'get'              => array(
						array(
							'key'    => 'beta',
							'size'   => 10,
							'time'   => 0.005,
							'group'  => 'options',
							'result' => '[lc already]',
						),
					),
					'get_flush_number' => array(
						array(
							'key'    => 'skip',
							'size'   => 999,
							'time'   => 0.999,
							'group'  => 'skip_group',
							'result' => 'skip',
						),
					),
					'bad'              => array( 'not-an-operation-row' ),
				),
				'groups'           => array( 'default', 'options' ),
				'slow-ops'         => array(
					'set' => array(
						array(
							'key'       => array( 'slow1', 'slow2' ),
							'size'      => 100,
							'time'      => 0.01,
							'group'     => 'default',
							'result'    => 'stored',
							'backtrace' => 'Class->method, next_frame',
						),
						array(
							'key'       => 'slow-empty-trace',
							'size'      => 0,
							'time'      => 0.02,
							'group'     => 'options',
							'result'    => 'not_found',
							'backtrace' => '',
						),
					),
				),
				'slow-ops-groups'  => array( 'default', 'options' ),
				'prefetch'         => $prefetch,
			);

			$wp_object_cache = new class( $stats ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				private array $stats;

				public function __construct( array $stats ) {
					$this->stats = $stats;
				}

				public function get_stats(): array {
					return $this->stats;
				}

				public function get( $key, $group = 'default', $force = false, &$found = null ) {
					$found = false;
					return false;
				}

				public function set( $key, $data, $group = 'default', $expire = 0 ): bool {
					return true;
				}

				public function add( $key, $data, $group = 'default', $expire = 0 ): bool {
					return true;
				}

				public function stats(): void {
					echo '<h2>Legacy Stats</h2>';
				}
			};

			try {
				foreach (
					array(
						new FOCUS_QM_Collector_Object_Cache(),
						new FOCUS_QM_Collector_Object_Cache_Ops(),
						new FOCUS_QM_Collector_Object_Cache_Group_Stats(),
						new FOCUS_QM_Collector_Object_Cache_Slow_Ops(),
						new FOCUS_QM_Collector_Prefetch(),
					) as $collector
				) {
					$this->assertInstanceOf( QM_Data::class, $collector->get_storage() );
				}

				$this->assertSame( 'Object Cache', ( new FOCUS_QM_Collector_Object_Cache() )->name() );
				$this->assertSame( 'Operations', ( new FOCUS_QM_Collector_Object_Cache_Ops() )->name() );
				$this->assertSame( 'Group Stats', ( new FOCUS_QM_Collector_Object_Cache_Group_Stats() )->name() );
				$this->assertSame( 'Slow Operations', ( new FOCUS_QM_Collector_Object_Cache_Slow_Ops() )->name() );
				$this->assertSame( 'Prefetch', ( new FOCUS_QM_Collector_Prefetch() )->name() );

				$wp_object_cache = new stdClass(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$empty_collectors = FOCUS_Query_Monitor::register_collectors( array() );
				foreach ( $empty_collectors as $empty_collector ) {
					$empty_collector->process();
				}
				$this->assertSame( array(), $empty_collectors['object_cache']->get_data()->totals );
				$this->assertSame( array(), $empty_collectors['object_cache_prefetch']->get_data()->prefetch );

				$wp_object_cache = new class( $stats ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					private array $stats;

					public function __construct( array $stats ) {
						$this->stats = $stats;
					}

					public function get_stats(): array {
						return $this->stats;
					}

					public function get( $key, $group = 'default', $force = false, &$found = null ) {
						$found = false;
						return false;
					}

					public function set( $key, $data, $group = 'default', $expire = 0 ): bool {
						return true;
					}

					public function add( $key, $data, $group = 'default', $expire = 0 ): bool {
						return true;
					}

					public function stats(): void {
						echo '<h2>Legacy Stats</h2>';
					}
				};

				FOCUS_Query_Monitor::register();
				$this->assertNotFalse( has_filter( 'qm/collectors', array( 'FOCUS_Query_Monitor', 'register_collectors' ) ) );
				$this->assertNotFalse( has_filter( 'qm/outputter/html', array( 'FOCUS_Query_Monitor', 'register_outputters' ) ) );

				$collectors = FOCUS_Query_Monitor::register_collectors( array() );
				$this->assertInstanceOf( FOCUS_QM_Collector_Object_Cache::class, $collectors['object_cache'] );
				$this->assertInstanceOf( FOCUS_QM_Collector_Object_Cache_Ops::class, $collectors['object_cache_ops'] );
				$this->assertInstanceOf( FOCUS_QM_Collector_Object_Cache_Group_Stats::class, $collectors['object_cache_group_stats'] );
				$this->assertInstanceOf( FOCUS_QM_Collector_Object_Cache_Slow_Ops::class, $collectors['object_cache_slow_ops'] );
				$this->assertInstanceOf( FOCUS_QM_Collector_Prefetch::class, $collectors['object_cache_prefetch'] );

				foreach ( $collectors as $collector ) {
					$collector->process();
				}

				$this->assertSame( $stats['totals'], $collectors['object_cache']->get_data()->totals );
				$this->assertArrayHasKey( 'set', $collectors['object_cache_group_stats']->get_data()->group_stats );
				$this->assertArrayNotHasKey( 'get_flush_number', $collectors['object_cache_group_stats']->get_data()->group_stats );
				$this->assertSame( 'database', $collectors['object_cache_prefetch']->get_data()->prefetch['backend'] );

				QM_Collectors::$collectors = array();
				$this->assertSame( array(), FOCUS_Query_Monitor::register_outputters( array() ) );

				QM_Collectors::$collectors = $collectors;
				$outputters = FOCUS_Query_Monitor::register_outputters( array() );
				$this->assertInstanceOf( FOCUS_QM_Output_Html_Object_Cache::class, $outputters['object_cache'] );
				$this->assertInstanceOf( FOCUS_QM_Output_Html_Object_Cache_Ops::class, $outputters['object_cache_ops'] );
				$this->assertInstanceOf( FOCUS_QM_Output_Html_Object_Cache_Group_Stats::class, $outputters['object_cache_group_stats'] );
				$this->assertInstanceOf( FOCUS_QM_Output_Html_Object_Cache_Slow_Ops::class, $outputters['object_cache_slow_ops'] );
				$this->assertInstanceOf( FOCUS_QM_Output_Html_Prefetch::class, $outputters['object_cache_prefetch'] );
				$this->assertSame( 'Object Cache', $outputters['object_cache']->name() );

				$menu = $outputters['object_cache']->admin_menu( array() );
				$this->assertSame( 'object_cache', $menu['object_cache']['id'] );

				$panel_menu = array(
					'object_cache' => array(
						'children' => array(),
					),
				);
				$panel_menu = $outputters['object_cache_ops']->panel_menu( $panel_menu );
				$panel_menu = $outputters['object_cache_group_stats']->panel_menu( $panel_menu );
				$panel_menu = $outputters['object_cache_slow_ops']->panel_menu( $panel_menu );
				$panel_menu = $outputters['object_cache_prefetch']->panel_menu( $panel_menu );
				$this->assertSame( 'qm-object_cache_ops', $panel_menu['object_cache']['children'][0]['id'] );
				$this->assertSame( 'qm-object_cache_group_stats', $panel_menu['object_cache']['children'][1]['id'] );
				$this->assertSame( 'qm-object_cache_slow_ops', $panel_menu['object_cache']['children'][2]['id'] );
				$this->assertSame( 'qm-object_cache_prefetch', $panel_menu['object_cache']['children'][3]['id'] );
				$this->assertSame( array(), $outputters['object_cache_prefetch']->panel_menu( array() ) );

				$empty_slow = new FOCUS_QM_Collector_Object_Cache_Slow_Ops();
				$empty_slow_outputter = new FOCUS_QM_Output_Html_Object_Cache_Slow_Ops( $empty_slow );
				$this->assertSame(
					array( 'object_cache' => array( 'children' => array() ) ),
					$empty_slow_outputter->panel_menu( array( 'object_cache' => array( 'children' => array() ) ) )
				);

				$this->assertContains( 'qm-object_cache', $outputters['object_cache']->admin_class( array() ) );
				$this->assertContains( 'qm-object_cache_ops', $outputters['object_cache_ops']->admin_class( array() ) );
				$this->assertContains( 'qm-object_cache_group_stats', $outputters['object_cache_group_stats']->admin_class( array() ) );
				$this->assertContains( 'qm-object_cache_slow_ops', $outputters['object_cache_slow_ops']->admin_class( array() ) );
				$this->assertContains( 'qm-object_cache_prefetch', $outputters['object_cache_prefetch']->admin_class( array() ) );

				ob_start();
				$outputters['object_cache']->output();
				$object_output = ob_get_clean();
				$this->assertStringContainsString( 'Totals', $object_output );
				$this->assertStringContainsString( 'Query Time', $object_output );
				$this->assertStringContainsString( 'Operation Counts', $object_output );
				$this->assertStringNotContainsString( '>zero<', $object_output );

				ob_start();
				$outputters['object_cache_ops']->output();
				$ops_output = ob_get_clean();
				$this->assertStringContainsString( 'Operation', $ops_output );
				$this->assertStringContainsString( '[+2 more]', $ops_output );
				$this->assertStringContainsString( 'Not in Memcached', $ops_output );
				$this->assertStringContainsString( 'Found in Memcached', $ops_output );
				$this->assertStringContainsString( 'Already in Memcached', $ops_output );
				$this->assertStringContainsString( 'Local cache already', $ops_output );
				$this->assertStringContainsString( 'Total:', $ops_output );

				$collectors['object_cache_group_stats']->data->group_stats['bad'] = array(
					'bad_group' => 'not-an-array',
				);
				ob_start();
				$outputters['object_cache_group_stats']->output();
				$group_output = ob_get_clean();
				$this->assertStringContainsString( 'Group Stats for set', $group_output );
				$this->assertStringContainsString( 'Totals:', $group_output );
				$this->assertStringContainsString( 'default', $group_output );
				$this->assertStringNotContainsString( 'skip_group', $group_output );
				$this->assertStringNotContainsString( 'bad_group', $group_output );

				$collectors['object_cache_slow_ops']->data->slow_ops['bad'] = array( 'not-an-array' );
				ob_start();
				$outputters['object_cache_slow_ops']->output();
				$slow_output = ob_get_clean();
				$this->assertStringContainsString( 'Backtrace', $slow_output );
				$this->assertStringContainsString( 'slow1', $slow_output );
				$this->assertStringContainsString( 'Class-&gt;method', $slow_output );

				ob_start();
				$outputters['object_cache_prefetch']->output();
				$prefetch_output = ob_get_clean();
				$this->assertStringContainsString( 'Prefetch Summary', $prefetch_output );
				$this->assertStringContainsString( 'Individual Calls Avoided', $prefetch_output );
				$this->assertStringContainsString( 'Prefetched And Used', $prefetch_output );
				$this->assertStringContainsString( 'Prefetched But Unused', $prefetch_output );
				$this->assertStringContainsString( 'unused_key', $prefetch_output );

				$empty_prefetch_outputter = new FOCUS_QM_Output_Html_Prefetch( new FOCUS_QM_Collector_Prefetch() );
				ob_start();
				$empty_prefetch_outputter->output();
				$this->assertSame( '', ob_get_clean() );

				$collectors['object_cache_prefetch']->data->prefetch['used_groups'] = array();
				$collectors['object_cache_prefetch']->data->prefetch['unused_groups'] = array();
				ob_start();
				$outputters['object_cache_prefetch']->output();
				$summary_only_output = ob_get_clean();
				$this->assertStringContainsString( 'Prefetch Summary', $summary_only_output );
				$this->assertStringNotContainsString( 'Prefetched But Unused', $summary_only_output );

				$fallback_outputter = new FOCUS_QM_Output_Html_Object_Cache( new FOCUS_QM_Collector_Object_Cache() );
				$wp_object_cache = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					public function stats(): void {
						echo '<h2>Legacy Stats</h2>';
					}
				};
				ob_start();
				$fallback_outputter->output();
				$fallback_output = ob_get_clean();
				$this->assertStringContainsString( 'Legacy Stats', $fallback_output );
			} finally {
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
