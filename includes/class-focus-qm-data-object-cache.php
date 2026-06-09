<?php
/**
 * Query Monitor data object for FOCUS object cache stats.
 *
 * @package WordPress
 */

declare(strict_types=1);

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'QM_Data' ) ) {
	return;
}
// @codeCoverageIgnoreEnd

/**
 * Storage object for FOCUS object cache stats.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Data_Object_Cache extends QM_Data {
	/**
	 * Aggregate operation totals.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $totals = array();

	/**
	 * Operation counts keyed by operation name.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $operation_counts = array();

	/**
	 * Slow operations keyed by operation name.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $slow_ops = array();

	/**
	 * Groups represented by slow operations.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $slow_ops_groups = array();

	/**
	 * Operations keyed by operation name.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $operations = array();

	/**
	 * Groups represented by operations.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $groups = array();

	/**
	 * Per-operation, per-group aggregates.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	public $group_stats = array();
}
