<?php
/**
 * Shared Query Monitor HTML helpers for FOCUS object cache panels.
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
 * Shared FOCUS object cache Query Monitor output helpers.
 *
 * @since 2.0.0
 */
abstract class FOCUS_QM_Output_Html_Object_Cache_Base extends QM_Output_Html {
	/**
	 * Adds a child panel to the Object Cache parent menu.
	 *
	 * @since 2.0.0
	 *
	 * @param array  $menu  Query Monitor panel menu.
	 * @param string $id    Panel ID.
	 * @param string $href  Panel href.
	 * @param string $title Panel title.
	 * @return array Query Monitor panel menu.
	 */
	protected function add_object_cache_child_menu( array $menu, string $id, string $href, string $title ): array {
		if ( isset( $menu['object_cache'] ) ) {
			$menu['object_cache']['children'][] = $this->menu(
				array(
					'id'    => $id,
					'href'  => $href,
					'title' => $title,
				)
			);
		}

		return $menu;
	}

	/**
	 * Formats bytes as a human-readable size.
	 *
	 * @since 2.0.0
	 *
	 * @param int|null $size Raw size.
	 * @return string Human-readable size.
	 */
	protected function format_size( ?int $size ): string {
		return size_format( (int) $size, 2 );
	}

	/**
	 * Formats seconds as milliseconds.
	 *
	 * @since 2.0.0
	 *
	 * @param float|null $time Raw time in seconds.
	 * @return string Human-readable milliseconds.
	 */
	protected function format_time( ?float $time ): string {
		return number_format_i18n( sprintf( '%0.1f', (float) $time * 1000 ), 1 ) . 'ms';
	}

	/**
	 * Formats legacy object-cache result names.
	 *
	 * @since 2.0.0
	 *
	 * @param string $result Operation result.
	 * @return string Human-readable result.
	 */
	protected function format_result( string $result ): string {
		switch ( trim( $result ) ) {
			case 'not_in_memcache':
				return __( 'Not in Memcached', 'focus-cache' );
			case 'memcache':
				return __( 'Found in Memcached', 'focus-cache' );
			case '[mc already]':
				return __( 'Already in Memcached', 'focus-cache' );
			case '[lc already]':
				return __( 'Local cache already', 'focus-cache' );
		}

		return $result;
	}

	/**
	 * Outputs a sortable table column header.
	 *
	 * @since 2.0.0
	 *
	 * @param string $title Column title.
	 * @return void
	 */
	protected function output_sortable_table_col( string $title ): void {
		echo '<th scope="col" class="qm-sortable-column" role="columnheader">';
		echo $this->build_sorter( esc_html( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</th>';
	}

	/**
	 * Outputs a filterable table column header.
	 *
	 * @since 2.0.0
	 *
	 * @param string $title  Column title.
	 * @param array  $values Filter values.
	 * @param array  $args   Additional filter arguments.
	 * @return void
	 */
	protected function output_filterable_table_col( string $title, array $values, array $args = array() ): void {
		echo '<th scope="col" class="qm-filterable-column">';
		echo $this->build_filter( sanitize_title( strtolower( $title ) ), $values, esc_html( $title ), $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</th>';
	}

	/**
	 * Outputs an operation key cell.
	 *
	 * @since 2.0.0
	 *
	 * @param array|string|null $key Operation key.
	 * @return void
	 */
	protected function output_key_cell( array|string|null $key ): void {
		if ( is_array( $key ) ) {
			$this->output_array_table_cell( $key );
			return;
		}

		$this->output_table_cell( $key );
	}

	/**
	 * Outputs a toggleable table cell for arrays.
	 *
	 * @since 2.0.0
	 *
	 * @param array $values Values.
	 * @return void
	 */
	protected function output_array_table_cell( array $values ): void {
		$values = array_values( $values );
		if ( empty( $values ) ) {
			$this->output_table_cell( '' );
			return;
		}

		if ( 1 === count( $values ) ) {
			$this->output_table_cell( (string) $values[0] );
			return;
		}

		echo '<td class="qm-nowrap qm-ltr qm-has-toggle">';
		echo static::build_toggler(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<ol><li>' . esc_html( (string) $values[0] ) . ' [+' . esc_html( (string) ( count( $values ) - 1 ) ) . ' more]</li>';
		unset( $values[0] );
		echo '<span class="qm-info qm-supplemental">';
		foreach ( $values as $value ) {
			echo '<li>' . esc_html( (string) $value ) . '</li>';
		}
		echo '</span>';
		echo '</ol></td>';
	}

	/**
	 * Outputs a toggleable table cell for a backtrace.
	 *
	 * @since 2.0.0
	 *
	 * @param string|null $backtrace Backtrace summary.
	 * @return void
	 */
	protected function output_backtrace_cell( ?string $backtrace ): void {
		if ( null === $backtrace || '' === $backtrace ) {
			$this->output_table_cell( '' );
			return;
		}

		$frames = explode( ', ', $backtrace );
		echo '<td class="qm-nowrap qm-ltr qm-has-toggle">';
		echo static::build_toggler(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<ol><li><code>' . esc_html( (string) $frames[0] ) . '...</code></li>';
		unset( $frames[0] );
		echo '<span class="qm-info qm-supplemental">';
		foreach ( $frames as $frame ) {
			echo '<li><code>' . esc_html( $frame ) . '</code></li>';
		}
		echo '</span>';
		echo '</ol></td>';
	}

	/**
	 * Outputs a table cell.
	 *
	 * @since 2.0.0
	 *
	 * @param string|int|float|null $value  Cell value.
	 * @param int|float|null        $weight Sort weight.
	 * @return void
	 */
	protected function output_table_cell( string|int|float|null $value, int|float|null $weight = null ): void {
		$weight_attribute = null !== $weight ? ' data-qm-sort-weight="' . esc_attr( (string) $weight ) . '"' : '';
		echo '<td class="qm-nowrap qm-ltr"' . $weight_attribute . '>' . esc_html( (string) $value ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Outputs a key-value table row.
	 *
	 * @since 2.0.0
	 *
	 * @param string $title Row title.
	 * @param string $value Row value.
	 * @return void
	 */
	protected function output_summary_table_row( string $title, string $value ): void {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( $title ) . '</th>';
		echo '<td class="qm-nowrap qm-ltr">' . esc_html( $value ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Outputs the start of a key-value section.
	 *
	 * @since 2.0.0
	 *
	 * @param string $heading Section heading.
	 * @return void
	 */
	protected function output_summary_section_start( string $heading ): void {
		echo '<section>';
		if ( '' !== $heading ) {
			echo '<h3>' . esc_html( $heading ) . '</h3>';
		}
		echo '<table>';
		echo '<thead class="qm-screen-reader-text">';
		echo '<tr>';
		echo '<th scope="col">' . esc_html__( 'Property', 'focus-cache' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Value', 'focus-cache' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';
	}

	/**
	 * Outputs the end of a key-value section.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	protected function output_summary_section_end(): void {
		echo '</tbody>';
		echo '</table>';
		echo '</section>';
	}
}
