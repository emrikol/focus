<?php
/**
 * Name: FOCUS Object Cache
 * Plugin URI: http://wordpress.org/plugins/focus-object-cache/
 * Description: File-based Object Cache is Utterly Slow: An Object Caching Dropin for WordPress that uses the local file system.
 * Version: 1.0.1
 * Text Domain: focus-cache
 * Author: Derrick Tennant
 * Author URI: https://emrikol.com/
 * GitHub Plugin URI: https://github.com/emrikol/focus/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WordPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">

	<h1><?php esc_html_e( 'FOCUS Object Cache', 'focus-cache' ); ?></h1>
	<h2 class="title"><?php esc_html_e( 'Overview', 'focus-cache' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Status:', 'focus-cache' ); ?></th>
			<td><code><?php echo esc_html( $this->get_status() ); ?></code></td>
		</tr>

		<?php if ( ! is_null( $this->get_focus_cachekey_prefix() ) && trim( $this->get_focus_cachekey_prefix() ) !== '' ) : ?>
			<tr>
				<th><?php esc_html_e( 'Key Prefix:', 'focus-cache' ); ?></th>
				<td>
					<code><?php echo esc_html( $this->get_focus_cachekey_prefix() ); ?></code>
					<p class="description" id="cachekey-prefix-description">The cache key prefix can be changed by setting the <code>WP_CACHE_KEY_SALT</code>code> constant.</p>
				</td>
			</tr>
		<?php endif; ?>

		<?php if ( ! is_null( $this->get_focus_maxttl() ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Max. TTL:', 'focus-cache' ); ?></th>
				<td>
					<code><?php echo esc_html( $this->get_focus_maxttl() ); ?></code> seconds
					<p class="description" id="maxttl-description">The maximum TTL can be changed by setting the <code>WP_FOCUS_MAXTTL</code> constant.</p>
				</td>
			</tr>
		<?php endif; ?>

	</table>
	<p class="submit">

		<?php if ( $this->object_cache_dropin_exists() ) : ?>
			<a href="<?php echo esc_url( wp_nonce_url( network_admin_url( add_query_arg( 'action', 'flush-cache', $this->page ) ), 'flush-cache' ) ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Flush Cache', 'focus-cache' ); ?></a> &nbsp;
		<?php endif; ?>

		<?php if ( ! $this->object_cache_dropin_exists() ) : ?>
			<a href="<?php echo esc_url( wp_nonce_url( network_admin_url( add_query_arg( 'action', 'enable-cache', $this->page ) ), 'enable-cache' ) ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Enable Object Cache', 'focus-cache' ); ?></a>
		<?php elseif ( $this->validate_object_cache_dropin() ) : ?>
			<a href="<?php echo esc_url( wp_nonce_url( network_admin_url( add_query_arg( 'action', 'disable-cache', $this->page ) ), 'disable-cache' ) ); ?>" class="button button-secondary button-large delete"><?php esc_html_e( 'Disable Object Cache', 'focus-cache' ); ?></a>
		<?php endif; ?>

	</p>
</div>
