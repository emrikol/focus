# FOCUS Cache 
**Contributors:** emrikol  
**Tags:** cache, caching  
**Donate link:** http://wordpressfoundation.org/donate/  
**Requires at least:** 4.3.11  
**Tested up to:** 4.8  
**Stable tag:** 0.1.0  
**License:** GPLv2 or later  
**License URI:** http://www.gnu.org/licenses/gpl-2.0.html  

File-based Object Cache is Utterly Slow: An Object Caching Dropin for WordPress that uses the local file system


## Description 
[![Build Status](https://travis-ci.org/emrikol/focus.svg?branch=master)](https://travis-ci.org/emrikol/focus)

I needed a persistent object cache while doing work on a budget hosting provider.  A lot of the other file-based caching plugins were either bundled with other things I didn't need (W3 Total Cache), or were old and broken.

On the sites I've tested this with, that have slow database servers, I have noticed an increase in page generation times of about 2x.  On the other hand, for sites that have fast database servers it can actually _increase_ page generation time.  Whenever possible, I'd recommend using Memcached, Redis, or your other quality cache of choice.

I've been heavily influenced by [redis-cache](https://wordpress.org/plugins/redis-cache/), [wp-redis](https://wordpress.org/plugins/wp-redis/), [W3 Total Cache](https://wordpress.org/plugins/w3-total-cache/), and [wp-memcached](https://github.com/Automattic/wp-memcached) to name a few.


## Installation 

Install `object-cache.php` to `wp-content/object-cache.php` with a symlink, by copying the file, or via the settings page in the Tools menu.
