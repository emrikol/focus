<?php
/**
 * Query Monitor collector for FOCUS object cache group stats.
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
 * Collects FOCUS object cache operation totals grouped by cache group.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Collector_Object_Cache_Group_Stats extends QM_Collector {
	/**
	 * Collector ID. Matches VIP's Query Monitor object-cache group stats panel.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public $id = 'object_cache_group_stats';

	/**
	 * Returns the collector label.
	 *
	 * @since 1.1.0
	 *
	 * @return string Collector label.
	 */
	public function name() {
		return __( 'Group Stats', 'focus-cache' );
	}

	/**
	 * Returns the collector storage object.
	 *
	 * @since 1.1.0
	 *
	 * @return QM_Data Storage object.
	 */
	public function get_storage(): QM_Data {
		return new FOCUS_QM_Data_Object_Cache();
	}

	/**
	 * Collects grouped object cache operation totals.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function process() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'get_stats' ) ) {
			return;
		}

		$stats       = $wp_object_cache->get_stats();
		$group_stats = array();

		foreach ( (array) ( $stats['operations'] ?? array() ) as $operation_type => $operations ) {
			if ( 'get_flush_number' === $operation_type ) {
				continue;
			}

			foreach ( (array) $operations as $operation ) {
				if ( ! is_array( $operation ) ) {
					continue;
				}

				$group = isset( $operation['group'] ) ? (string) $operation['group'] : '[unknown]';

				$group_stats[ $operation_type ][ $group ]['count'] ??= 0;
				$group_stats[ $operation_type ][ $group ]['time']  ??= 0.0;
				$group_stats[ $operation_type ][ $group ]['size']  ??= 0;

				++$group_stats[ $operation_type ][ $group ]['count'];
				$group_stats[ $operation_type ][ $group ]['time'] += (float) ( $operation['time'] ?? 0 );
				$group_stats[ $operation_type ][ $group ]['size'] += (int) ( $operation['size'] ?? 0 );
			}
		}

		$this->data->totals           = $stats['totals'] ?? array();
		$this->data->operation_counts = $stats['operation_counts'] ?? array();
		$this->data->operations       = $stats['operations'] ?? array();
		$this->data->groups           = $stats['groups'] ?? array();
		$this->data->group_stats      = $group_stats;
	}
}
