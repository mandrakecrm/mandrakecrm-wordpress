<?php
/**
 * MandrakeCRM Widget
 *
 * Handles the lead capture widget functionality.
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_Widget {

	/**
	 * Initialize widget hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$enabled = get_option( 'mandrakecrm_widget_option', '0' ) === '1';

		if ( ! $enabled ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_widget_script' ) );
	}

	/**
	 * Enqueue the widget script.
	 *
	 * Uses widget_token for CDN URL (not exposing API token).
	 * URL format: /widgets/{widget_token}/widget.js
	 *
	 * @since 2.0.0
	 * @since 3.12.0 Changed to use widget_token for CDN URLs
	 */
	public static function enqueue_widget_script() {
		if ( is_admin() || is_checkout() || is_cart() ) {
			return;
		}

		// widget_token is saved when verifying API token
		$widget_token = get_option( 'mandrakecrm_widget_token', '' );

		if ( empty( $widget_token ) ) {
			return;
		}

		// New URL structure: /widgets/{widget_token}/widget.js
		$script_url = MANDRAKECRM_CDN_BASE . '/widgets/' . sanitize_text_field( $widget_token ) . '/widget.js';
		$script_url = add_query_arg( 'v', time(), $script_url );

		wp_register_script(
			'mandrakecrm-widget',
			$script_url,
			array(),
			null,
			true
		);

		add_filter( 'script_loader_tag', array( __CLASS__, 'add_async_attribute' ), 10, 2 );

		wp_enqueue_script( 'mandrakecrm-widget' );
	}

	/**
	 * Add async attribute to the widget script.
	 *
	 * @since 2.0.0
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @return string Modified script tag.
	 */
	public static function add_async_attribute( $tag, $handle ) {
		if ( 'mandrakecrm-widget' !== $handle ) {
			return $tag;
		}

		return str_replace( ' src', ' async defer src', $tag );
	}

	/**
	 * Check if widget should be displayed.
	 *
	 * @since 2.0.0
	 * @since 3.12.0 Now checks for widget_token instead of API token
	 * @return bool True if widget should be displayed.
	 */
	public static function should_display() {
		if ( is_admin() || is_checkout() || is_cart() ) {
			return false;
		}

		$enabled = get_option( 'mandrakecrm_widget_option', '0' ) === '1';

		if ( ! $enabled ) {
			return false;
		}

		$widget_token = get_option( 'mandrakecrm_widget_token', '' );

		if ( empty( $widget_token ) ) {
			return false;
		}

		return true;
	}
}
