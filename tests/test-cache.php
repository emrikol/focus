<?php

/**
 * Test the persistent object cache using core's cache tests
 * Borrowed from https://github.com/pantheon-systems/wp-redis/blob/e1027dc56b9e2e08541bcd63dcf785cd11d1a2d2/tests/phpunit/test-cache.php
 */
class CacheTest extends WP_UnitTestCase {

	private $cache;

	private static $exists_key;

	private static $get_key;

	private static $set_key;

	private static $incr_by_key;

	private static $decr_by_key;

	private static $delete_key;

	private static $flush_all_key;

	public function setUp() {
		parent::setUp();
		// create two cache objects with a shared cache dir
		// this simulates a typical cache situation, two separate requests interacting
		$this->cache =& $this->init_cache();
		$this->cache->cache_hits = 0;
		$this->cache->cache_misses = 0;
	}

	public function &init_cache() {
		$cache = new WP_Object_Cache();
		$cache->add_global_groups( array( 'global-cache-test', 'users', 'userlogins', 'usermeta', 'user_meta', 'site-transient', 'site-options', 'site-lookup', 'blog-lookup', 'blog-details', 'rss', 'global-posts', 'blog-id-cache' ) );
		return $cache;
	}

	public function test_miss() {
		$this->assertFalse( $this->cache->get( rand_str() ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
	}

	public function test_add_get() {
		$key = rand_str();
		$val = rand_str();

		$this->cache->add( $key, $val );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_add_get_0() {
		$key = rand_str();
		$val = 0;

		// you can store zero in the cache
		$this->cache->add( $key, $val );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

	}

	public function test_add_get_null() {
		$key = rand_str();
		$val = null;

		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertNull( $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

	}

	public function test_add() {
		$key = rand_str();
		$val1 = rand_str();
		$val2 = rand_str();

		// add $key to the cache
		$this->assertTrue( $this->cache->add( $key, $val1 ) );
		$this->assertEquals( $val1, $this->cache->get( $key ) );
		// $key is in the cache, so reject new calls to add()
		$this->assertFalse( $this->cache->add( $key, $val2 ) );
		$this->assertEquals( $val1, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

	}

	public function test_replace() {
		$key = rand_str();
		$val = rand_str();
		$val2 = rand_str();

		// memcached rejects replace() if the key does not exist
		$this->assertFalse( $this->cache->replace( $key, $val ) );
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertTrue( $this->cache->replace( $key, $val2 ) );
		$this->assertEquals( $val2, $this->cache->get( $key ) );
	}

	public function test_set() {
		$key = rand_str();
		$val1 = rand_str();
		$val2 = rand_str();

		// memcached accepts set() if the key does not exist
		$this->assertTrue( $this->cache->set( $key, $val1 ) );
		$this->assertEquals( $val1, $this->cache->get( $key ) );
		// Second set() with same key should be allowed
		$this->assertTrue( $this->cache->set( $key, $val2 ) );
		$this->assertEquals( $val2, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_flush() {

		$key = rand_str();
		$val = rand_str();

		$this->cache->add( $key, $val );
		// item is visible to both cache objects
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->flush();
		// If there is no value get returns false.
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
	}

	// Make sure objects are cloned going to and from the cache
	public function test_object_refs() {
		$key = rand_str();
		$object_a = new stdClass;
		$object_a->foo = 'alpha';
		$this->cache->set( $key, $object_a );
		$object_a->foo = 'bravo';
		$object_b = $this->cache->get( $key );
		$this->assertEquals( 'alpha', $object_b->foo );
		$object_b->foo = 'charlie';
		$this->assertEquals( 'bravo', $object_a->foo );

		$key = rand_str();
		$object_a = new stdClass;
		$object_a->foo = 'alpha';
		$this->cache->add( $key, $object_a );
		$object_a->foo = 'bravo';
		$object_b = $this->cache->get( $key );
		$this->assertEquals( 'alpha', $object_b->foo );
		$object_b->foo = 'charlie';
		$this->assertEquals( 'bravo', $object_a->foo );
	}

	public function test_get_already_exists_internal() {
		$key = rand_str();
		$this->cache->set( $key, 'alpha' );
		$this->assertEquals( 'alpha', $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_missing_persistent() {
		$key = rand_str();
		$this->cache->get( $key );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		$this->cache->get( $key );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
	}

	public function test_get_non_persistent_group() {
		$key = rand_str();
		$group = 'nonpersistent';
		$this->cache->add_non_persistent_groups( $group );
		$this->cache->get( $key, $group );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		$this->cache->get( $key, $group );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
		$this->cache->set( $key, 'alpha', $group );
		$this->cache->get( $key, $group );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
		$this->cache->get( $key, $group );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
	}

	public function test_get_false_value_persistent_cache() {
		$key = rand_str();
		$this->cache->set( $key, false );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$found = null;
		$this->assertFalse( $this->cache->get( $key, 'default', false, $found ) );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_true_value_persistent_cache() {
		$key = rand_str();
		$this->cache->set( $key, true );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$found = null;
		$this->assertTrue( $this->cache->get( $key, 'default', false, $found ) );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_null_value_persistent_cache() {
		$key = rand_str();
		$this->cache->set( $key, null );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$found = null;
		$this->assertNull( $this->cache->get( $key, 'default', false, $found ) );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_int_values_persistent_cache() {
		$key1 = rand_str();
		$key2 = rand_str();
		$this->cache->set( $key1, 123 );
		$this->cache->set( $key2, 0xf4c3b00c );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		// Should be upgraded to more strict comparison if change proposed in issue #181 is merged.
		$this->assertSame( 123, $this->cache->get( $key1 ) );
		$this->assertSame( 4106465292, $this->cache->get( $key2 ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_float_values_persistent_cache() {
		$key1 = rand_str();
		$key2 = rand_str();
		$this->cache->set( $key1, 123.456 );
		$this->cache->set( $key2, + 0123.45e6 );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$this->assertSame( 123.456, $this->cache->get( $key1 ) );
		$this->assertSame( 123450000.0, $this->cache->get( $key2 ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_string_values_persistent_cache() {
		$key1 = rand_str();
		$key2 = rand_str();
		$key3 = rand_str();
		$key4 = rand_str();
		$this->cache->set( $key1, 'a plain old string' );
		// To ensure numeric strings are not converted to integers.
		$this->cache->set( $key2, '42' );
		$this->cache->set( $key3, '123.456' );
		$this->cache->set( $key4, '+0123.45e6' );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$this->assertEquals( 'a plain old string', $this->cache->get( $key1 ) );
		$this->assertSame( '42', $this->cache->get( $key2 ) );
		$this->assertSame( '123.456', $this->cache->get( $key3 ) );
		$this->assertSame( '+0123.45e6', $this->cache->get( $key4 ) );
		$this->assertEquals( 4, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_array_values_persistent_cache() {
		$key = rand_str();
		$value = array( 'one', 2, true );
		$this->cache->set( $key, $value );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$this->assertEquals( $value, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_object_values_persistent_cache() {
		$key = rand_str();
		$value = new stdClass;
		$value->one = 'two';
		$value->three = 'four';
		$this->cache->set( $key, $value );
		$this->cache->cache_hits = 0; // reset everything
		$this->cache->cache_misses = 0; // reset everything
		$this->cache->cache = array(); // reset everything
		$this->assertEquals( $value, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_force() {

		$key = rand_str();
		$group = 'default';
		$this->cache->set( $key, 'alpha', $group );
		$this->assertEquals( 'alpha', $this->cache->get( $key, $group ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->cache[ $this->cache->_sanitize_cache_group( $group ) ][ $this->cache->_key( $key, $group ) ] = 'beta';
		$this->assertEquals( 'beta', $this->cache->get( $key, $group ) );
		$this->assertEquals( 'alpha', $this->cache->get( $key, $group, true ) );
		$this->assertEquals( 3, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_get_found() {
		$key = rand_str();
		$found = null;
		$this->cache->get( $key, 'default', false, $found );
		$this->assertFalse( $found );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		$this->cache->set( $key, 'alpha', 'default' );
		$this->cache->get( $key, 'default', false, $found );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
	}

	public function test_incr() {
		$key = rand_str();

		$this->assertFalse( $this->cache->incr( $key ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0 );
		$this->cache->incr( $key );
		$this->assertEquals( 1, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->incr( $key, 2 );
		$this->assertEquals( 3, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_incr_separate_groups() {
		$key = rand_str();
		$group1 = 'group1';
		$group2 = 'group2';

		$this->assertFalse( $this->cache->incr( $key, 1, $group1 ) );
		$this->assertFalse( $this->cache->incr( $key, 1, $group2 ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0, $group1 );
		$this->cache->incr( $key, 1, $group1 );
		$this->cache->set( $key, 0, $group2 );
		$this->cache->incr( $key, 1, $group2 );
		$this->assertEquals( 1, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 1, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->incr( $key, 2, $group1 );
		$this->cache->incr( $key, 1, $group2 );
		$this->assertEquals( 3, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 2, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 4, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_incr_never_below_zero() {
		$key = rand_str();
		$this->cache->set( $key, 1 );
		$this->assertEquals( 1, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->incr( $key, -2 );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_incr_non_persistent() {
		$key = rand_str();

		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->assertFalse( $this->cache->incr( $key, 1, 'nonpersistent' ) );

		$this->cache->set( $key, 0, 'nonpersistent' );
		$this->cache->incr( $key, 1, 'nonpersistent' );
		$this->assertEquals( 1, $this->cache->get( $key, 'nonpersistent' ) );

		$this->cache->incr( $key, 2, 'nonpersistent' );
		$this->assertEquals( 3, $this->cache->get( $key, 'nonpersistent' ) );
	}

	public function test_incr_non_persistent_never_below_zero() {
		$key = rand_str();
		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->cache->set( $key, 1, 'nonpersistent' );
		$this->assertEquals( 1, $this->cache->get( $key, 'nonpersistent' ) );
		$this->cache->incr( $key, -2, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );
	}

	public function test_wp_cache_incr() {
		$key = rand_str();

		$this->assertFalse( wp_cache_incr( $key ) );

		wp_cache_set( $key, 0 );
		wp_cache_incr( $key );
		$this->assertEquals( 1, wp_cache_get( $key ) );

		wp_cache_incr( $key, 2 );
		$this->assertEquals( 3, wp_cache_get( $key ) );
	}

	public function test_decr() {
		$key = rand_str();

		$this->assertFalse( $this->cache->decr( $key ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0 );
		$this->cache->decr( $key );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 3 );
		$this->cache->decr( $key );
		$this->assertEquals( 2, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->decr( $key, 2 );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 3, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_decr_separate_groups() {
		$key = rand_str();
		$group1 = 'group1';
		$group2 = 'group2';

		$this->assertFalse( $this->cache->decr( $key, 1, $group1 ) );
		$this->assertFalse( $this->cache->decr( $key, 1, $group2 ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0, $group1 );
		$this->cache->decr( $key, 1, $group1 );
		$this->cache->set( $key, 0, $group2 );
		$this->cache->decr( $key, 1, $group2 );
		$this->assertEquals( 0, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 0, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 3, $group1 );
		$this->cache->decr( $key, 1, $group1 );
		$this->cache->set( $key, 2, $group2 );
		$this->cache->decr( $key, 1, $group2 );
		$this->assertEquals( 2, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 1, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 4, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->decr( $key, 2, $group1 );
		$this->cache->decr( $key, 2, $group2 );
		$this->assertEquals( 0, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 0, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 6, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_decr_never_below_zero() {
		$key = rand_str();
		$this->cache->set( $key, 1 );
		$this->assertEquals( 1, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->decr( $key, 2 );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
	}

	public function test_decr_non_persistent() {
		$key = rand_str();

		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->assertFalse( $this->cache->decr( $key, 1, 'nonpersistent' ) );

		$this->cache->set( $key, 0, 'nonpersistent' );
		$this->cache->decr( $key, 1, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );

		$this->cache->set( $key, 3, 'nonpersistent' );
		$this->cache->decr( $key, 1, 'nonpersistent' );
		$this->assertEquals( 2, $this->cache->get( $key, 'nonpersistent' ) );

		$this->cache->decr( $key, 2, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );
	}

	public function test_decr_non_persistent_never_below_zero() {
		$key = rand_str();
		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->cache->set( $key, 1, 'nonpersistent' );
		$this->assertEquals( 1, $this->cache->get( $key, 'nonpersistent' ) );
		$this->cache->decr( $key, 2, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );
	}

	/**
	 * @group 21327
	 */
	public function test_wp_cache_decr() {
		$key = rand_str();

		$this->assertFalse( wp_cache_decr( $key ) );

		wp_cache_set( $key, 0 );
		wp_cache_decr( $key );
		$this->assertEquals( 0, wp_cache_get( $key ) );

		wp_cache_set( $key, 3 );
		wp_cache_decr( $key );
		$this->assertEquals( 2, wp_cache_get( $key ) );

		wp_cache_decr( $key, 2 );
		$this->assertEquals( 0, wp_cache_get( $key ) );
	}

	public function test_delete() {
		$key = rand_str();
		$val = rand_str();

		// Verify set
		$this->assertTrue( $this->cache->set( $key, $val ) );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		// Verify successful delete
		$this->assertTrue( $this->cache->delete( $key ) );
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );

		$this->assertFalse( $this->cache->delete( $key, 'default' ) );
	}

	public function test_wp_cache_delete() {
		$key = rand_str();
		$val = rand_str();

		// Verify set
		$this->assertTrue( wp_cache_set( $key, $val ) );
		$this->assertEquals( $val, wp_cache_get( $key ) );

		// Verify successful delete
		$this->assertTrue( wp_cache_delete( $key ) );
		$this->assertFalse( wp_cache_get( $key ) );

		// wp_cache_delete() does not have a $force method.
		// Delete returns (bool) true when key is not set and $force is true
		// $this->assertTrue( wp_cache_delete( $key, 'default', true ) );

		$this->assertFalse( wp_cache_delete( $key, 'default' ) );
	}

	public function test_delete_group() {
		$key1 = rand_str();
		$val1 = rand_str();
		$key2 = rand_str();
		$val2 = rand_str();
		$key3 = rand_str();
		$val3 = rand_str();
		$group = 'foo';
		$group2 = 'bar';

		// Set up the values
		$this->cache->set( $key1, $val1, $group );
		$this->cache->set( $key2, $val2, $group );
		$this->cache->set( $key3, $val3, $group2 );
		$this->assertEquals( $val1, $this->cache->get( $key1, $group ) );
		$this->assertEquals( $val2, $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertTrue( $this->cache->delete_group( $group ) );

		$this->assertFalse( $this->cache->get( $key1, $group ) );
		$this->assertFalse( $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertTrue( $this->cache->delete_group( $group ) );
	}

	public function test_delete_group_non_persistent() {
		$key1 = rand_str();
		$val1 = rand_str();
		$key2 = rand_str();
		$val2 = rand_str();
		$key3 = rand_str();
		$val3 = rand_str();
		$group = 'foo';
		$group2 = 'bar';
		$this->cache->add_non_persistent_groups( array( $group, $group2 ) );

		// Set up the values
		$this->cache->set( $key1, $val1, $group );
		$this->cache->set( $key2, $val2, $group );
		$this->cache->set( $key3, $val3, $group2 );
		$this->assertEquals( $val1, $this->cache->get( $key1, $group ) );
		$this->assertEquals( $val2, $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertTrue( $this->cache->delete_group( $group ) );

		$this->assertFalse( $this->cache->get( $key1, $group ) );
		$this->assertFalse( $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertFalse( $this->cache->delete_group( $group ) );
	}

	public function test_wp_cache_delete_group() {

		$key1 = rand_str();
		$val1 = rand_str();
		$key2 = rand_str();
		$val2 = rand_str();
		$key3 = rand_str();
		$val3 = rand_str();
		$group = 'foo';
		$group2 = 'bar';

		// Set up the values
		wp_cache_set( $key1, $val1, $group );
		wp_cache_set( $key2, $val2, $group );
		wp_cache_set( $key3, $val3, $group2 );
		$this->assertEquals( $val1, wp_cache_get( $key1, $group ) );
		$this->assertEquals( $val2, wp_cache_get( $key2, $group ) );
		$this->assertEquals( $val3, wp_cache_get( $key3, $group2 ) );

		$this->assertTrue( wp_cache_delete_group( $group ) );

		$this->assertFalse( wp_cache_get( $key1, $group ) );
		$this->assertFalse( wp_cache_get( $key2, $group ) );
		$this->assertEquals( $val3, wp_cache_get( $key3, $group2 ) );

		$this->assertTrue( wp_cache_delete_group( $group ) );
	}

	public function test_switch_to_blog() {
		if ( ! method_exists( $this->cache, 'switch_to_blog' ) ) {
			return;
		}

		$key = rand_str();
		$val = rand_str();
		$val2 = rand_str();

		if ( ! is_multisite() ) {
			// Single site ingnores switch_to_blog().
			$this->assertTrue( $this->cache->set( $key, $val ) );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->assertTrue( $this->cache->set( $key, $val2 ) );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
		} else {
			// Multisite should have separate per-blog caches
			$this->assertTrue( $this->cache->set( $key, $val ) );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertFalse( $this->cache->get( $key ) );
			$this->assertTrue( $this->cache->set( $key, $val2 ) );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertEquals( $val, $this->cache->get( $key ) );
		}

		// Global group
		$this->assertTrue( $this->cache->set( $key, $val, 'global-cache-test' ) );
		$this->assertEquals( $val, $this->cache->get( $key, 'global-cache-test' ) );
		$this->cache->switch_to_blog( 999 );
		$this->assertEquals( $val, $this->cache->get( $key, 'global-cache-test' ) );
		$this->assertTrue( $this->cache->set( $key, $val2, 'global-cache-test' ) );
		$this->assertEquals( $val2, $this->cache->get( $key, 'global-cache-test' ) );
		$this->cache->switch_to_blog( get_current_blog_id() );
		$this->assertEquals( $val2, $this->cache->get( $key, 'global-cache-test' ) );
	}

	public function test_wp_cache_replace() {
		$key  = 'my-key';
		$val1 = 'first-val';
		$val2 = 'second-val';

		$fake_key = 'my-fake-key';

		// Save the first value to cache and verify
		wp_cache_set( $key, $val1 );
		$this->assertEquals( $val1, wp_cache_get( $key ) );

		// Replace the value and verify
		wp_cache_replace( $key, $val2 );
		$this->assertEquals( $val2, wp_cache_get( $key ) );

		// Non-existant key should fail
		$this->assertFalse( wp_cache_replace( $fake_key, $val1 ) );

		// Make sure $fake_key is not stored
		$this->assertFalse( wp_cache_get( $fake_key ) );
	}

	public function tearDown() {
		parent::tearDown();
		$this->flush_cache();
	}

	/**
	 * Remove the object-cache.php from the place we've dropped it
	 */
	static function tearDownAfterClass() {
		// @codingStandardsIgnoreStart
		unlink( ABSPATH . 'wp-content/object-cache.php' );
		// @codingStandardsIgnoreEnd
	}
}