<?php
declare(strict_types=1);

/**
 * Tests for the FOCUS database-backed object cache.
 *
 * @group cache
 * @group focus
 */
class Tests_Focus_Database_False_Prefetch_Key_Cache extends FOCUS_Database_Object_Cache {
	public function is_prefetch_enabled(): bool {
		return true;
	}

	public function get_prefetch_key(): string|false {
		return false;
	}
}

class Tests_Focus_Database_Non_Array_Results_Wpdb_Double {
	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	public function get_results( string $query, string $output = OBJECT ) {
		unset( $query, $output );
		return false;
	}
}

class Tests_Focus_Database_Mismatched_Results_Wpdb_Double {
	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		unset( $query, $output );
		return array(
			array(
				'bucket_hash' => strtoupper( md5( 'unexpected_bucket' ) ),
				'key_hash' => strtoupper( md5( 'unexpected_key' ) ),
				'cache_key' => 'unexpected_key',
				'cache_value' => serialize( 'unexpected_value' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				'expires_at' => time() + 300,
			),
		);
	}
}

class Tests_Focus_Database_Query_Failure_Wpdb_Double {
	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	public function query( string $query ): bool {
		unset( $query );
		return false;
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		unset( $query, $output );
		return array(
			array(
				'key_hash' => strtoupper( md5( 'missing' ) ),
			),
		);
	}
}

class Tests_Focus_Database_Invalid_Prefetch_Rows_Wpdb_Double {
	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );
		return $query;
	}

	public function get_results( string $query, string $output = OBJECT ): array {
		unset( $query, $output );

		return array(
			array(
				'cache_group' => '',
				'cache_key' => 'skipped_group',
				'bucket_hash' => strtoupper( md5( 'skipped_bucket' ) ),
				'key_hash' => strtoupper( md5( 'skipped_key' ) ),
				'cache_value' => null,
				'expires_at' => null,
			),
			array(
				'cache_group' => 'database_prefetch_invalid_rows',
				'cache_key' => " \n\t",
				'bucket_hash' => strtoupper( md5( 'skipped_bucket' ) ),
				'key_hash' => strtoupper( md5( 'skipped_key' ) ),
				'cache_value' => null,
				'expires_at' => null,
			),
			array(
				'cache_group' => 'database_prefetch_invalid_rows',
				'cache_key' => 'skipped_hashes',
				'bucket_hash' => '',
				'key_hash' => '',
				'cache_value' => null,
				'expires_at' => null,
			),
			array(
				'cache_group' => 'database_prefetch_invalid_rows',
				'cache_key' => 'valid_missing',
				'bucket_hash' => strtoupper( md5( 'valid_bucket' ) ),
				'key_hash' => strtoupper( md5( 'valid_key' ) ),
				'cache_value' => null,
				'expires_at' => null,
			),
		);
	}
}

class Tests_Focus_Database_Cache extends WP_UnitTestCase {
	private ?FOCUS_Database_Object_Cache $database_cache = null;

	public function tear_down() {
		if ( $this->database_cache instanceof FOCUS_Database_Object_Cache ) {
			$this->database_cache->flush();
		}

		parent::tear_down();
	}

	private function init_database_cache(): FOCUS_Database_Object_Cache {
		global $wpdb;

		$cache = new FOCUS_Database_Object_Cache();
		$cache->configure_backend( 'database' );

		if ( ! $cache->install_database_tables() ) {
			$this->fail(
				sprintf(
					'The FOCUS database backend schema could not be installed. Last DB error: %s. Last query: %s. Table: %s.',
					(string) $wpdb->last_error,
					(string) $wpdb->last_query,
					$cache->database_items_table
				)
			);
		}

		if ( ! $cache->is_database_backend() ) {
			$this->fail(
				sprintf(
					'The FOCUS database backend did not initialize. Last DB error: %s. Last query: %s. Table: %s.',
					(string) $wpdb->last_error,
					(string) $wpdb->last_query,
					$cache->database_items_table
				)
			);
		}

		$cache->flush();
		$this->database_cache = $cache;

		return $cache;
	}

	private function get_protected_method( FOCUS_Database_Object_Cache $cache, string $method ): ReflectionMethod {
		$reflection = new ReflectionMethod( $cache, $method );
		$reflection->setAccessible( true );

		return $reflection;
	}

	private function get_database_bucket_identity( FOCUS_Database_Object_Cache $cache, string $group ): array {
		return $this->get_protected_method( $cache, 'get_database_bucket_identity' )->invoke( $cache, $group );
	}

