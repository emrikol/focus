<?php

/**
 * Test FOCUS-specific implementations of core WordPress cache functionality
 * 
 * These tests cover functionality that gets skipped in the core WordPress tests
 * when an external object cache is in use. We need to ensure FOCUS behaves 
 * correctly for the same scenarios.
 * 
 * @group cache
 * @group focus
 * @group core-compat
 */
class Test_FOCUS_Core_Compatibility extends WP_UnitTestCase {
	
	private $cache;

	public function set_up() {
		parent::set_up();
		$this->cache = $this->init_cache();
	}

	public function tear_down() {
		$this->flush_focus_cache();
		parent::tear_down();
	}

	private function init_cache() {
		global $wp_object_cache;
		$cache_class = get_class( $wp_object_cache );
		$cache = new $cache_class();
		return $cache;
	}

	private function flush_focus_cache() {
		if ( $this->cache ) {
			$this->cache->flush();
		}
	}

	// =======================
	// KEY VALIDATION TESTS (FOCUS VERSION)
	// =======================

	/**
	 * Test that FOCUS properly validates cache keys
	 * 
	 * This replicates the core WordPress test_is_valid_key functionality
	 * but specifically for FOCUS object cache implementation
	 * 
	 * @dataProvider data_is_valid_key
	 * @covers WP_Object_Cache::is_valid_key
	 */
	public function test_focus_is_valid_key( $key, $valid ) {
		$val = 'test_value';

		if ( $valid ) {
			// Valid keys should work normally
			$this->assertTrue( $this->cache->add( $key, $val ), 'FOCUS should accept valid cache keys.' );
			$this->assertSame( $val, $this->cache->get( $key ), 'Valid keys should store and retrieve values correctly.' );
			
			// Test other operations with valid keys
			$this->assertTrue( $this->cache->set( $key, $val . '_set' ), 'Valid keys should work with set().' );
			$this->assertSame( $val . '_set', $this->cache->get( $key ), 'Set operation should update the value.' );
			
			$this->assertTrue( $this->cache->delete( $key ), 'Valid keys should work with delete().' );
			$this->assertFalse( $this->cache->get( $key ), 'Deleted keys should return false.' );
		} else {
			// Invalid keys should be rejected and trigger _doing_it_wrong notices
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::add' );
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::set' );
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::get' );
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::delete' );
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::replace' );
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::incr' );
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::decr' );
			
			$this->assertFalse( $this->cache->add( $key, $val ), 'FOCUS should reject invalid cache keys in add().' );
			$this->assertFalse( $this->cache->set( $key, $val ), 'FOCUS should reject invalid cache keys in set().' );
			$this->assertFalse( $this->cache->get( $key ), 'FOCUS should reject invalid cache keys in get().' );
			$this->assertFalse( $this->cache->delete( $key ), 'FOCUS should reject invalid cache keys in delete().' );
			$this->assertFalse( $this->cache->replace( $key, $val ), 'FOCUS should reject invalid cache keys in replace().' );
			$this->assertFalse( $this->cache->incr( $key ), 'FOCUS should reject invalid cache keys in incr().' );
			$this->assertFalse( $this->cache->decr( $key ), 'FOCUS should reject invalid cache keys in decr().' );
		}
	}

	/**
	 * Data provider for test_focus_is_valid_key()
	 * 
	 * Based on WordPress core data_is_valid_key() provider
	 * 
	 * @return array[] Test parameters {
	 *     @type mixed $key   Cache key value.
	 *     @type bool  $valid Whether the key should be considered valid.
	 * }
	 */
	public function data_is_valid_key() {
		return array(
			'false'          => array( false, false ),
			'null'           => array( null, false ),
			'line break'     => array( "\n", false ),
			'null character' => array( "\0", false ),
			'empty string'   => array( '', false ),
			'single space'   => array( ' ', false ),
			'two spaces'     => array( '  ', false ),
			'float 0'        => array( 0.0, false ),
			'int 0'          => array( 0, true ),
			'int 1'          => array( 1, true ),
			'string 0'       => array( '0', true ),
			'string'         => array( 'valid_key', true ),
		);
	}

