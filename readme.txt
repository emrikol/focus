=== FOCUS Cache ===
Contributors: emrikol
Donate link: http://wordpressfoundation.org/donate/
Tags: cache, caching
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

A persistent object cache drop-in for WordPress with file and database storage backends.

== Description ==

FOCUS Object Cache provides a persistent object-cache drop-in for WordPress. It uses a file backend by default and can also use a custom database-table backend for environments where local file storage is not the best fit.

The database backend stores transient cache data in custom tables and supports group deletion, multisite blog buckets, global cache buckets, network cache buckets, batch cache operations, and prefetch hydration.

Prefetch is optional. When enabled with `WP_FOCUS_CACHE_PREFETCH`, FOCUS records the cache keys used for each request URL and hydrates them on the next matching request. The database backend can load a request's prefetch keys with a single joined SELECT.

Prefetch manifests expire after `WP_FOCUS_PREFETCH_TTL`, which defaults to five minutes. They are saved at the end of the request on the latest shutdown priority so the next matching request can hydrate the keys seen during the previous request. Non-persistent groups and FOCUS's internal prefetch group are excluded.

With the file backend, prefetch manifests are stored as ordinary cache items in the prefetch group. With the database backend, prefetch keys are stored in the normalized `focus_cache_prefetch_keys` table and hydrated from `focus_cache_items` in a joined batch query. Database prefetch also remembers keys that were requested by a prefetch manifest but missing from storage, then carries those keys into the next manifest so newly populated values can be prefetched on the following request.

The Query Monitor integration adds an Object Cache panel plus a FOCUS Prefetch subpanel. The prefetch subpanel reports requested, loaded, missing, used, and unused keys, along with estimated calls and time saved.

Whenever possible, use Memcached, Redis, or another dedicated object-cache service. FOCUS is intended for hosts and environments where those services are unavailable or impractical.

I've been heavily influenced by [redis-cache](https://wordpress.org/plugins/redis-cache/), [wp-redis](https://wordpress.org/plugins/wp-redis/), [W3 Total Cache](https://wordpress.org/plugins/w3-total-cache/), and [wp-memcached](https://github.com/Automattic/wp-memcached) to name a few.

== Installation ==

Install like any other plugin, directly from your plugins page or manually by copying the files to the `plugins/` folder. Go to the plugin settings page at Settings->FOCUS Cache and click `Enable Object Cache`.

The file backend is used by default. To use the database backend, define this in `wp-config.php` before enabling or updating the drop-in:

`define( 'WP_FOCUS_BACKEND', 'database' );`

To enable prefetch:

`define( 'WP_FOCUS_CACHE_PREFETCH', true );`

The prefetch manifest TTL can be adjusted if needed:

`define( 'WP_FOCUS_PREFETCH_TTL', 300 );`

Database backend tables contain transient cache data. They may be dropped and rebuilt during backend installation or updates.

== Changelog ==

= 2.0.0 =
* New: Added a database-backed object cache backend using custom WordPress database tables.
* New: Added optional request prefetching with database-backed single-query hydration.
* New: Added Query Monitor-compatible object cache panels and a FOCUS Prefetch subpanel.
* New: Added WordPress 7.0 object cache API parity checks and support for modern cache functions, including salted cache helpers and multiple-key operations.
* New: Added PHPStan using WordPress core-style configuration.
* Improved: Rebuilt the database schema as transient cache storage with direct bucket hashes, no runtime schema checks, and bounded garbage collection.
* Improved: Reduced normal-request database overhead for the database backend.
* Improved: Added database backend setup during activation, drop-in enable, and drop-in update.
* Improved: Expanded unit tests across single-site, multisite, database backend, prefetch, Query Monitor, and WordPress 7.0 cache API compatibility.
* Changed: Database cache tables are treated as disposable transient cache data and may be dropped/rebuilt when the backend is installed or updated.

= 1.0.2 =
* New: Added plugin activation hook (`on_activation()`) to flush cache on install.
* Improved: Now flushes cache after enabling, disabling, or updating the drop-in.
* Improved: Filesystem credential handling is more explicit and avoids side effects.
* Updated: Code style consistency: spacing, indentation, naming alignment, and translator comments.
* Updated: WordPress 7.0 compatibility metadata and PHP 8.0 requirement.
* Updated: Object cache drop-in now defines the WP 7.0 cache API surface, including reset and salted cache helpers.
* Updated: Drop-in version bumped to 1.0.2; changed default cache directory resolution to use WP_CONTENT_DIR instead of ABSPATH.
* Fixed: Activation/deactivation hooks and plugin action links now target the main plugin file.
* Fixed: Persistent cache existence checks for add, replace, increment, decrement, delete, and add_multiple.
* Removed: Obsolete sample test file (tests/test-sample.php).
* Fixed: Minor logic and control flow refinements in object cache drop-in (e.g., readdir loop fix, file permissions).

= 1.0.1 =

* Bugfix: Plugin was unable to be activated in the "Add Plugins" page.  This was due to the fact that WordPress detected the wrong PHP file as the plugin and tried to activate it.  Renaming the "Plugin Name" header from the PHP files in the `includes/` directory resolved the issue.  Thanks to @ramonjosegn on the WordPress.org Support Forums for bringing this to my attention.
* Bugfix: The plugin is now required to be activated across all sites in a multisite installation.
* Readme updates.

= 1.0.0 =

First Version

== Upgrade Notice ==

= 2.0.0 =
Adds the database backend, optional prefetching, Query Monitor panels, and WordPress 7.0 object cache API parity. Database backend tables contain transient cache data and may be rebuilt during installation or updates.

= 1.0.1 =

The plugin can now be properly activated via the "Add Plugins" screen.
