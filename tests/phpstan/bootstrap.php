<?php
/**
 * Defines default constants for PHPStan discovery.
 *
 * Mocks the constant initiation that normally happens in WordPress before the
 * plugin and object-cache drop-in are loaded.
 *
 * Loaded as a `bootstrapFile` by PHPStan; see `base.neon`.
 */

defined( 'WP_CACHE' ) || define( 'WP_CACHE', true );
defined( 'WP_CACHE_KEY_SALT' ) || define( 'WP_CACHE_KEY_SALT', '' );
defined( 'WP_FOCUS_BACKEND' ) || define( 'WP_FOCUS_BACKEND', 'file' );
defined( 'WP_FOCUS_CACHE_PREFETCH' ) || define( 'WP_FOCUS_CACHE_PREFETCH', false );
defined( 'WP_FOCUS_DATABASE_GC_BATCH_SIZE' ) || define( 'WP_FOCUS_DATABASE_GC_BATCH_SIZE', 1000 );
defined( 'WP_FOCUS_DATABASE_MAX_VALUE_SIZE' ) || define( 'WP_FOCUS_DATABASE_MAX_VALUE_SIZE', 1048576 );
defined( 'WP_FOCUS_DATABASE_PREFETCH_CHUNK_SIZE' ) || define( 'WP_FOCUS_DATABASE_PREFETCH_CHUNK_SIZE', 500 );
defined( 'WP_FOCUS_MAXTTL' ) || define( 'WP_FOCUS_MAXTTL', YEAR_IN_SECONDS );
defined( 'WP_FOCUS_PREFETCH_TTL' ) || define( 'WP_FOCUS_PREFETCH_TTL', DAY_IN_SECONDS );
