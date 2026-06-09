<?php
/**
 * Query Monitor collector for FOCUS object cache operations.
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
 * Collects FOCUS object cache operation rows.
 *
 * @since 2.0.0
 */
class FOCUS_QM_Collector_Object_Cache_Ops extends QM_Collector {
	/**
	 * Collector ID. Matches VIP's Query Monitor object-cache operations panel.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	public $id = 'object_cache_ops';

	/**
	 * Returns the collector label.
	 *
	 * @since 2.0.0
	 *
	 * @return string Collector label.
	 */
	public function name() {
		return __( 'Operations', 'focus-cache' );
	}

	/**
	 * Returns the collector storage object.
	 *
	 * @since 2.0.0
	 *
	 * @return QM_Data Storage object.
	 */
	public function get_storage(): QM_Data {
		return new FOCUS_QM_Data_Object_Cache();
	}

	/**
	 * Collects object cache operation rows.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function process() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'get_stats' ) ) {
			return;
		}

		$stats = $wp_object_cache->get_stats();

		$this->data->operations = $stats['operations'] ?? array();
		$this->data->groups     = $stats['groups'] ?? array();
	}
}
