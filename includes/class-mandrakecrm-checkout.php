<?php
/**
 * MandrakeCRM Checkout
 *
 * Handles marketing opt-in functionality at checkout.
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
 * Checkout class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_Checkout {

	/**
	 * Meta key for marketing opt-in.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const META_KEY = '_mandrakecrm_marketing_optin';

	/**
	 * Initialize checkout hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$token = get_option( 'mandrakecrm_token', '' );

		// Do not load marketing opt-in if no valid token
		if ( empty( $token ) ) {
			return;
		}

		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'add_marketing_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_marketing_optin' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'display_optin_in_admin' ), 5 );
	}

	/**
	 * Add marketing consent checkbox to checkout.
	 *
	 * @since 2.0.0
	 */
	public static function add_marketing_checkbox() {
		$checked = apply_filters( 'mandrakecrm_marketing_checkbox_default', true );

		?>
		<div class="mandrakecrm-marketing-optin">
			<label>
				<input
					type="checkbox"
					name="mandrakecrm_marketing_optin"
					id="mandrakecrm_marketing_optin"
					value="yes"
					<?php checked( $checked ); ?>
				/>
				<span>
					<?php
					echo esc_html(
						apply_filters(
							'mandrakecrm_marketing_checkbox_label',
							__('I would like to receive marketing emails about promotions, new products, and exclusive offers.', 'mandrakecrm' )
						)
					);
					?>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save marketing opt-in value to order.
	 *
	 * @since 2.0.0
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public static function save_marketing_optin( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$optin = isset( $_POST['mandrakecrm_marketing_optin'] ) && 'yes' === $_POST['mandrakecrm_marketing_optin'] ? 'yes' : 'no';

		$order->update_meta_data( self::META_KEY, sanitize_text_field( $optin ) );
	}

	/**
	 * Display opt-in status in admin order page.
	 *
	 * @since 2.0.0
	 * @param WC_Order $order Order object.
	 */
	public static function display_optin_in_admin( $order ) {
		$optin = $order->get_meta( self::META_KEY );

		if ( empty( $optin ) ) {
			return;
		}

		$is_subscribed = 'yes' === $optin;

		?>
		<div class="mandrakecrm-optin-status" style="margin-top: 15px;">
			<p style="margin: 0;">
				<strong style="color: #1e293b;">
					<?php esc_html_e('Marketing Consent:', 'mandrakecrm' ); ?>
				</strong>
				<?php if ( $is_subscribed ) : ?>
					<span style="color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e('Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php else : ?>
					<span style="color: #991b1b; background: #fee2e2; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e('Not Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get marketing consent for an order.
	 *
	 * @since 2.0.0
	 * @param int|WC_Order $order Order ID or object.
	 * @return bool True if customer opted in, false otherwise.
	 */
	public static function get_marketing_consent( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return false;
		}

		return 'yes' === $order->get_meta( self::META_KEY );
	}

	/**
	 * Get customer marketing consent from their latest order.
	 *
	 * @since 2.0.0
	 * @param int $customer_id Customer ID.
	 * @return bool|null True if opted in, false if not, null if no orders.
	 */
	public static function get_customer_marketing_consent( $customer_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		return self::get_marketing_consent( $orders[0] );
	}
}
