<?php
/**
 * Plugin Name: FOCUS Object Cache
 * Plugin URI: http://wordpress.org/plugins/focus-object-cache/
 * Description: File-based Object Cache is Utterly Slow: An Object Caching Dropin for WordPress that uses the local file system.
 * Version: 1.0.1
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/includes/class-focus-cache.php';
new FOCUS_Cache();
