<?php

/**
 * Simple debug test to check cache behavior
 */
class Tests_Debug_Cache extends WP_UnitTestCase {

	public function test_debug_cache_status() {
		global $wp_object_cache;
		
		// Check what cache class is being used
		$cache_class = get_class($wp_object_cache);
		fwrite(STDERR, "DEBUG: Cache class: " . $cache_class . "\n");
		
		// Check if WordPress thinks we're using external cache
		$using_ext = wp_using_ext_object_cache();
		fwrite(STDERR, "DEBUG: wp_using_ext_object_cache(): " . ($using_ext ? 'true' : 'false') . "\n");
		
		// Check WP_CONTENT_DIR
		fwrite(STDERR, "DEBUG: WP_CONTENT_DIR: " . WP_CONTENT_DIR . "\n");
		
		// Check if object-cache.php exists
		$object_cache_file = WP_CONTENT_DIR . '/object-cache.php';
		fwrite(STDERR, "DEBUG: object-cache.php exists: " . (file_exists($object_cache_file) ? 'true' : 'false') . "\n");
		
		// Test basic cache operation
		wp_cache_set('debug_test', 'debug_value');
		$retrieved = wp_cache_get('debug_test');
		fwrite(STDERR, "DEBUG: Cache set/get test: " . ($retrieved === 'debug_value' ? 'PASS' : 'FAIL') . "\n");
		fwrite(STDERR, "DEBUG: Retrieved value: " . print_r($retrieved, true) . "\n");
		
		// Check for FOCUS-specific directory
		$focus_dir = WP_CONTENT_DIR . '/focus-object-cache';
		fwrite(STDERR, "DEBUG: FOCUS cache dir exists: " . (is_dir($focus_dir) ? 'true' : 'false') . "\n");
		
		// List cache directory contents if it exists
		if (is_dir($focus_dir)) {
			$contents = scandir($focus_dir);
			fwrite(STDERR, "DEBUG: FOCUS cache dir contents: " . print_r($contents, true) . "\n");
		}
		
		// Check cache methods available
		$methods = get_class_methods($wp_object_cache);
		fwrite(STDERR, "DEBUG: Available methods: " . implode(', ', $methods) . "\n");
		
		// Just pass the test - we're using this for debugging
		$this->assertTrue(true);
	}
}