	// =======================
	// FLUSH FUNCTIONALITY TESTS (FOCUS VERSION)
	// =======================

	/**
	 * Test that FOCUS flush() properly clears all cache data
	 * 
	 * This replicates the core WordPress test_flush functionality
	 * but specifically for FOCUS object cache implementation
	 */
	public function test_focus_flush() {
		$key1 = 'flush_test_1';
		$key2 = 'flush_test_2';
		$val1 = 'value_1';
		$val2 = 'value_2';

		// Set multiple cache items
		$this->assertTrue( $this->cache->add( $key1, $val1 ), 'First cache item should be added.' );
		$this->assertTrue( $this->cache->add( $key2, $val2 ), 'Second cache item should be added.' );

		// Verify items exist
		$this->assertSame( $val1, $this->cache->get( $key1 ), 'First item should be retrievable.' );
		$this->assertSame( $val2, $this->cache->get( $key2 ), 'Second item should be retrievable.' );

		// Flush cache
		$this->assertTrue( $this->cache->flush(), 'Flush should return true.' );

		// Verify all items are cleared
		$this->assertFalse( $this->cache->get( $key1 ), 'First item should be cleared after flush.' );
		$this->assertFalse( $this->cache->get( $key2 ), 'Second item should be cleared after flush.' );
	}

	/**
	 * Test that FOCUS flush() clears cache across different groups
	 */
	public function test_focus_flush_multiple_groups() {
		$key = 'multi_group_test';
		$val = 'test_value';
		$group1 = 'group_1';
		$group2 = 'group_2';

		// Set items in different groups
		$this->assertTrue( $this->cache->set( $key, $val . '_g1', $group1 ), 'Item should be set in group 1.' );
		$this->assertTrue( $this->cache->set( $key, $val . '_g2', $group2 ), 'Item should be set in group 2.' );

		// Verify items exist in their groups
		$this->assertSame( $val . '_g1', $this->cache->get( $key, $group1 ), 'Group 1 item should be retrievable.' );
		$this->assertSame( $val . '_g2', $this->cache->get( $key, $group2 ), 'Group 2 item should be retrievable.' );

		// Flush cache
		$this->cache->flush();

		// Verify all groups are cleared
		$this->assertFalse( $this->cache->get( $key, $group1 ), 'Group 1 item should be cleared after flush.' );
		$this->assertFalse( $this->cache->get( $key, $group2 ), 'Group 2 item should be cleared after flush.' );
	}

	/**
	 * Test that FOCUS flush() clears persistent file cache as well as memory cache
	 */
	public function test_focus_flush_clears_persistent_storage() {
		$key = 'persistent_flush_test';
		$val = 'persistent_value';

		// Set cache item
		$this->cache->set( $key, $val );
		$this->assertSame( $val, $this->cache->get( $key ), 'Value should be cached.' );

		// Create a new cache instance to simulate a fresh request
		$new_cache = $this->init_cache();
		$this->assertSame( $val, $new_cache->get( $key ), 'Value should persist across cache instances.' );

		// Flush from original cache
		$this->cache->flush();

		// Verify that even a new cache instance doesn't find the data
		$fresh_cache = $this->init_cache();
		$this->assertFalse( $fresh_cache->get( $key ), 'Flush should clear persistent storage.' );
	}

	// =======================
	// FOCUS-SPECIFIC EDGE CASE TESTS
	// =======================

