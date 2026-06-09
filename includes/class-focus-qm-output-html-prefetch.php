<?php
/**
 * Query Monitor output panel for FOCUS prefetch stats.
 *
 * @package WordPress
 */

declare(strict_types=1);

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'QM_Output_Html' ) || ! class_exists( 'QM_Collector' ) ) {
	return;
}
// @codeCoverageIgnoreEnd

/**
 * Outputs the FOCUS prefetch Query Monitor panel.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Output_Html_Prefetch extends QM_Output_Html {
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
	 * Outputs the prefetch panel.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function output() {
		$data = $this->collector->get_data();
		if ( empty( $data ) || empty( $data->prefetch ) || ! is_array( $data->prefetch ) ) {
			return;
		}

		$prefetch = $data->prefetch;

		$this->before_non_tabular_output();
		$this->output_summary( $prefetch );
		$this->output_grouped_keys( __( 'Prefetched And Used', 'focus-cache' ), $prefetch['used_groups'] ?? array() );
		$this->output_grouped_keys( __( 'Prefetched But Unused', 'focus-cache' ), $prefetch['unused_groups'] ?? array() );
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
		$classes[] = 'qm-focus-prefetch';
		return $classes;
	}

	/**
	 * Adds the prefetch subpanel to Query Monitor.
	 *
	 * @since 1.1.0
	 *
	 * @param array $menu Query Monitor panel menu.
	 * @return array Query Monitor panel menu.
	 */
	public function panel_menu( array $menu ): array {
		$item = $this->menu(
			array(
				'id'    => 'qm-focus_prefetch',
				'href'  => '#qm-focus_prefetch',
				'title' => __( 'FOCUS Prefetch', 'focus-cache' ),
			)
		);

		if ( isset( $menu['cache'] ) ) {
			$menu['cache']['children'][] = $item;
		} elseif ( isset( $menu['object_cache'] ) ) {
			$menu['object_cache']['children'][] = $item;
		} else {
			$menu['focus_prefetch'] = $item;
		}

		return $menu;
	}

	/**
	 * Outputs the summary table.
	 *
	 * @since 1.1.0
	 *
	 * @param array $prefetch Prefetch stats.
	 * @return void
	 */
	protected function output_summary( array $prefetch ): void {
		$this->output_before_section( __( 'Prefetch Summary', 'focus-cache' ) );
		$this->output_table_row( __( 'Enabled', 'focus-cache' ), ! empty( $prefetch['enabled'] ) ? __( 'Yes', 'focus-cache' ) : __( 'No', 'focus-cache' ) );
		$this->output_table_row( __( 'Backend', 'focus-cache' ), (string) ( $prefetch['backend'] ?? '' ) );
		$this->output_table_row( __( 'Manifest Found', 'focus-cache' ), ! empty( $prefetch['manifest_found'] ) ? __( 'Yes', 'focus-cache' ) : __( 'No', 'focus-cache' ) );
		$this->output_table_row( __( 'Manifest Groups', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['manifest_groups'] ?? 0 ) ) );
		$this->output_table_row( __( 'Manifest Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['manifest_keys'] ?? 0 ) ) );
		$this->output_table_row( __( 'Prefetch Load Calls', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['load_operations'] ?? 0 ) ) );
		$this->output_table_row( __( 'Prefetch Load Time', 'focus-cache' ), $this->format_time( (float) ( $prefetch['load_time'] ?? 0 ) ) );
		$this->output_table_row( __( 'Loaded Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['loaded_keys'] ?? 0 ) ) );
		$this->output_table_row( __( 'Missing Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['missing_keys'] ?? 0 ) ) );
		$this->output_table_row( __( 'Used Prefetched Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['used_keys'] ?? 0 ) ) );
		$this->output_table_row( __( 'Unused Prefetched Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['unused_keys'] ?? 0 ) ) );
		$this->output_table_row( __( 'Individual Calls Avoided', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['calls_saved'] ?? 0 ) ) );
		$this->output_table_row( __( 'Net Calls Saved', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['net_calls_saved'] ?? 0 ) ) );
		$this->output_table_row( __( 'Estimated Time Saved', 'focus-cache' ), $this->format_time( (float) ( $prefetch['estimated_time_saved'] ?? 0 ) ) );
		$this->output_after_section();
	}

	/**
	 * Outputs a grouped key section.
	 *
	 * @since 1.1.0
	 *
	 * @param string $heading Section heading.
	 * @param array  $groups  Grouped keys.
	 * @return void
	 */
	protected function output_grouped_keys( string $heading, array $groups ): void {
		if ( empty( $groups ) ) {
			return;
		}

		$this->output_before_section( $heading );

		foreach ( $groups as $group => $keys ) {
			$this->output_table_row( (string) $group, implode( ', ', array_map( 'strval', (array) $keys ) ) );
		}

		$this->output_after_section();
	}

	/**
	 * Outputs the start of a table section.
	 *
	 * @since 1.1.0
	 *
	 * @param string $heading Section heading.
	 * @return void
	 */
	protected function output_before_section( string $heading ): void {
		echo '<section>';
		echo '<h3>' . esc_html( $heading ) . '</h3>';
		echo '<table>';
		echo '<tbody>';
	}

	/**
	 * Outputs the end of a table section.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	protected function output_after_section(): void {
		echo '</tbody>';
		echo '</table>';
		echo '</section>';
	}

	/**
	 * Outputs one summary row.
	 *
	 * @since 1.1.0
	 *
	 * @param string $title Row title.
	 * @param string $value Row value.
	 * @return void
	 */
	protected function output_table_row( string $title, string $value ): void {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( $title ) . '</th>';
		echo '<td class="qm-nowrap qm-ltr">' . esc_html( $value ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Formats seconds as milliseconds.
	 *
	 * @since 1.1.0
	 *
	 * @param float $seconds Seconds.
	 * @return string Human-readable milliseconds.
	 */
	protected function format_time( float $seconds ): string {
		return number_format_i18n( sprintf( '%0.1f', $seconds * 1000 ), 1 ) . 'ms';
	}
}
