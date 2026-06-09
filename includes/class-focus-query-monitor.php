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
	 * Registers the FOCUS prefetch collector.
	 *
	 * @since 1.1.0
	 *
	 * @param array $collectors Query Monitor collectors.
	 * @return array Query Monitor collectors.
	 */
	public static function register_collectors( array $collectors ): array {
		$collectors['focus_prefetch'] = new FOCUS_QM_Collector_Prefetch();

		return $collectors;
	}

	/**
	 * Registers the FOCUS prefetch HTML output panel.
	 *
	 * @since 1.1.0
	 *
	 * @param array $outputters Query Monitor outputters.
	 * @return array Query Monitor outputters.
	 */
	public static function register_outputters( array $outputters ): array {
		$collector = QM_Collectors::get( 'focus_prefetch' );
		if ( $collector ) {
			$outputters['focus_prefetch'] = new FOCUS_QM_Output_Html_Prefetch( $collector );
		}

		return $outputters;
	}
}
