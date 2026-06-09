<?php
/**
 * Query Monitor Object Cache group stats panel for FOCUS.
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
 * Outputs FOCUS object cache group stats.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Output_Html_Object_Cache_Group_Stats extends FOCUS_QM_Output_Html_Object_Cache_Base {
	/**
	 * Initializes the outputter.
	 *
	 * @since 1.1.0
	 *
	 * @param QM_Collector $collector Query Monitor collector.
	 */
	public function __construct( QM_Collector $collector ) {
		parent::__construct( $collector );

		add_filter( 'qm/output/menu_class', array( $this, 'admin_class' ) );
		add_filter( 'qm/output/panel_menus', array( $this, 'panel_menu' ), 99 );
	}

	/**
	 * Outputs group stats.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function output() {
		$data = $this->collector->get_data();

		$group_stats = $data->group_stats ?? array();

		$this->before_non_tabular_output();

		foreach ( $group_stats as $operation_type => $operation_stats ) {
			$total = array(
				'count' => 0,
				'time'  => 0.0,
				'size'  => 0,
			);

			$this->output_table_start( sprintf( '%s %s', __( 'Group Stats for', 'focus-cache' ), (string) $operation_type ) );

			foreach ( (array) $operation_stats as $group => $values ) {
				if ( ! is_array( $values ) ) {
					continue;
				}

				$count = (int) ( $values['count'] ?? 0 );
				$time  = (float) ( $values['time'] ?? 0 );
				$size  = (int) ( $values['size'] ?? 0 );

				echo '<tr>';
				$this->output_table_cell( (string) $group );
				$this->output_table_cell( $count );
				$this->output_table_cell( $this->format_time( $time ), $time );
				$this->output_table_cell( $this->format_size( $size ), $size );
				echo '</tr>';

				$total['count'] += $count;
				$total['time']  += $time;
				$total['size']  += $size;
			}

			echo '</tbody>';
			echo '<tfoot>';
			echo '<tr>';
			$this->output_table_cell( __( 'Totals:', 'focus-cache' ) );
			$this->output_table_cell( $total['count'] );
			$this->output_table_cell( $this->format_time( $total['time'] ) );
			$this->output_table_cell( $this->format_size( $total['size'] ) );
			echo '</tr>';
			echo '</tfoot>';
			echo '</table>';
			echo '</section>';
		}

		$this->after_non_tabular_output();
	}

	/**
	 * Adds a menu class.
	 *
	 * @since 1.1.0
	 *
	 * @param array $classes Menu classes.
	 * @return array Menu classes.
	 */
	public function admin_class( array $classes ): array {
		$classes[] = 'qm-object_cache_group_stats';
		return $classes;
	}

	/**
	 * Adds the Group Stats child menu.
	 *
	 * @since 1.1.0
	 *
	 * @param array $menu Query Monitor panel menu.
	 * @return array Query Monitor panel menu.
	 */
	public function panel_menu( array $menu ): array {
		return $this->add_object_cache_child_menu(
			$menu,
			'qm-object_cache_group_stats',
			'#qm-object_cache_group_stats',
			__( 'Group Stats', 'focus-cache' )
		);
	}

	/**
	 * Outputs a group stats table start.
	 *
	 * @since 1.1.0
	 *
	 * @param string $heading Table heading.
	 * @return void
	 */
	private function output_table_start( string $heading ): void {
		echo '<section>';
		echo '<h3>' . esc_html( $heading ) . '</h3>';
		echo '<table class="qm-sortable">';
		echo '<thead>';
		echo '<tr>';
		$this->output_sortable_table_col( __( 'Group', 'focus-cache' ) );
		$this->output_sortable_table_col( __( 'Count', 'focus-cache' ) );
		$this->output_sortable_table_col( __( 'Time', 'focus-cache' ) );
		$this->output_sortable_table_col( __( 'Size', 'focus-cache' ) );
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';
	}
}
