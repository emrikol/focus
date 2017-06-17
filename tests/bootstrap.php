<?php
/**
 * PHPUnit bootstrap file
 *
 * @package FOCUS
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/*
 * Borrowed from https://github.com/pantheon-systems/wp-redis/blob/e1027dc56b9e2e08541bcd63dcf785cd11d1a2d2/tests/phpunit/bootstrap.php
 */
if ( getenv( 'WP_CORE_DIR' ) ) {
	$_core_dir = getenv( 'WP_CORE_DIR' );
} elseif ( getenv( 'WP_DEVELOP_DIR' ) ) {
	$_core_dir = getenv( 'WP_DEVELOP_DIR' ) . '/src/';
} else {
	$_core_dir = '/tmp/wordpress';
}

// Easiest way to get this to where WordPress will load it.
copy( dirname( dirname( dirname( __FILE__ ) ) ) . '/focus/includes/object-cache.php', $_core_dir . '/wp-content/object-cache.php' );

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/focus.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
