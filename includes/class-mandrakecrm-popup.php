<?php
/**
 * MandrakeCRM Popup
 *
 * Handles the lead capture popup functionality.
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
 * Popup class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_Popup {

	/**
	 * Initialize popup hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$enabled = get_option( 'mandrakecrm_popup_option', '0' ) === '1';

		if ( ! $enabled ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_popup_script' ) );
	}

	/**
	 * Enqueue the popup script.
	 *
	 * @since 2.0.0
	 */
	public static function enqueue_popup_script() {
		if ( is_admin() || is_checkout() || is_cart() ) {
			return;
		}

		$token = get_option( 'mandrakecrm_token', '' );

		if ( empty( $token ) ) {
			return;
		}

		$script_url = MANDRAKECRM_CDN_BASE . '/popup/' . sanitize_text_field( $token ) . '.js';
		$script_url = add_query_arg( 'v', time(), $script_url );

		wp_register_script(
			'mandrakecrm-popup',
			$script_url,
			array(),
			null,
			true
		);

		add_filter( 'script_loader_tag', array( __CLASS__, 'add_async_attribute' ), 10, 2 );

		wp_enqueue_script( 'mandrakecrm-popup' );
	}

	/**
	 * Add async attribute to the popup script.
	 *
	 * @since 2.0.0
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @return string Modified script tag.
	 */
	public static function add_async_attribute( $tag, $handle ) {
		if ( 'mandrakecrm-popup' !== $handle ) {
			return $tag;
		}

		return str_replace( ' src', ' async defer src', $tag );
	}

	/**
	 * Check if popup should be displayed.
	 *
	 * @since 2.0.0
	 * @return bool True if popup should be displayed.
	 */
	public static function should_display() {
		if ( is_admin() || is_checkout() || is_cart() ) {
			return false;
		}

		$enabled = get_option( 'mandrakecrm_popup_option', '0' ) === '1';

		if ( ! $enabled ) {
			return false;
		}

		$token = get_option( 'mandrakecrm_token', '' );

		if ( empty( $token ) ) {
			return false;
		}

		return true;
	}
}
