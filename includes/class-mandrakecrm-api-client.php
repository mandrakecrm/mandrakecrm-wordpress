<?php
/**
 * MandrakeCRM API Client
 *
 * Handles communication with MandrakeCRM services.
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
 * API Client class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_API_Client {

	/**
	 * Get the configured API token.
	 *
	 * @since 2.0.0
	 * @return string The API token.
	 */
	public static function get_token() {
		return get_option( 'mandrakecrm_token', '' );
	}

	/**
	 * Verify if the token is valid.
	 *
	 * @since 2.0.0
	 * @param string $token Optional. Token to verify. Default null uses stored token.
	 * @return array Result with valid status and store info.
	 */
	public static function verify_token( $token = null ) {
		if ( null === $token ) {
			$token = self::get_token();
		}

		if ( empty( $token ) ) {
			return array(
				'valid' => false,
				'error' => 'Token is empty',
			);
		}

		$response = wp_remote_get(
			MANDRAKECRM_API_BASE . '/store-plugin-status?token=' . urlencode( $token ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid' => false,
				'error' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body ) ) {
			return array(
				'valid' => false,
				'error' => 'Invalid response from server',
			);
		}

		return $body;
	}

	/**
	 * Sync plugin status with MandrakeCRM.
	 *
	 * @since 2.0.0
	 * @return bool True on success, false on failure.
	 */
	public static function sync_status() {
		$token = self::get_token();

		if ( empty( $token ) ) {
			return false;
		}

		// Resolve WooCommerce my-account URLs so the backend can build customer-facing links
		// honoring the merchant's localized/customized slugs (e.g. /minha-conta/pedidos/).
		$myaccount_url = '';
		$orders_url    = '';
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$myaccount_url = (string) wc_get_page_permalink( 'myaccount' );
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$orders_url = (string) wc_get_account_endpoint_url( 'orders' );
		}

		$payload = array(
			'token'          => $token,
			'action'         => 'heartbeat',
			'plugin_version' => MANDRAKECRM_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'php_version'    => PHP_VERSION,
			'site_url'       => home_url(),
			'features'       => array(
				'transactional_emails' => get_option( 'mandrakecrm_transactional_emails', '0' ) === '1',
				'widget_leads'         => get_option( 'mandrakecrm_widget_option', '0' ) === '1',
				'utm_tracking'         => true,
				'marketing_optin'      => true,
				'abandoned_cart'       => get_option( 'mandrakecrm_abandoned_cart', '0' ) === '1',
			),
			'account_urls'   => array(
				'myaccount' => $myaccount_url,
				'orders'    => $orders_url,
			),
		);

		$response = wp_remote_post(
			MANDRAKECRM_API_BASE . '/store-plugin-status',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'MandrakeCRM: sync_status - Network error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Validate HTTP status code
		if ( 200 !== $code ) {
			error_log( 'MandrakeCRM: sync_status - HTTP error: ' . $code );
			return false;
		}

		// CRITICAL: Validate response.success in body (not just HTTP 200)
		if ( empty( $body ) || ! isset( $body['success'] ) || true !== $body['success'] ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error from server';
			error_log( 'MandrakeCRM: sync_status - Response error: ' . $error_msg );
			return false;
		}

		return true;
	}

	/**
	 * Notify MandrakeCRM of plugin deactivation.
	 *
	 * @since 2.0.0
	 * @return bool True on success, false on failure.
	 */
	public static function notify_deactivation() {
		$token = self::get_token();

		if ( empty( $token ) ) {
			return false;
		}

		$payload = array(
			'token'  => $token,
			'action' => 'deactivate',
		);

		$response = wp_remote_post(
			MANDRAKECRM_API_BASE . '/store-plugin-status',
			array(
				'timeout' => 5,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'MandrakeCRM: notify_deactivation - Network error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Validate HTTP status code
		if ( 200 !== $code ) {
			error_log( 'MandrakeCRM: notify_deactivation - HTTP error: ' . $code );
			return false;
		}

		// CRITICAL: Validate response.success in body
		if ( empty( $body ) || ! isset( $body['success'] ) || true !== $body['success'] ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			error_log( 'MandrakeCRM: notify_deactivation - Response error: ' . $error_msg );
			return false;
		}

		return true;
	}

	/**
	 * Notify MandrakeCRM of integration disconnection.
	 *
	 * @since 2.0.0
	 * @return bool True on success, false on failure.
	 */
	public static function notify_disconnection() {
		$token = self::get_token();

		if ( empty( $token ) ) {
			return false;
		}

		$payload = array(
			'token'  => $token,
			'action' => 'disconnect',
		);

		$response = wp_remote_post(
			MANDRAKECRM_API_BASE . '/store-plugin-status',
			array(
				'timeout' => 5,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'MandrakeCRM: notify_disconnection - Network error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Validate HTTP status code
		if ( 200 !== $code ) {
			error_log( 'MandrakeCRM: notify_disconnection - HTTP error: ' . $code );
			return false;
		}

		// CRITICAL: Validate response.success in body
		if ( empty( $body ) || ! isset( $body['success'] ) || true !== $body['success'] ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
			error_log( 'MandrakeCRM: notify_disconnection - Response error: ' . $error_msg );
			return false;
		}

		return true;
	}

	/**
	 * Send transactional email via MandrakeCRM.
	 *
	 * @since 2.0.0
	 * @param string $email_type Email type identifier.
	 * @param string $recipient  Recipient email address.
	 * @param string $name       Recipient name.
	 * @param array  $data       Additional email data.
	 * @return bool True on success, false on failure.
	 */
	public static function send_email( $email_type, $recipient, $name, $data = array() ) {
		$token = self::get_token();

		if ( empty( $token ) ) {
			error_log( 'MandrakeCRM: send_email - No token configured' );
			return false;
		}

		$payload = array_merge(
			array(
				'token' => $token,
				'type'  => $email_type,
				'email' => $recipient,
				'name'  => $name,
			),
			$data
		);

		error_log( 'MandrakeCRM: send_email - Type: ' . $email_type . ', Recipient: ' . $recipient );
		error_log( 'MandrakeCRM: send_email - Payload: ' . wp_json_encode( $payload ) );

		$response = wp_remote_post(
			MANDRAKECRM_API_BASE . '/store-send-transactional',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InB6ampmZWNua2lhYW5pd3RseWRyIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTIyODkxMDYsImV4cCI6MjA2Nzg2NTEwNn0.C8fNmSEvZ1RTtfeVnXwPZLQzE9NRNMa5dbdo0mtfFso',
					'Origin'        => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'MandrakeCRM: send_email - Error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		error_log( 'MandrakeCRM: send_email - Response code: ' . $code . ', Body: ' . wp_json_encode( $body ) );

		// Validate HTTP status code
		if ( 200 !== $code ) {
			error_log( 'MandrakeCRM: send_email - HTTP error: ' . $code );
			return false;
		}

		// CRITICAL: Validate response.success in body (not just HTTP 200)
		if ( empty( $body ) || ! isset( $body['success'] ) || true !== $body['success'] ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error from server';
			error_log( 'MandrakeCRM: send_email - Response error: ' . $error_msg );
			return false;
		}

		return true;
	}
}