	private function insert_raw_database_item( FOCUS_Database_Object_Cache $cache, string $group, string $key, string $serialized_value, int $ttl = 300 ): string {
		global $wpdb;

		$identity = $this->get_database_bucket_identity( $cache, $group );

		$cache_key  = $cache->key( $key, $group );
		$table      = $cache->database_items_table;
		$expires_at = time() + $ttl;
		$now        = time();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (bucket_hash, key_hash, cache_key, cache_value, value_size, flags, expires_at, created_at, updated_at)
				VALUES (UNHEX(%s), UNHEX(%s), %s, %s, %d, 0, %d, %d, %d)
				ON DUPLICATE KEY UPDATE cache_key = VALUES(cache_key), cache_value = VALUES(cache_value), value_size = VALUES(value_size), flags = VALUES(flags), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)",
				$identity['bucket_hash'],
				md5( $cache_key ),
				$cache_key,
				$serialized_value,
				strlen( $serialized_value ),
				$expires_at,
				$now,
				$now
			)
		);

		return $cache_key;
	}

	public function test_database_backend_installs_schema_and_sets_backend() {
		global $wpdb;

		$runtime_cache = new FOCUS_Database_Object_Cache();
		$runtime_cache->configure_backend( 'database' );
		$this->assertSame( 'database', $runtime_cache->backend );
		$this->assertFalse( $runtime_cache->database_schema_checked );

		$cache = $this->init_database_cache();

		$this->assertSame( 'database', $cache->backend );
		$this->assertTrue( $cache->database_schema_checked );
		$this->assertSame( $wpdb->base_prefix . 'focus_cache_items', $cache->database_items_table );
		$this->assertSame( $wpdb->base_prefix . 'focus_cache_prefetch_keys', $cache->database_prefetch_table );

		$this->assertNotEmpty( $wpdb->get_results( "DESCRIBE `{$cache->database_items_table}`" ) );
		$this->assertNotEmpty( $wpdb->get_results( "DESCRIBE `{$cache->database_prefetch_table}`" ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache->database_buckets_table ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache->database_meta_table ) ) );
	}

	public function test_database_backend_get_set_false_value_delete_and_expiration() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_get_set';
		$found = null;

		$this->assertTrue( $cache->set( 'false_key', false, $group ) );
		$this->assertFalse( $cache->get( 'false_key', $group, false, $found ) );
		$this->assertTrue( $found );

		$this->assertTrue( $cache->delete( 'false_key', $group ) );
		$this->assertFalse( $cache->get( 'false_key', $group, false, $found ) );
		$this->assertFalse( $found );

		$query_count = $wpdb->num_queries;
		$this->assertTrue( $cache->set( 'scalar_key', 'scalar_value', $group ) );
		$this->assertSame( 1, $wpdb->num_queries - $query_count );
		$cache->flush_runtime();
		$query_count = $wpdb->num_queries;
		$this->assertSame( 'scalar_value', $cache->get( 'scalar_key', $group, false, $found ) );
		$this->assertTrue( $found );
		$this->assertSame( 1, $wpdb->num_queries - $query_count );

		$query_count = $wpdb->num_queries;
		$this->assertSame( 'scalar_value', $cache->get( 'scalar_key', $group, false, $found ) );
		$this->assertTrue( $found );
		$this->assertSame( 0, $wpdb->num_queries - $query_count );

		$this->assertTrue( $cache->set( 'short_key', 'short_value', $group, 1 ) );
		sleep( 2 );

		$this->assertFalse( $cache->get( 'short_key', $group, false, $found ) );
		$this->assertFalse( $found );
	}

	public function test_database_backend_get_multiple_hydrates_runtime_cache() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_get_multiple';

		$cache->set( 'key_1', 'value_1', $group );
		$cache->set( 'key_2', 'value_2', $group );
		$cache->flush_runtime();

		$query_count = $wpdb->num_queries;
		$results = $cache->get_multiple( array( 'key_1', 'key_2', 'missing' ), $group );
		$this->assertSame( 1, $wpdb->num_queries - $query_count );

		$this->assertSame( 'value_1', $results['key_1'] );
		$this->assertSame( 'value_2', $results['key_2'] );
		$this->assertFalse( $results['missing'] );
		$this->assertArrayHasKey( $cache->key( 'key_1', $group ), $cache->cache[ $group ] );
		$this->assertArrayHasKey( $cache->key( 'key_2', $group ), $cache->cache[ $group ] );
	}

	public function test_database_backend_get_from_storage_records_hit_and_clones_objects() {
		$cache = $this->init_database_cache();
		$group = 'database_get_storage';
		$found = null;
		$value = (object) array( 'name' => 'database object' );

		$this->assertTrue( $cache->set( 'object_key', $value, $group ) );
		$cache->flush_runtime();

		$result = $cache->get( 'object_key', $group, false, $found, true );

		$this->assertTrue( $found );
		$this->assertEquals( $value, $result );
		$this->assertNotSame( $value, $result );
		$this->assertNotEmpty( $cache->group_ops[ $group ] );
		$this->assertStringContainsString( 'Hit (FOCUS DB): ' . $cache->key( 'object_key', $group ), implode( "\n", $cache->group_ops[ $group ] ) );
	}

	public function test_database_backend_batch_writes_and_deletes_use_database_storage() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_batch';

		$query_count = $wpdb->num_queries;
		$this->assertSame(
			array(
				'one' => true,
				'two' => true,
			),
			$cache->set_multiple(
				array(
					'one' => 'value_1',
					'two' => 'value_2',
				),
				$group
			)
		);
		$this->assertSame( 1, $wpdb->num_queries - $query_count );

		$cache->flush_runtime();

		$query_count = $wpdb->num_queries;
		$this->assertSame(
			array(
				'one' => false,
				'three' => true,
			),
			$cache->add_multiple(
				array(
					'one' => 'new_value',
					'three' => 'value_3',
				),
				$group
			)
		);
		$this->assertSame( 2, $wpdb->num_queries - $query_count );

		$query_count = $wpdb->num_queries;
		$this->assertSame(
			array(
				'one' => true,
				'two' => true,
				'missing' => false,
			),
			$cache->delete_multiple( array( 'one', 'two', 'missing' ), $group )
		);
		$this->assertSame( 2, $wpdb->num_queries - $query_count );
		$this->assertSame( 'value_3', $cache->get( 'three', $group ) );
	}

	public function test_database_backend_flush_group_deletes_group_rows() {
		$cache = $this->init_database_cache();
		$group = 'database_flush_group';
		$kept_group = 'database_flush_group_kept';

		$cache->set( 'flush_key', 'flush_value', $group );
		$cache->set( 'kept_key', 'kept_value', $kept_group );

		$this->assertTrue( $cache->flush_group( $group ) );
		$this->assertFalse( $cache->get( 'flush_key', $group ) );
		$this->assertSame( 'kept_value', $cache->get( 'kept_key', $kept_group ) );
	}

	public function test_database_backend_gc_removes_expired_rows() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_gc';

		$cache->set( 'expired_key', 'expired_value', $group, 1 );
		$cache->set( 'kept_key', 'kept_value', $group );
		sleep( 2 );

		$deleted = $cache->run_database_gc( 100 );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cache->database_items_table}`" );

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertSame( 1, $count );
		$this->assertSame( 'kept_value', $cache->get( 'kept_key', $group ) );
	}

	public function test_database_backend_guards_unavailable_database() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$original_wpdb = $wpdb;

		$cache->database_available = false;
		$this->assertSame( 0, $cache->run_database_gc( 100 ) );
		$cache->database_available = true;

		try {
			$wpdb = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->assertFalse( $cache->install_database_tables() );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$cache->database_available = false;
		$this->assertFalse( $this->get_protected_method( $cache, 'flush_database' )->invoke( $cache ) );
		$this->assertTrue( $this->get_protected_method( $cache, 'flush_database_group' )->invoke( $cache, 'database_unavailable' ) );
		$this->assertFalse( $this->get_protected_method( $cache, 'save_to_database' )->invoke( $cache, 'key', 'value', 'database_unavailable', 300 ) );
		$this->assertFalse( $this->get_protected_method( $cache, 'save_prefetch_groups_to_database' )->invoke( $cache, md5( 'database_unavailable' ), array( 'database_unavailable' => array( 'key' ) ) ) );
		$this->assertFalse( $this->get_protected_method( $cache, 'load_prefetch_request_from_database' )->invoke( $cache, md5( 'database_unavailable' ) ) );
		$this->assertFalse( $this->get_protected_method( $cache, 'delete_from_database' )->invoke( $cache, 'key', 'database_unavailable' ) );
		$cache->database_available = true;
	}

	public function test_database_backend_storage_defensive_branches() {
		global $wpdb;

		$cache = $this->init_database_cache();

		$get_expiration = $this->get_protected_method( $cache, 'get_expiration' );
		$load_from_database = $this->get_protected_method( $cache, 'load_from_database' );
		$load_multiple_from_database = $this->get_protected_method( $cache, 'load_multiple_from_database' );
		$save_to_database = $this->get_protected_method( $cache, 'save_to_database' );
		$delete_from_database = $this->get_protected_method( $cache, 'delete_from_database' );

		$this->assertTrue( $cache->set( 'memory_key', 'memory_value', 'database_no_wpdb' ) );

		$original_wpdb = $wpdb;
		try {
			$wpdb = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertSame( 0, $get_expiration->invoke( $cache, 'missing', 'database_no_wpdb' ) );
			$found = null;
			$this->assertSame( 'memory_value', $cache->get( 'memory_key', 'database_no_wpdb', false, $found ) );
			$this->assertTrue( $found );

			$found = true;
			$this->assertFalse( $load_from_database->invokeArgs( $cache, array( 'missing', 'database_no_wpdb', &$found ) ) );
			$this->assertFalse( $found );
			$this->assertSame( array(), $load_multiple_from_database->invoke( $cache, array( 'missing' => 'missing' ), 'database_no_wpdb' ) );
			$this->assertFalse( $save_to_database->invoke( $cache, 'key', 'value', 'database_no_wpdb', 300 ) );
			$this->assertFalse( $delete_from_database->invoke( $cache, 'key', 'database_no_wpdb' ) );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_database_backend_respects_non_persistent_groups_and_max_value_size() {
		$cache = $this->init_database_cache();
		$group = 'database_nonpersistent';
		$found = null;

		$cache->add_non_persistent_groups( array( $group ) );

		$this->assertFalse( $this->get_protected_method( $cache, 'save_to_database' )->invoke( $cache, 'key', 'value', $group, 0 ) );
		$this->assertSame( 0, $this->get_protected_method( $cache, 'get_expiration' )->invoke( $cache, 'key', $group ) );
		$this->assertTrue( $cache->set( 'key', 'runtime-only', $group ) );
		$cache->flush_runtime();
		$this->assertFalse( $cache->get( 'key', $group, false, $found ) );
		$this->assertFalse( $found );

		$cache->database_max_value_size = 5;
		$this->assertFalse( $cache->set( 'large', str_repeat( 'x', 50 ), 'database_max_value' ) );
		$cache->flush_runtime();
		$this->assertFalse( $cache->get( 'large', 'database_max_value', false, $found ) );
		$this->assertFalse( $found );
	}

	public function test_database_backend_batch_operations_handle_invalid_local_and_oversized_values() {
		$cache = $this->init_database_cache();
		$group = 'database_batch_defensive';

		$this->assertTrue( $cache->set( 'memory', 'value', $group ) );
		$cache->database_max_value_size = 5;

		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::add_multiple' );
		$this->assertSame(
			array(
				'' => false,
				'memory' => false,
				'large' => false,
			),
			$cache->add_multiple(
				array(
					'' => 'invalid',
					'memory' => 'new_value',
					'large' => str_repeat( 'x', 50 ),
				),
				$group
			)
		);
		$this->assertArrayNotHasKey( $cache->key( 'large', $group ), $cache->cache[ $group ] ?? array() );

		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::set_multiple' );
		$this->assertSame(
			array(
				'' => false,
				'large' => false,
			),
			$cache->set_multiple(
				array(
					'' => 'invalid',
					'large' => str_repeat( 'x', 50 ),
				),
				$group
			)
		);
		$this->assertArrayNotHasKey( $cache->key( 'large', $group ), $cache->cache[ $group ] ?? array() );

		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::delete_multiple' );
		$this->assertSame( array( '' => false ), $cache->delete_multiple( array( '' ), $group ) );

		$cache->database_max_value_size = WP_FOCUS_DATABASE_MAX_VALUE_SIZE;
		$cache->add_non_persistent_groups( array( 'database_batch_local' ) );
		$this->assertTrue( $cache->set( 'runtime', 'value', 'database_batch_local' ) );
		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::delete_multiple' );
		$this->assertSame(
			array(
				'' => false,
				'runtime' => true,
			),
			$cache->delete_multiple( array( '', 'runtime' ), 'database_batch_local' )
		);
	}

	public function test_database_backend_batch_storage_defensive_branches() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$save_multiple = $this->get_protected_method( $cache, 'save_multiple_to_database' );
		$delete_multiple = $this->get_protected_method( $cache, 'delete_multiple_from_database' );
		$save_prefetch = $this->get_protected_method( $cache, 'save_prefetch_groups_to_database' );

		$this->assertSame( array(), $save_multiple->invoke( $cache, array(), 'database_batch_defensive', 300 ) );
		$this->assertSame( array(), $delete_multiple->invoke( $cache, array(), 'database_batch_defensive' ) );
		$this->assertSame( array( 'missing' => false ), $delete_multiple->invoke( $cache, array( 'missing' => 'missing' ), 'database_batch_defensive' ) );
		$this->assertFalse( $save_prefetch->invoke( $cache, md5( 'empty-prefetch' ), array() ) );

		$original_wpdb = $wpdb;
		try {
			$wpdb = new Tests_Focus_Database_Non_Array_Results_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->assertSame( array( 'missing' => false ), $delete_multiple->invoke( $cache, array( 'missing' => 'missing' ), 'database_batch_defensive' ) );

			$wpdb = new Tests_Focus_Database_Query_Failure_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->assertSame(
				array( 'key' => false ),
				$save_multiple->invoke(
					$cache,
					array(
						'key' => array(
							'cache_key' => 'key',
							'value' => 'value',
						),
					),
					'database_batch_defensive',
					300
				)
			);
			$this->assertFalse( $save_prefetch->invoke( $cache, md5( 'query-failure' ), array( 'database_batch_defensive' => array( 'key' ) ) ) );
			$this->assertSame( array( 'missing' => false ), $delete_multiple->invoke( $cache, array( 'missing' => 'missing' ), 'database_batch_defensive' ) );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_database_backend_missing_rows_are_safe_noops() {
		$cache = $this->init_database_cache();
		$group = 'database_missing_rows';

		$this->assertSame( 0, $this->get_protected_method( $cache, 'get_expiration' )->invoke( $cache, 'missing', $group ) );
		$this->assertSame( array(), $this->get_protected_method( $cache, 'load_multiple_from_database' )->invoke( $cache, array(), $group ) );
		$this->assertFalse( $this->get_protected_method( $cache, 'delete_from_database' )->invoke( $cache, 'missing', 'database_missing_bucket' ) );
		$this->assertTrue( $cache->delete_group( 'database_missing_bucket' ) );
	}

	public function test_database_backend_handles_non_array_database_results() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_non_array_results';

		$load_multiple = $this->get_protected_method( $cache, 'load_multiple_from_database' );
		$load_prefetch = $this->get_protected_method( $cache, 'load_prefetch_request_from_database' );

		$original_wpdb = $wpdb;
		try {
			$wpdb = new Tests_Focus_Database_Non_Array_Results_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertSame( array(), $load_multiple->invoke( $cache, array( 'missing' => $cache->key( 'missing', $group ) ), $group ) );
			$this->assertFalse( $load_prefetch->invoke( $cache, md5( 'non-array-results' ) ) );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_database_backend_ignores_mismatched_database_results() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_mismatched_results';

		$load_multiple = $this->get_protected_method( $cache, 'load_multiple_from_database' );
		$load_prefetch = $this->get_protected_method( $cache, 'load_prefetch_request_from_database' );

		$original_wpdb = $wpdb;
		try {
			$wpdb = new Tests_Focus_Database_Mismatched_Results_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertSame( array(), $load_multiple->invoke( $cache, array( 'missing' => $cache->key( 'missing', $group ) ), $group ) );
			$this->assertFalse( $load_prefetch->invoke( $cache, md5( 'mismatched-results' ) ) );

			$this->assertArrayNotHasKey( 'missing', $cache->cache[ $group ] ?? array() );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_database_backend_corrupt_rows_are_ignored_and_deleted() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_corrupt';
		$found = null;

		$cache_key = $this->insert_raw_database_item( $cache, $group, 'corrupt_key', '' );
		$cache->flush_runtime();

		$this->assertFalse( $cache->get( 'corrupt_key', $group, false, $found ) );
		$this->assertFalse( $found );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$cache->database_items_table}` WHERE key_hash = UNHEX(%s)",
				md5( $cache_key )
			)
		);

		$this->assertSame( 0, $count );

		$multi_cache_key = $this->insert_raw_database_item( $cache, $group, 'corrupt_multi_key', '' );
		$cache->flush_runtime();

		$this->assertSame( array( 'corrupt_multi_key' => false ), $cache->get_multiple( array( 'corrupt_multi_key' ), $group ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$cache->database_items_table}` WHERE key_hash = UNHEX(%s)",
				md5( $multi_cache_key )
			)
		);

		$this->assertSame( 0, $count );
	}

	public function test_database_backend_base_fallback_methods() {
		$cache = new FOCUS_File_Object_Cache();

		$cache->configure_backend( 'db' );

		$this->assertSame( 'file', $cache->requested_backend );
		$this->assertSame( 'file', $cache->backend );
		$this->assertFalse( $cache->is_database_backend() );
		$this->assertFalse( $cache->install_database_tables() );
		$this->assertSame( 0, $cache->run_database_gc( 100 ) );
	}

	public function test_database_backend_prefetch_hydrates_multiple_groups() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$cache->test_prefetch_enabled = true;
		$_SERVER['HTTP_HOST'] = 'example.com';
		$_SERVER['REQUEST_URI'] = '/database-prefetch';
		unset( $_SERVER['HTTPS'], $_SERVER['QUERY_STRING'] );

		$group_1 = 'database_prefetch_one';
		$group_2 = 'database_prefetch_two';

		$cache->set( 'key_1', 'value_1', $group_1 );
		$cache->set( 'key_2', 'value_2', $group_2 );

		$query_count = $wpdb->num_queries;
		$cache->save_prefetch_manifest();
		$this->assertSame( 1, $wpdb->num_queries - $query_count );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cache->database_prefetch_table}`" ) );

		$fresh_cache = new FOCUS_Database_Object_Cache();
		$fresh_cache->configure_backend( 'database' );
		$fresh_cache->test_prefetch_enabled = true;

		$query_count = $wpdb->num_queries;
		$fresh_cache->load_prefetch_manifest();
		$this->assertSame( 1, $wpdb->num_queries - $query_count );

		$this->assertSame( 'value_1', $fresh_cache->cache[ $group_1 ][ $fresh_cache->key( 'key_1', $group_1 ) ] );
		$this->assertSame( 'value_2', $fresh_cache->cache[ $group_2 ][ $fresh_cache->key( 'key_2', $group_2 ) ] );

		$object_cache_stats = $fresh_cache->get_stats();
		$this->assertSame( 2, $object_cache_stats['operation_counts']['get_multiple'] );
		$this->assertContains( $group_1, $object_cache_stats['groups'] );
		$this->assertContains( $group_2, $object_cache_stats['groups'] );

		$get_multiple_operations = array();
		foreach ( $object_cache_stats['operations']['get_multiple'] as $operation ) {
			$get_multiple_operations[ $operation['group'] ] = $operation;
		}

		$this->assertSame( array( $fresh_cache->key( 'key_1', $group_1 ) ), $get_multiple_operations[ $group_1 ]['key'] );
		$this->assertSame( '1 hits, 0 misses', $get_multiple_operations[ $group_1 ]['result'] );
		$this->assertSame( array( $fresh_cache->key( 'key_2', $group_2 ) ), $get_multiple_operations[ $group_2 ]['key'] );
		$this->assertSame( '1 hits, 0 misses', $get_multiple_operations[ $group_2 ]['result'] );

		$loaded_stats = $fresh_cache->get_prefetch_stats();
		$this->assertSame( 'database', $loaded_stats['backend'] );
		$this->assertTrue( $loaded_stats['manifest_found'] );
		$this->assertSame( 2, $loaded_stats['manifest_groups'] );
		$this->assertSame( 2, $loaded_stats['manifest_keys'] );
		$this->assertSame( 2, $loaded_stats['requested_keys'] );
		$this->assertSame( 2, $loaded_stats['loaded_keys'] );
		$this->assertSame( 0, $loaded_stats['used_keys'] );
		$this->assertSame( 2, $loaded_stats['unused_keys'] );
		$this->assertSame( 1, $loaded_stats['load_operations'] );

		$this->assertSame( 'value_1', $fresh_cache->get( 'key_1', $group_1 ) );

		$used_stats = $fresh_cache->get_prefetch_stats();
		$this->assertSame( 1, $used_stats['used_keys'] );
		$this->assertSame( 1, $used_stats['unused_keys'] );
		$this->assertSame( 1, $used_stats['calls_saved'] );
		$this->assertSame( 0, $used_stats['net_calls_saved'] );
		$this->assertArrayHasKey( $group_1, $used_stats['used_groups'] );
		$this->assertArrayHasKey( $group_2, $used_stats['unused_groups'] );

		$this->database_cache = $fresh_cache;
	}

	public function test_database_backend_prefetch_records_misses_as_request_local_negative_cache() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$cache->test_prefetch_enabled = true;
		$_SERVER['HTTP_HOST'] = 'example.com';
		$_SERVER['REQUEST_URI'] = '/database-prefetch-misses';
		unset( $_SERVER['HTTPS'], $_SERVER['QUERY_STRING'] );

		$group = 'database_prefetch_misses';
		$cache->set( 'hit', 'hit_value', $group );

		$prefetch_key = $cache->get_prefetch_key();
		$this->assertIsString( $prefetch_key );

		$save_groups = $this->get_protected_method( $cache, 'save_prefetch_groups_to_database' );
		$this->assertTrue( $save_groups->invoke( $cache, $prefetch_key, array( $group => array( 'hit', 'missing' ) ) ) );

		$fresh_cache = new FOCUS_Database_Object_Cache();
		$fresh_cache->configure_backend( 'database' );
		$fresh_cache->test_prefetch_enabled = true;

		$query_count = $wpdb->num_queries;
		$fresh_cache->load_prefetch_manifest();
		$this->assertSame( 1, $wpdb->num_queries - $query_count );

		$hit_key = $fresh_cache->key( 'hit', $group );
		$missing_key = $fresh_cache->key( 'missing', $group );

		$this->assertSame( 'hit_value', $fresh_cache->cache[ $group ][ $hit_key ] );
		$this->assertArrayHasKey( $group, $fresh_cache->database_misses );
		$this->assertArrayHasKey( $missing_key, $fresh_cache->database_misses[ $group ] );

		$found = true;
		$query_count = $wpdb->num_queries;
		$this->assertFalse( $fresh_cache->get( 'missing', $group, false, $found ) );
		$this->assertFalse( $found );
		$this->assertSame( 0, $wpdb->num_queries - $query_count );

		$query_count = $wpdb->num_queries;
		$this->assertSame( array( 'missing' => false ), $fresh_cache->get_multiple( array( 'missing' ), $group ) );
		$this->assertSame( 0, $wpdb->num_queries - $query_count );

		$object_cache_stats = $fresh_cache->get_stats();
		$this->assertSame( '1 hits, 1 misses', $object_cache_stats['operations']['get_multiple'][0]['result'] );
		$this->assertSame( 'prefetch_miss', $object_cache_stats['operations']['get_local'][0]['result'] );

		$prefetch_stats = $fresh_cache->get_prefetch_stats();
		$this->assertSame( 2, $prefetch_stats['requested_keys'] );
		$this->assertSame( 2, $prefetch_stats['loaded_keys'] );
		$this->assertSame( 0, $prefetch_stats['missing_keys'] );
		$this->assertSame( 1, $prefetch_stats['used_keys'] );
		$this->assertSame( 1, $prefetch_stats['unused_keys'] );
		$this->assertSame( 1, $prefetch_stats['calls_saved'] );

		$query_count = $wpdb->num_queries;
		$fresh_cache->save_prefetch_manifest();
		$this->assertSame( 1, $wpdb->num_queries - $query_count );

		$this->database_cache = $fresh_cache;
	}

	public function test_database_backend_prefetch_ignores_disabled_missing_and_false_key_manifests() {
		global $wpdb;

		$cache = $this->init_database_cache();

		$cache->load_prefetch_manifest();
		$query_count = $wpdb->num_queries;
		$cache->save_prefetch_manifest();
		$this->assertSame( 0, $wpdb->num_queries - $query_count );

		$cache->test_prefetch_enabled = true;
		$_SERVER['HTTP_HOST'] = 'example.com';
		$_SERVER['REQUEST_URI'] = '/database-prefetch-missing';
		unset( $_SERVER['HTTPS'], $_SERVER['QUERY_STRING'] );
		$cache->load_prefetch_manifest();
		$query_count = $wpdb->num_queries;
		$cache->save_prefetch_manifest();
		$this->assertSame( 0, $wpdb->num_queries - $query_count );

		$false_key_cache = new Tests_Focus_Database_False_Prefetch_Key_Cache();
		$false_key_cache->configure_backend( 'database' );
		$false_key_cache->load_prefetch_manifest();
		$query_count = $wpdb->num_queries;
		$false_key_cache->save_prefetch_manifest();
		$this->assertSame( 0, $wpdb->num_queries - $query_count );

		$this->assertTrue( $cache->is_database_backend() );
		$this->assertTrue( $false_key_cache->is_database_backend() );

		$this->database_cache = $false_key_cache;
	}

	public function test_database_backend_prefetch_skips_invalid_groups_and_corrupt_rows() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$save_groups = $this->get_protected_method( $cache, 'save_prefetch_groups_to_database' );
		$load_request = $this->get_protected_method( $cache, 'load_prefetch_request_from_database' );
		$merge_groups = $this->get_protected_method( $cache, 'merge_database_miss_prefetch_groups' );
		$prefetch_key = md5( 'database_prefetch_skips' );

		$cache->add_non_persistent_groups( array( 'database_prefetch_nonpersistent' ) );
		$this->assertFalse(
			$save_groups->invoke(
				$cache,
				$prefetch_key,
				array(
					$cache->prefetch_group => array( 'skip_prefetch_group' ),
					123 => array( 'skip_non_string_group' ),
					'database_prefetch_empty' => array(),
					'database_prefetch_bad_keys' => array( '', " \n\t", null ),
					'database_prefetch_nonpersistent' => array( 'skip_bucket' ),
				)
			)
		);

		$cache->database_misses = array(
			123 => array( 'skip_non_string_group' => true ),
			$cache->prefetch_group => array( 'skip_prefetch_group' => true ),
			'database_prefetch_nonpersistent' => array( 'skip_nonpersistent' => true ),
			'database_prefetch_empty_misses' => array(),
		);
		$this->assertSame( array(), $merge_groups->invoke( $cache, array() ) );
		$cache->database_misses = array();

		$this->assertFalse( $load_request->invoke( $cache, md5( 'database_prefetch_missing_rows' ) ) );

		$group = 'database_prefetch_corrupt';
		$cache_key = $this->insert_raw_database_item( $cache, $group, 'corrupt_prefetch_key', '' );
		$this->assertTrue(
			$save_groups->invoke(
				$cache,
				$prefetch_key,
				array( $group => array( 'corrupt_prefetch_key' ) )
			)
		);
		$this->assertTrue( $load_request->invoke( $cache, $prefetch_key ) );

		$this->assertArrayNotHasKey( $cache_key, $cache->cache[ $group ] ?? array() );
		$this->assertArrayHasKey( $cache_key, $cache->database_misses[ $group ] );

		$suppress_errors = $wpdb->suppress_errors( true );
		try {
			$cache->database_prefetch_table = $wpdb->base_prefix . 'focus_cache_missing_prefetch';
			$this->assertFalse( $load_request->invoke( $cache, $prefetch_key ) );
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
			$cache->configure_backend( 'database' );
		}

		$original_wpdb = $wpdb;
		try {
			$wpdb = new Tests_Focus_Database_Invalid_Prefetch_Rows_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->assertTrue( $load_request->invoke( $cache, md5( 'database_prefetch_invalid_rows' ) ) );
			$this->assertArrayHasKey( 'valid_missing', $cache->database_misses['database_prefetch_invalid_rows'] );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_database_bucket_scopes_distinguish_blog_global_and_network_groups() {
		$cache = $this->init_database_cache();

		$identity_method = new ReflectionMethod( $cache, 'get_database_bucket_identity' );
		$identity_method->setAccessible( true );

		$cache->database_network_id = 1;
		$cache->database_blog_id = 11;
		$blog_bucket_1 = $identity_method->invoke( $cache, 'blog_group' );

		$cache->database_blog_id = 12;
		$blog_bucket_2 = $identity_method->invoke( $cache, 'blog_group' );

		$cache->add_global_groups( array( 'global_group' ) );
		$cache->database_blog_id = 11;
		$global_bucket_1 = $identity_method->invoke( $cache, 'global_group' );
		$cache->database_blog_id = 12;
		$global_bucket_2 = $identity_method->invoke( $cache, 'global_group' );

		$cache->add_network_groups( array( 'network_group' ) );
		$cache->database_network_id = 1;
		$cache->database_blog_id = 11;
		$network_bucket_1 = $identity_method->invoke( $cache, 'network_group' );
		$cache->database_blog_id = 12;
		$network_bucket_2 = $identity_method->invoke( $cache, 'network_group' );
		$cache->database_network_id = 2;
		$network_bucket_3 = $identity_method->invoke( $cache, 'network_group' );

		$this->assertNotSame( $blog_bucket_1['bucket_hash'], $blog_bucket_2['bucket_hash'] );
		$this->assertSame( $global_bucket_1['bucket_hash'], $global_bucket_2['bucket_hash'] );
		$this->assertSame( $network_bucket_1['bucket_hash'], $network_bucket_2['bucket_hash'] );
		$this->assertNotSame( $network_bucket_1['bucket_hash'], $network_bucket_3['bucket_hash'] );
	}
}
