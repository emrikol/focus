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
				'generation' => 999,
				'key_hash' => strtoupper( md5( 'unexpected_key' ) ),
				'cache_key' => 'unexpected_key',
				'cache_value' => serialize( 'unexpected_value' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				'expires_at' => time() + 300,
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

		if ( ! $cache->is_database_backend() ) {
			$this->fail(
				sprintf(
					'The FOCUS database backend did not initialize. Last DB error: %s. Last query: %s. Tables: %s, %s, %s.',
					(string) $wpdb->last_error,
					(string) $wpdb->last_query,
					$cache->database_buckets_table,
					$cache->database_items_table,
					$cache->database_meta_table
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

	private function get_database_bucket( FOCUS_Database_Object_Cache $cache, string $group, bool $create = true ): array|false {
		return $this->get_protected_method( $cache, 'get_database_bucket' )->invoke( $cache, $group, $create );
	}

	private function insert_raw_database_item( FOCUS_Database_Object_Cache $cache, string $group, string $key, string $serialized_value, int $ttl = 300 ): string {
		global $wpdb;

		$bucket = $this->get_database_bucket( $cache, $group, true );
		$this->assertIsArray( $bucket );

		$cache_key  = $cache->key( $key, $group );
		$table      = $cache->database_items_table;
		$expires_at = time() + $ttl;
		$now        = time();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (bucket_hash, generation, key_hash, cache_key, cache_value, value_size, flags, expires_at, created_at, updated_at)
				VALUES (UNHEX(%s), %d, UNHEX(%s), %s, %s, %d, 0, %d, %d, %d)
				ON DUPLICATE KEY UPDATE cache_key = VALUES(cache_key), cache_value = VALUES(cache_value), value_size = VALUES(value_size), flags = VALUES(flags), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)",
				$bucket['bucket_hash'],
				$bucket['generation'],
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

		$cache = $this->init_database_cache();

		$this->assertSame( 'database', $cache->backend );
		$this->assertTrue( $cache->database_schema_checked );
		$this->assertSame( $wpdb->base_prefix . 'focus_cache_buckets', $cache->database_buckets_table );
		$this->assertSame( $wpdb->base_prefix . 'focus_cache_items', $cache->database_items_table );
		$this->assertSame( $wpdb->base_prefix . 'focus_cache_meta', $cache->database_meta_table );

		$this->assertNotEmpty( $wpdb->get_results( "DESCRIBE `{$cache->database_buckets_table}`" ) );
		$this->assertNotEmpty( $wpdb->get_results( "DESCRIBE `{$cache->database_items_table}`" ) );
		$this->assertNotEmpty( $wpdb->get_results( "DESCRIBE `{$cache->database_meta_table}`" ) );
		$this->assertSame( (string) WP_FOCUS_DATABASE_SCHEMA_VERSION, (string) $wpdb->get_var( "SELECT meta_value FROM `{$cache->database_meta_table}` WHERE meta_key = 'schema_version' LIMIT 1" ) );
	}

	public function test_database_backend_get_set_false_value_delete_and_expiration() {
		$cache = $this->init_database_cache();
		$group = 'database_get_set';
		$found = null;

		$this->assertTrue( $cache->set( 'false_key', false, $group ) );
			$this->assertFalse( $cache->get( 'false_key', $group, false, $found ) );
			$this->assertTrue( $found );

			$this->assertTrue( $cache->delete( 'false_key', $group ) );
			$this->assertFalse( $cache->get( 'false_key', $group, false, $found ) );
			$this->assertFalse( $found );

			$this->assertTrue( $cache->set( 'scalar_key', 'scalar_value', $group ) );
			$cache->flush_runtime();
			$this->assertSame( 'scalar_value', $cache->get( 'scalar_key', $group, false, $found ) );
			$this->assertTrue( $found );

			$this->assertTrue( $cache->set( 'short_key', 'short_value', $group, 1 ) );
			sleep( 2 );

		$this->assertFalse( $cache->get( 'short_key', $group, false, $found ) );
		$this->assertFalse( $found );
	}

	public function test_database_backend_get_multiple_hydrates_runtime_cache() {
		$cache = $this->init_database_cache();
		$group = 'database_get_multiple';

		$cache->set( 'key_1', 'value_1', $group );
		$cache->set( 'key_2', 'value_2', $group );
		$cache->flush_runtime();

		$results = $cache->get_multiple( array( 'key_1', 'key_2', 'missing' ), $group );

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
		$cache = $this->init_database_cache();
		$group = 'database_batch';

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

		$cache->flush_runtime();

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

		$this->assertSame(
			array(
				'one' => true,
				'two' => true,
				'missing' => false,
			),
			$cache->delete_multiple( array( 'one', 'two', 'missing' ), $group )
		);
		$this->assertSame( 'value_3', $cache->get( 'three', $group ) );
	}

	public function test_database_backend_flush_group_bumps_generation() {
		$cache = $this->init_database_cache();
		$group = 'database_flush_group';
		$kept_group = 'database_flush_group_kept';

		$bucket_method = new ReflectionMethod( $cache, 'get_database_bucket' );
		$bucket_method->setAccessible( true );

		$cache->set( 'flush_key', 'flush_value', $group );
		$cache->set( 'kept_key', 'kept_value', $kept_group );

		$before = $bucket_method->invoke( $cache, $group, false );

		$this->assertTrue( $cache->flush_group( $group ) );
		$this->assertFalse( $cache->get( 'flush_key', $group ) );
		$this->assertSame( 'kept_value', $cache->get( 'kept_key', $kept_group ) );

		$after = $bucket_method->invoke( $cache, $group, false );

		$this->assertIsArray( $before );
		$this->assertIsArray( $after );
		$this->assertGreaterThan( $before['generation'], $after['generation'] );
	}

	public function test_database_backend_gc_removes_expired_and_stale_generation_rows() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_gc';

		$cache->set( 'expired_key', 'expired_value', $group, 1 );
		$cache->set( 'stale_key', 'stale_value', $group );
		sleep( 2 );
		$cache->flush_group( $group );

		$deleted = $cache->run_database_gc( 100 );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cache->database_items_table}`" );

		$this->assertGreaterThanOrEqual( 2, $deleted );
		$this->assertSame( 0, $count );
	}

	public function test_database_backend_guards_unavailable_database_and_missing_tables() {
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

		$tables_exist = $this->get_protected_method( $cache, 'database_tables_exist' );

		$cache->database_buckets_table = '';
		$this->assertFalse( $tables_exist->invoke( $cache ) );

		$suppress_errors = $wpdb->suppress_errors( true );
		try {
			$cache->database_buckets_table = $wpdb->base_prefix . 'focus_cache_missing_buckets';
			$cache->database_items_table = $wpdb->base_prefix . 'focus_cache_missing_items';
			$cache->database_meta_table = $wpdb->base_prefix . 'focus_cache_missing_meta';

			$this->assertFalse( $tables_exist->invoke( $cache ) );
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
			$cache->configure_backend( 'database' );
		}
	}

	public function test_database_backend_schema_and_storage_defensive_branches() {
		global $wpdb;

		$cache = $this->init_database_cache();

		$schema_current = $this->get_protected_method( $cache, 'database_schema_current' );
		$tables_exist = $this->get_protected_method( $cache, 'database_tables_exist' );
		$get_expiration = $this->get_protected_method( $cache, 'get_expiration' );
		$load_from_database = $this->get_protected_method( $cache, 'load_from_database' );

		$this->assertTrue( $schema_current->invoke( $cache ) );
		$this->assertTrue( $tables_exist->invoke( $cache ) );

		$original_wpdb = $wpdb;
		try {
			$wpdb = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertFalse( $schema_current->invoke( $cache ) );
			$this->assertSame( 0, $get_expiration->invoke( $cache, 'missing', 'database_no_wpdb' ) );

			$found = true;
			$this->assertFalse( $load_from_database->invokeArgs( $cache, array( 'missing', 'database_no_wpdb', &$found ) ) );
			$this->assertFalse( $found );
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

	public function test_database_backend_missing_rows_and_buckets_are_safe_noops() {
		$cache = $this->init_database_cache();
		$group = 'database_missing_rows';

		$this->get_database_bucket( $cache, $group, true );

		$this->assertSame( 0, $this->get_protected_method( $cache, 'get_expiration' )->invoke( $cache, 'missing', $group ) );
		$this->assertSame( array(), $this->get_protected_method( $cache, 'load_multiple_from_database' )->invoke( $cache, array(), $group ) );
		$this->assertFalse( $this->get_protected_method( $cache, 'delete_from_database' )->invoke( $cache, 'missing', 'database_missing_bucket' ) );
		$this->assertTrue( $cache->delete_group( 'database_missing_bucket' ) );
	}

	public function test_database_backend_handles_non_array_database_results() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_non_array_results';
		$this->get_database_bucket( $cache, $group, true );

		$load_multiple = $this->get_protected_method( $cache, 'load_multiple_from_database' );
		$chunk_method = $this->get_protected_method( $cache, 'load_prefetch_request_chunk_from_database' );
		$bucket = $this->get_database_bucket( $cache, $group, false );
		$this->assertIsArray( $bucket );

		$original_wpdb = $wpdb;
		try {
			$wpdb = new Tests_Focus_Database_Non_Array_Results_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertSame( array(), $load_multiple->invoke( $cache, array( 'missing' => $cache->key( 'missing', $group ) ), $group ) );
			$chunk_method->invoke(
				$cache,
				array(
					$bucket['bucket_hash'] . ':' . $bucket['generation'] . ':' . md5( 'missing' ) => array(
						'group' => $group,
						'cache_key' => 'missing',
						'key_hash' => md5( 'missing' ),
						'bucket' => $bucket,
					),
				)
			);
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_database_backend_ignores_mismatched_database_results() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_mismatched_results';
		$this->get_database_bucket( $cache, $group, true );

		$load_multiple = $this->get_protected_method( $cache, 'load_multiple_from_database' );
		$chunk_method = $this->get_protected_method( $cache, 'load_prefetch_request_chunk_from_database' );
		$bucket = $this->get_database_bucket( $cache, $group, false );
		$this->assertIsArray( $bucket );

		$original_wpdb = $wpdb;
		try {
			$wpdb = new Tests_Focus_Database_Mismatched_Results_Wpdb_Double(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			$this->assertSame( array(), $load_multiple->invoke( $cache, array( 'missing' => $cache->key( 'missing', $group ) ), $group ) );
			$chunk_method->invoke(
				$cache,
				array(
					$bucket['bucket_hash'] . ':' . $bucket['generation'] . ':' . md5( 'missing' ) => array(
						'group' => $group,
						'cache_key' => 'missing',
						'key_hash' => md5( 'missing' ),
						'bucket' => $bucket,
					),
				)
			);

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

	public function test_database_backend_alias_and_base_fallback_methods() {
		$cache = new FOCUS_File_Object_Cache();

		$cache->configure_backend( 'db' );

		$this->assertSame( 'database', $cache->requested_backend );
		$this->assertSame( 'file', $cache->backend );
		$this->assertFalse( $cache->is_database_backend() );
		$this->assertFalse( $cache->install_database_tables() );
		$this->assertSame( 0, $cache->run_database_gc( 100 ) );
	}

	public function test_database_backend_opportunistic_gc_can_run_deterministically() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$group = 'database_opportunistic_gc';
		$gc_method = $this->get_protected_method( $cache, 'maybe_run_database_gc' );

		$cache->set( 'expired_key', 'expired_value', $group, 1 );
		sleep( 2 );

		$cache->database_gc_probability = 1;
		$gc_method->invoke( $cache );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cache->database_items_table}`" );
		$this->assertSame( 0, $count );
	}

	public function test_database_backend_prefetch_hydrates_multiple_groups() {
		$cache = $this->init_database_cache();
		$cache->test_prefetch_enabled = true;
		$_SERVER['HTTP_HOST'] = 'example.com';
		$_SERVER['REQUEST_URI'] = '/database-prefetch';
		unset( $_SERVER['HTTPS'], $_SERVER['QUERY_STRING'] );

		$group_1 = 'database_prefetch_one';
		$group_2 = 'database_prefetch_two';

		$cache->set( 'key_1', 'value_1', $group_1 );
		$cache->set( 'key_2', 'value_2', $group_2 );
		$cache->save_prefetch_manifest();

		$fresh_cache = new FOCUS_Database_Object_Cache();
		$fresh_cache->configure_backend( 'database' );
		$fresh_cache->test_prefetch_enabled = true;
		$fresh_cache->load_prefetch_manifest();

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

	public function test_database_backend_prefetch_ignores_disabled_missing_and_false_key_manifests() {
		$cache = $this->init_database_cache();

		$cache->load_prefetch_manifest();

		$cache->test_prefetch_enabled = true;
		$_SERVER['HTTP_HOST'] = 'example.com';
		$_SERVER['REQUEST_URI'] = '/database-prefetch-missing';
		unset( $_SERVER['HTTPS'], $_SERVER['QUERY_STRING'] );
		$cache->load_prefetch_manifest();

		$false_key_cache = new Tests_Focus_Database_False_Prefetch_Key_Cache();
		$false_key_cache->configure_backend( 'database' );
		$false_key_cache->load_prefetch_manifest();

		$this->assertTrue( $cache->is_database_backend() );
		$this->assertTrue( $false_key_cache->is_database_backend() );

		$this->database_cache = $false_key_cache;
	}

	public function test_database_backend_prefetch_skips_invalid_groups_and_corrupt_rows() {
		global $wpdb;

		$cache = $this->init_database_cache();
		$groups_method = $this->get_protected_method( $cache, 'load_prefetch_groups_from_database' );
		$chunk_method = $this->get_protected_method( $cache, 'load_prefetch_request_chunk_from_database' );

		$cache->add_non_persistent_groups( array( 'database_prefetch_nonpersistent' ) );
		$groups_method->invoke(
			$cache,
			array(
				$cache->prefetch_group => array( 'skip_prefetch_group' ),
				123 => array( 'skip_non_string_group' ),
				'database_prefetch_empty' => array(),
				'database_prefetch_bad_keys' => array( '', " \n\t", null ),
				'database_prefetch_nonpersistent' => array( 'skip_bucket' ),
			)
		);

		$chunk_method->invoke( $cache, array() );

		$group = 'database_prefetch_corrupt';
		$cache_key = $this->insert_raw_database_item( $cache, $group, 'corrupt_prefetch_key', '' );
		$groups_method->invoke( $cache, array( $group => array( 'corrupt_prefetch_key' ) ) );

		$this->assertArrayNotHasKey( $cache_key, $cache->cache[ $group ] ?? array() );

		$suppress_errors = $wpdb->suppress_errors( true );
		try {
			$bucket = $this->get_database_bucket( $cache, $group, false );
			$this->assertIsArray( $bucket );

			$cache->database_items_table = $wpdb->base_prefix . 'focus_cache_missing_items';
			$chunk_method->invoke(
				$cache,
				array(
					$bucket['bucket_hash'] . ':' . $bucket['generation'] . ':' . md5( 'missing' ) => array(
						'group' => $group,
						'cache_key' => 'missing',
						'key_hash' => md5( 'missing' ),
						'bucket' => $bucket,
					),
				)
			);
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
			$cache->configure_backend( 'database' );
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
