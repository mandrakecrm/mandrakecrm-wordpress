<?php
/**
 * MandrakeCRM UTM Tracking
 *
 * Handles UTM parameter tracking for orders.
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
 * UTM Tracking class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_UTM {

	/**
	 * UTM parameters to track.
	 *
	 * Only includes parameters processed by backend for conversion tracking.
	 * Excludes ad platform IDs (gclid, fbclid, utm_id) as they are not used.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const UTM_PARAMS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
	);

	/**
	 * Cookie name for UTM data.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const COOKIE_NAME = 'mandrakecrm_utm';

	/**
	 * Cookie expiration in days.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const COOKIE_EXPIRY_DAYS = 30;

	/**
	 * Initialize UTM tracking.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$token = get_option( 'mandrakecrm_token', '' );

		// Do not load UTM tracking if no valid token
		if ( empty( $token ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'capture_utm_params' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_utm_to_order' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'display_utm_in_admin' ) );
	}

	/**
	 * Capture UTM parameters from URL.
	 *
	 * @since 2.0.0
	 */
	public static function capture_utm_params() {
		if ( is_admin() ) {
			return;
		}

		$utm_data = array();
		$has_utm  = false;

		foreach ( self::UTM_PARAMS as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && ! empty( $_GET[ $param ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$utm_data[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
				$has_utm            = true;
			}
		}

		if ( $has_utm ) {
			$existing_utm = self::get_utm_from_cookie();
			$merged_utm   = array_merge( $existing_utm, $utm_data );

			$merged_utm['captured_at'] = current_time( 'mysql' );

			$expiry = time() + ( self::COOKIE_EXPIRY_DAYS * DAY_IN_SECONDS );

			setcookie(
				self::COOKIE_NAME,
				wp_json_encode( $merged_utm ),
				$expiry,
				COOKIEPATH,
				COOKIE_DOMAIN,
				is_ssl(),
				true
			);

			$_COOKIE[ self::COOKIE_NAME ] = wp_json_encode( $merged_utm );
		}
	}

	/**
	 * Get UTM data from cookie.
	 *
	 * @since 2.0.0
	 * @return array UTM data.
	 */
	public static function get_utm_from_cookie() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$data = json_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save UTM data to order.
	 *
	 * @since 2.0.0
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public static function save_utm_to_order( $order, $data ) {
		$utm_data = self::get_utm_from_cookie();

		if ( empty( $utm_data ) ) {
			return;
		}

		foreach ( self::UTM_PARAMS as $param ) {
			if ( ! empty( $utm_data[ $param ] ) ) {
				$order->update_meta_data( '_mandrakecrm_' . $param, sanitize_text_field( $utm_data[ $param ] ) );
			}
		}

		$order->update_meta_data( '_mandrakecrm_utm_data', wp_json_encode( $utm_data ) );

		if ( ! empty( $utm_data['captured_at'] ) ) {
			$order->update_meta_data( '_mandrakecrm_utm_captured_at', sanitize_text_field( $utm_data['captured_at'] ) );
		}
	}

	/**
	 * Display UTM data in admin order page.
	 *
	 * @since 2.0.0
	 * @param WC_Order $order Order object.
	 */
	public static function display_utm_in_admin( $order ) {
		$utm_json = $order->get_meta( '_mandrakecrm_utm_data' );

		if ( empty( $utm_json ) ) {
			return;
		}

		$utm_data = json_decode( $utm_json, true );

		if ( empty( $utm_data ) ) {
			return;
		}

		?>
		<div class="mandrakecrm-utm-data" style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-left: 4px solid #6366f1;">
			<h4 style="margin: 0 0 10px 0; color: #1e293b;">
				<span class="dashicons dashicons-chart-bar" style="color: #6366f1;"></span>
				<?php esc_html_e('MandrakeCRM UTM Tracking', 'mandrakecrm' ); ?>
			</h4>
			<table style="width: 100%; border-collapse: collapse;">
				<?php foreach ( self::UTM_PARAMS as $param ) : ?>
					<?php if ( ! empty( $utm_data[ $param ] ) ) : ?>
						<tr>
							<td style="padding: 4px 8px 4px 0; font-weight: 500; color: #64748b; width: 120px;">
								<?php echo esc_html( $param ); ?>
							</td>
							<td style="padding: 4px 0; color: #1e293b;">
								<?php echo esc_html( $utm_data[ $param ] ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( ! empty( $utm_data['captured_at'] ) ) : ?>
					<tr>
						<td style="padding: 4px 8px 4px 0; font-weight: 500; color: #64748b; width: 120px;">
							<?php esc_html_e('Captured', 'mandrakecrm' ); ?>
						</td>
						<td style="padding: 4px 0; color: #1e293b;">
							<?php echo esc_html( $utm_data['captured_at'] ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Get UTM data for an order.
	 *
	 * @since 2.0.0
	 * @param int|WC_Order $order Order ID or object.
	 * @return array UTM data.
	 */
	public static function get_order_utm_data( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return array();
		}

		$utm_json = $order->get_meta( '_mandrakecrm_utm_data' );

		if ( empty( $utm_json ) ) {
			return array();
		}

		return json_decode( $utm_json, true ) ?: array();
	}
}
