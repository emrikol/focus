<?php
/**
 * Query Monitor integration registrar for FOCUS Object Cache.
 *
 * @package WordPress
 */

declare(strict_types=1);

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

/**
 * Registers FOCUS Query Monitor collectors and output panels.
 *
 * @since 1.1.0
 */
class FOCUS_Query_Monitor {
	/**
	 * Registers Query Monitor filters.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'qm/collectors', array( __CLASS__, 'register_collectors' ), 10, 2 );
		add_filter( 'qm/outputter/html', array( __CLASS__, 'register_outputters' ), 10, 2 );
	}

	/**
	 * Registers the FOCUS object cache collectors.
	 *
	 * @since 1.1.0
	 *
	 * @param array $collectors Query Monitor collectors.
	 * @return array Query Monitor collectors.
	 */
	public static function register_collectors( array $collectors ): array {
		$collectors['object_cache']             = new FOCUS_QM_Collector_Object_Cache();
		$collectors['object_cache_ops']         = new FOCUS_QM_Collector_Object_Cache_Ops();
		$collectors['object_cache_group_stats'] = new FOCUS_QM_Collector_Object_Cache_Group_Stats();
		$collectors['object_cache_slow_ops']    = new FOCUS_QM_Collector_Object_Cache_Slow_Ops();
		$collectors['object_cache_prefetch']    = new FOCUS_QM_Collector_Prefetch();

		return $collectors;
	}

	/**
	 * Registers the FOCUS object cache HTML output panels.
	 *
	 * @since 1.1.0
	 *
	 * @param array $outputters Query Monitor outputters.
	 * @return array Query Monitor outputters.
	 */
	public static function register_outputters( array $outputters ): array {
		$outputter_map = array(
			'object_cache'             => FOCUS_QM_Output_Html_Object_Cache::class,
			'object_cache_ops'         => FOCUS_QM_Output_Html_Object_Cache_Ops::class,
			'object_cache_group_stats' => FOCUS_QM_Output_Html_Object_Cache_Group_Stats::class,
			'object_cache_slow_ops'    => FOCUS_QM_Output_Html_Object_Cache_Slow_Ops::class,
			'object_cache_prefetch'    => FOCUS_QM_Output_Html_Prefetch::class,
		);

		foreach ( $outputter_map as $collector_id => $outputter_class ) {
			$collector = QM_Collectors::get( $collector_id );
			if ( $collector ) {
				$outputters[ $collector_id ] = new $outputter_class( $collector );
			}
		}

		return $outputters;
	}
}
