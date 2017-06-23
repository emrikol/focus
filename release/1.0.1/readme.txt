=== FOCUS Cache ===
Contributors: emrikol
Donate link: http://wordpressfoundation.org/donate/
Tags: cache, caching
Requires at least: 4.3.11
Tested up to: 4.8
Stable tag: 1.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

File-based Object Cache is Utterly Slow: An Object Caching Dropin for WordPress that uses the local file system.

== Description ==

I needed a persistent object cache while doing work on a budget hosting provider.  A lot of the other file-based caching plugins were either bundled with other things I didn't need (W3 Total Cache), or were old and broken.

On the sites I've tested this with, that have slow database servers, I have noticed an increase in page generation times of about 2x.  On the other hand, for sites that have fast database servers it can actually _increase_ page generation time.  Whenever possible, I'd recommend using Memcached, Redis, or your other quality cache of choice.

I've been heavily influenced by [redis-cache](https://wordpress.org/plugins/redis-cache/), [wp-redis](https://wordpress.org/plugins/wp-redis/), [W3 Total Cache](https://wordpress.org/plugins/w3-total-cache/), and [wp-memcached](https://github.com/Automattic/wp-memcached) to name a few.

== Installation ==

Install like any other plugin, directly from your plugins page or manually by copying the files to the `plugins/` folder.  Go to the plugin settings page at Settings->FOCUS Cache and click `Enable Object Cache`.

== Changelog ==

= 1.0.1 =

* Bugfix: Plugin was unable to be activated in the "Add Plugins" page.  This was due to the fact that WordPress detected the wrong PHP file as the plugin and tried to activate it.  Renaming the "Plugin Name" header from the PHP files in the `includes/` directory resolved the issue.  Thanks to @ramonjosegn on the WordPress.org Support Forums for bringing this to my attention.
* Bugfix: The plugin is now required to be activated across all sites in a multisite installation.
* Readme updates.

= 1.0.0 =

First Version

== Upgrade Notice ==

= 1.0.1 =

The plugin can now be properly activated via the "Add Plugins" screen.