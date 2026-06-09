<?php
/**
 * Query Monitor Object Cache operations panel for FOCUS.
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
 * Outputs FOCUS object cache operation rows.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Output_Html_Object_Cache_Ops extends FOCUS_QM_Output_Html_Object_Cache_Base {
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
	 * Outputs operation rows.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function output() {
		$data = $this->collector->get_data();

		$operations = $data->operations ?? array();
		$groups     = $data->groups ?? array();

		$this->before_tabular_output();

		echo '<thead>';
		echo '<tr>';
		$this->output_filterable_table_col( __( 'Operation', 'focus-cache' ), array_keys( $operations ) );
		$this->output_sortable_table_col( __( 'Key', 'focus-cache' ) );
		$this->output_sortable_table_col( __( 'Size', 'focus-cache' ) );
		$this->output_sortable_table_col( __( 'Time', 'focus-cache' ) );
		$this->output_filterable_table_col( __( 'Group', 'focus-cache' ), $groups );
		$this->output_sortable_table_col( __( 'Result', 'focus-cache' ) );
		echo '</tr>';
		echo '</thead>';

		echo '<tbody>';
		$total = 0;
		foreach ( $operations as $operation_name => $operation_rows ) {
			foreach ( (array) $operation_rows as $operation ) {
				if ( ! is_array( $operation ) ) {
					continue;
				}

				echo '<tr data-qm-operation="' . esc_attr( (string) $operation_name ) . '" data-qm-group="' . esc_attr( (string) ( $operation['group'] ?? '' ) ) . '">';
				$this->output_table_cell( (string) $operation_name );
				$this->output_key_cell( $operation['key'] ?? null );
				$this->output_table_cell( $this->format_size( isset( $operation['size'] ) ? (int) $operation['size'] : null ), isset( $operation['size'] ) ? (int) $operation['size'] : null );
				$this->output_table_cell( $this->format_time( isset( $operation['time'] ) ? (float) $operation['time'] : null ) );
				$this->output_table_cell( (string) ( $operation['group'] ?? '' ) );
				$this->output_table_cell( $this->format_result( (string) ( $operation['result'] ?? '' ) ) );
				echo '</tr>';
				++$total;
			}
		}
		echo '</tbody>';
		echo '<tfoot>';
		echo '<tr>';
		printf(
			'<td colspan="6">%1$s</td>',
			sprintf(
				/* translators: %s: Number of object cache operations. */
				esc_html( _nx( 'Total: %s', 'Total: %s', $total, 'Object cache operations', 'focus-cache' ) ),
				'<span class="qm-items-number">' . esc_html( number_format_i18n( $total ) ) . '</span>'
			)
		);
		echo '</tr>';
		echo '</tfoot>';

		$this->after_tabular_output();
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
		$classes[] = 'qm-object_cache_ops';
		return $classes;
	}

	/**
	 * Adds the Operations child menu.
	 *
	 * @since 1.1.0
	 *
	 * @param array $menu Query Monitor panel menu.
	 * @return array Query Monitor panel menu.
	 */
	public function panel_menu( array $menu ): array {
		return $this->add_object_cache_child_menu(
			$menu,
			'qm-object_cache_ops',
			'#qm-object_cache_ops',
			__( 'Operations', 'focus-cache' )
		);
	}
}
