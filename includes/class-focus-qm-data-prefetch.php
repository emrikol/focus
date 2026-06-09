<?php
/**
 * Query Monitor data object for FOCUS prefetch stats.
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
 * Storage object for FOCUS prefetch stats.
 *
 * @since 2.0.0
 */
class FOCUS_QM_Data_Prefetch extends QM_Data {
	/**
	 * Prefetch stats.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	public $prefetch = array();
}
