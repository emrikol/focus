<?php
/**
 * Query Monitor Object Cache prefetch panel for FOCUS.
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
 * Outputs the FOCUS prefetch Query Monitor child panel.
 *
 * @since 1.1.0
 */
class FOCUS_QM_Output_Html_Prefetch extends FOCUS_QM_Output_Html_Object_Cache_Base {
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
		$classes[] = 'qm-object_cache_prefetch';
		return $classes;
	}

	/**
	 * Adds the Prefetch child menu to Object Cache.
	 *
	 * @since 1.1.0
	 *
	 * @param array $menu Query Monitor panel menu.
	 * @return array Query Monitor panel menu.
	 */
	public function panel_menu( array $menu ): array {
		return $this->add_object_cache_child_menu(
			$menu,
			'qm-object_cache_prefetch',
			'#qm-object_cache_prefetch',
			__( 'Prefetch', 'focus-cache' )
		);
	}

	/**
	 * Outputs the summary table.
	 *
	 * @since 1.1.0
	 *
	 * @param array $prefetch Prefetch stats.
	 * @return void
	 */
	private function output_summary( array $prefetch ): void {
		$this->output_summary_section_start( __( 'Prefetch Summary', 'focus-cache' ) );
		$this->output_summary_table_row( __( 'Enabled', 'focus-cache' ), ! empty( $prefetch['enabled'] ) ? __( 'Yes', 'focus-cache' ) : __( 'No', 'focus-cache' ) );
		$this->output_summary_table_row( __( 'Backend', 'focus-cache' ), (string) ( $prefetch['backend'] ?? '' ) );
		$this->output_summary_table_row( __( 'Manifest Found', 'focus-cache' ), ! empty( $prefetch['manifest_found'] ) ? __( 'Yes', 'focus-cache' ) : __( 'No', 'focus-cache' ) );
		$this->output_summary_table_row( __( 'Manifest Groups', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['manifest_groups'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Manifest Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['manifest_keys'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Prefetch Load Calls', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['load_operations'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Prefetch Load Time', 'focus-cache' ), $this->format_time( (float) ( $prefetch['load_time'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Loaded Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['loaded_keys'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Missing Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['missing_keys'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Used Prefetched Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['used_keys'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Unused Prefetched Keys', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['unused_keys'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Individual Calls Avoided', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['calls_saved'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Net Calls Saved', 'focus-cache' ), number_format_i18n( (int) ( $prefetch['net_calls_saved'] ?? 0 ) ) );
		$this->output_summary_table_row( __( 'Estimated Time Saved', 'focus-cache' ), $this->format_time( (float) ( $prefetch['estimated_time_saved'] ?? 0 ) ) );
		$this->output_summary_section_end();
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
	private function output_grouped_keys( string $heading, array $groups ): void {
		if ( empty( $groups ) ) {
			return;
		}

		$this->output_summary_section_start( $heading );

		foreach ( $groups as $group => $keys ) {
			$this->output_summary_table_row( (string) $group, implode( ', ', array_map( 'strval', (array) $keys ) ) );
		}

		$this->output_summary_section_end();
	}
}
