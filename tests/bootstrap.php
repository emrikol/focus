<?php
/**
 * PHPUnit bootstrap file
 *
 * @package FOCUS
 */

// Composer autoloader
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// PHPUnit Polyfills for WordPress tests
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && file_exists( dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/' );
}

// WordPress test environment
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/*
 * Determine WordPress core directory for object cache installation.
 */
if ( getenv( 'WP_CORE_DIR' ) ) {
	$_core_dir = getenv( 'WP_CORE_DIR' );
} elseif ( getenv( 'WP_DEVELOP_DIR' ) ) {
	$_core_dir = getenv( 'WP_DEVELOP_DIR' ) . '/src/';
} else {
	$_core_dir = '/tmp/wordpress';
}

/**
 * Install object cache drop-in at the proper time.
 */
function _install_focus_object_cache() {
	if ( getenv( 'WP_CORE_DIR' ) ) {
		$_core_dir = getenv( 'WP_CORE_DIR' );
	} elseif ( getenv( 'WP_DEVELOP_DIR' ) ) {
		$_core_dir = getenv( 'WP_DEVELOP_DIR' ) . '/src/';
	} else {
		$_core_dir = '/tmp/wordpress';
	}
	
	copy( dirname( __DIR__ ) . '/includes/object-cache.php', $_core_dir . '/wp-content/object-cache.php' );
}

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/focus.php';
}

// Ensure required globals are set before WordPress initialization
if ( ! isset( $GLOBALS['blog_id'] ) ) {
	$GLOBALS['blog_id'] = 1;
}

if ( ! isset( $GLOBALS['table_prefix'] ) ) {
	$GLOBALS['table_prefix'] = 'wp_';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

/**
 * Generate a random string for testing.
 * This function is normally provided by WordPress core tests.
 */
if ( ! function_exists( 'rand_str' ) ) {
	function rand_str( $length = 32 ) {
		return substr( md5( uniqid( rand(), true ) ), 0, $length );
	}
}
