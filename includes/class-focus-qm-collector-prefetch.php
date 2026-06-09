<?php
/**
 * Query Monitor collector for FOCUS prefetch stats.
 *
 * @package WordPress
 */

declare(strict_types=1);

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'QM_Collector' ) || ! class_exists( 'QM_Data' ) ) {
	return;
}
// @codeCoverageIgnoreEnd

/**
 * Collects FOCUS prefetch stats from the object-cache drop-in.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Collector_Prefetch extends QM_Collector {
	/**
	 * Collector ID.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public $id = 'focus_prefetch';

	/**
	 * Returns the collector label.
	 *
	 * @since 1.1.0
	 *
	 * @return string Collector label.
	 */
	public function name() {
		return __( 'FOCUS Prefetch', 'focus-cache' );
	}

	/**
	 * Returns the collector storage object.
	 *
	 * @since 1.1.0
	 *
	 * @return QM_Data Storage object.
	 */
	public function get_storage() {
		return new QM_Data();
	}

	/**
	 * Collects prefetch stats.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function process() {
		global $wp_object_cache;

		if ( ! method_exists( $wp_object_cache, 'get_stats' ) ) {
			return;
		}

		$stats                = $wp_object_cache->get_stats();
		$this->data->prefetch = $stats['prefetch'] ?? array();
	}
}
