<?php
/**
 * PHPUnit bootstrap file for FOCUS Object Cache
 *
 * @package FOCUS
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// PHPUnit Polyfills fallbacks for older WP versions
if ( ! trait_exists( 'Yoast\PHPUnitPolyfills\Polyfills\AssertFileDirectory' ) ) {
	// Provide basic fallback if polyfills aren't available
	trait AssertFileDirectory {
		public function assertFileExists( $filename, $message = '' ) {
			$this->assertTrue( file_exists( $filename ), $message );
		}
	}
}

// Install the object cache DROP-IN before WordPress loads
// This must happen early to ensure WordPress uses FOCUS cache instead of default cache
$cache_source = dirname( __DIR__ ) . '/includes/object-cache.php';
$cache_dest = $_tests_dir . '/src/wp-content/object-cache.php';

// Ensure wp-content directory exists
if ( ! is_dir( dirname( $cache_dest ) ) ) {
	mkdir( dirname( $cache_dest ), 0755, true );
}

if ( file_exists( $cache_source ) ) {
	if ( file_exists( $cache_dest ) || is_link( $cache_dest ) ) {
		unlink( $cache_dest );
	}

	if ( ! symlink( $cache_source, $cache_dest ) ) {
		copy( $cache_source, $cache_dest );
	}

	echo "FOCUS object cache installed at: " . $cache_dest . "\n";
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_focus_plugin() {
	// Load the main plugin
	require dirname( __DIR__ ) . '/focus.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_focus_plugin' );

// Add some retry logic for database connections in CI/Docker environments
if ( getenv( 'CI' ) || getenv( 'DOCKER' ) ) {
	$db_retries = 5;
	while ( $db_retries > 0 ) {
		try {
			// Start up the WP testing environment
			require $_tests_dir . '/includes/bootstrap.php';
			break;
		} catch ( Exception $e ) {
			$db_retries--;
			if ( $db_retries === 0 ) {
				throw $e;
			}
			sleep( 1 );
		}
	}
} else {
	// Start up the WP testing environment
	require $_tests_dir . '/includes/bootstrap.php';
}

/**
 * Generate a random string for testing.
 * This function is normally provided by WordPress core tests.
 */
if ( ! function_exists( 'rand_str' ) ) {
	function rand_str( $length = 32 ) {
		return substr( md5( uniqid( rand(), true ) ), 0, $length );
	}
}