	/**
	 * Test that FOCUS handles edge cases in key validation properly
	 */
	public function test_focus_key_validation_edge_cases() {
		// Test whitespace-only strings (should trigger _doing_it_wrong notices)
		$whitespace_keys = array( "\t", "\r", "\n", "\r\n", "   \t\n   " );
		
		foreach ( $whitespace_keys as $key ) {
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::set' );
			$this->assertFalse( $this->cache->set( $key, 'value' ), "Whitespace-only key should be rejected: " . json_encode( $key ) );
		}

		// Test reasonably long keys (FOCUS should handle them, but filesystem has limits)
		$long_key = str_repeat( 'a', 200 );  // Reduced from 1000 to avoid filesystem limits
		$this->assertTrue( $this->cache->set( $long_key, 'long_key_value' ), 'Long key should be accepted.' );
		$this->assertSame( 'long_key_value', $this->cache->get( $long_key ), 'Long key should work correctly.' );

		// Test keys with special characters (should be valid strings)
		$special_keys = array( 'key-with-dashes', 'key_with_underscores', 'key.with.dots', 'key:with:colons' );
		
		foreach ( $special_keys as $key ) {
			$this->assertTrue( $this->cache->set( $key, "value_for_$key" ), "Special character key should be accepted: $key" );
			$this->assertSame( "value_for_$key", $this->cache->get( $key ), "Special character key should work: $key" );
		}
	}

	/**
	 * Test that FOCUS properly handles mixed data types in cache operations
	 */
	public function test_focus_mixed_data_types() {
		$test_data = array(
			'string' => 'string_value',
			'integer' => 42,
			'float' => 3.14159,
			'boolean_true' => true,
			'boolean_false' => false,
			'array' => array( 'nested' => 'value' ),
			'object' => (object) array( 'property' => 'value' ),
			'null' => null,
		);

		foreach ( $test_data as $key => $value ) {
			$cache_key = "mixed_type_$key";
			
			$this->assertTrue( $this->cache->set( $cache_key, $value ), "Should be able to cache $key data type." );
			
			if ( $key === 'boolean_false' || $key === 'null' ) {
				// These should be stored but might return false from get()
				$found = null;
				$retrieved = $this->cache->get( $cache_key, 'default', false, $found );
				$this->assertTrue( $found, "Should find cached $key value even if it's falsy." );
			} elseif ( $key === 'object' ) {
				// Objects get serialized/unserialized, so use assertEquals instead of assertSame
				$this->assertEquals( $value, $this->cache->get( $cache_key ), "Should retrieve correct $key value." );
			} else {
				$this->assertSame( $value, $this->cache->get( $cache_key ), "Should retrieve correct $key value." );
			}
		}
	}

	/**
	 * Test FOCUS behavior with WordPress core wp_cache_* functions
	 */
	public function test_focus_with_wp_cache_functions() {
		// Test that wp_cache_* functions work correctly with FOCUS
		$key = 'wp_function_test';
		$val = 'wp_function_value';

		// Test wp_cache_set/get
		$this->assertTrue( wp_cache_set( $key, $val ), 'wp_cache_set should work with FOCUS.' );
		$this->assertSame( $val, wp_cache_get( $key ), 'wp_cache_get should work with FOCUS.' );

		// Test wp_cache_add
		$this->assertFalse( wp_cache_add( $key, 'new_value' ), 'wp_cache_add should return false for existing key.' );
		$this->assertSame( $val, wp_cache_get( $key ), 'wp_cache_add should not overwrite existing value.' );

		// Test wp_cache_delete
		$this->assertTrue( wp_cache_delete( $key ), 'wp_cache_delete should work with FOCUS.' );
		$this->assertFalse( wp_cache_get( $key ), 'wp_cache_get should return false after delete.' );

		// Test wp_cache_flush
		wp_cache_set( 'flush_test_1', 'value1' );
		wp_cache_set( 'flush_test_2', 'value2' );
		$this->assertTrue( wp_cache_flush(), 'wp_cache_flush should work with FOCUS.' );
		$this->assertFalse( wp_cache_get( 'flush_test_1' ), 'wp_cache_flush should clear all cache.' );
		$this->assertFalse( wp_cache_get( 'flush_test_2' ), 'wp_cache_flush should clear all cache.' );
	}
}