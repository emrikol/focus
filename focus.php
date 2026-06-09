<?php
/**
 * Plugin Name: FOCUS Object Cache
 * Plugin URI: http://wordpress.org/plugins/focus-object-cache/
 * Description: File-based Object Cache is Utterly Slow: An Object Caching Dropin for WordPress that uses the local file system.
 * Version: 1.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Text Domain: focus-cache
 * Author: Derrick Tennant
 * Author URI: https://emrikol.com/
 * GitHub Plugin URI: https://github.com/emrikol/focus/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Network: true
 *
 * @package WordPress
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FOCUS_PLUGIN_FILE' ) ) {
	define( 'FOCUS_PLUGIN_FILE', __FILE__ );
}

require __DIR__ . '/includes/class-focus-cache.php';
new FOCUS_Cache( FOCUS_PLUGIN_FILE );
