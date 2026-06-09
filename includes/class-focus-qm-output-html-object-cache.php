<?php
/**
 * Query Monitor Object Cache parent panel for FOCUS.
 *
 * @package WordPress
 */

declare(strict_types=1);

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'FOCUS_QM_Output_Html_Object_Cache_Base' ) ) {
	return;
}
// @codeCoverageIgnoreEnd

/**
 * Outputs the FOCUS Object Cache Query Monitor parent panel.
 *
 * @since 2.0.0
 */
class FOCUS_QM_Output_Html_Object_Cache extends FOCUS_QM_Output_Html_Object_Cache_Base {
	/**
	 * Initializes the outputter.
	 *
	 * @since 2.0.0
	 *
	 * @param QM_Collector $collector Query Monitor collector.
	 */
	public function __construct( QM_Collector $collector ) {
		parent::__construct( $collector );

		add_filter( 'qm/output/menu_class', array( $this, 'admin_class' ) );
		add_filter( 'qm/output/menus', array( $this, 'admin_menu' ), 30 );
	}

	/**
	 * Returns the panel label.
	 *
	 * @since 2.0.0
	 *
	 * @return string Panel label.
	 */
	public function name() {
		return __( 'Object Cache', 'focus-cache' );
	}

	/**
	 * Outputs object cache totals and operation counts.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function output() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! method_exists( $wp_object_cache, 'get_stats' ) ) {
			echo '<div class="qm qm-non-tabular" id="' . esc_attr( $this->collector->id() ) . '">';
			echo '<div id="object-cache-stats">';
			if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'stats' ) ) {
				$wp_object_cache->stats();
			}
			echo '</div></div>';
			return;
		}

		$data = $this->collector->get_data();

		$this->before_non_tabular_output();

		$totals = $data->totals ?? array();
		if ( ! empty( $totals ) ) {
			$this->output_summary_section_start( __( 'Totals', 'focus-cache' ) );
			if ( isset( $totals['query_time'] ) ) {
				$this->output_summary_table_row( __( 'Query Time', 'focus-cache' ), $this->format_time( (float) $totals['query_time'] ) );
			}
			if ( isset( $totals['size'] ) ) {
				$this->output_summary_table_row( __( 'Size', 'focus-cache' ), $this->format_size( (int) $totals['size'] ) );
			}
			$this->output_summary_section_end();
		}

		$operation_counts = $data->operation_counts ?? array();
		if ( ! empty( $operation_counts ) ) {
			$this->output_summary_section_start( __( 'Operation Counts', 'focus-cache' ) );
			foreach ( $operation_counts as $operation => $count ) {
				if ( (int) $count > 0 ) {
					$this->output_summary_table_row( (string) $operation, number_format_i18n( (int) $count ) );
				}
			}
			$this->output_summary_section_end();
		}

		$this->after_non_tabular_output();
	}

	/**
	 * Adds a menu class.
	 *
	 * @since 2.0.0
	 *
	 * @param array $classes Menu classes.
	 * @return array Menu classes.
	 */
	public function admin_class( array $classes ): array {
		$classes[] = 'qm-object_cache';
		return $classes;
	}

	/**
	 * Adds the Object Cache parent menu.
	 *
	 * @since 2.0.0
	 *
	 * @param array $menu Query Monitor menu.
	 * @return array Query Monitor menu.
	 */
	public function admin_menu( array $menu ): array {
		$menu['object_cache'] = $this->menu(
			array(
				'id'    => 'object_cache',
				'href'  => '#qm-object_cache',
				'title' => __( 'Object Cache', 'focus-cache' ),
			)
		);

		return $menu;
	}
}
