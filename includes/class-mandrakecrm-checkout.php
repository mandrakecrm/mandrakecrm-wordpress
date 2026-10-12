<?php
/**
 * MandrakeCRM Checkout
 *
 * Handles marketing opt-in functionality at checkout: the email checkbox and,
 * since 3.25, a separate WhatsApp checkbox, both in the classic and the block checkout.
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
	 * Order meta key for the email marketing opt-in ('yes' or 'no'), the key the MandrakeCRM API reads.
	 *
	 * Saved only when the checkbox was shown. Before 3.25 the plugin saved '_mandrakecrm_marketing_optin'
	 * (LEGACY_META_KEY), which the API never read: those orders stay without effect on purpose.
	 *
	 * @since 2.0.0
	 * @since 3.25 '_mandrakecrm_marketing_opt_in'.
	 * @var string
	 */
	const META_KEY = '_mandrakecrm_marketing_opt_in';

	/**
	 * Order meta key used before 3.25 (only read, to show older orders in the admin).
	 *
	 * @since 3.25
	 * @var string
	 */
	const LEGACY_META_KEY = '_mandrakecrm_marketing_optin';

	/**
	 * Order meta key: whether the email checkbox started ticked ('yes' or 'no').
	 *
	 * Lets MandrakeCRM tell a box left ticked (legitimate interest) from a box the customer ticked.
	 *
	 * @since 3.25
	 * @var string
	 */
	const DEFAULT_META_KEY = '_mandrakecrm_marketing_opt_in_default';

	/**
	 * Additional checkout field id of the email opt-in (block checkout).
	 *
	 * @since 3.25
	 * @var string
	 */
	const MARKETING_FIELD_ID = 'mandrakecrm/marketing-optin';

	/**
	 * Order meta key for the WhatsApp opt-in ('yes' or 'no').
	 *
	 * @since 3.25
	 * @var string
	 */
	const WHATSAPP_META_KEY = '_mandrakecrm_whatsapp_opt_in';

	/**
	 * Additional checkout field id of the WhatsApp opt-in (block checkout).
	 *
	 * @since 3.25
	 * @var string
	 */
	const WHATSAPP_FIELD_ID = 'mandrakecrm/whatsapp-optin';

	/**
	 * Option: show the WhatsApp checkbox at checkout ('1' by default).
	 *
	 * @since 3.25
	 * @var string
	 */
	const WHATSAPP_OPTION = 'mandrakecrm_whatsapp_optin';

	/**
	 * Option: the store name in MandrakeCRM, as last reported by the MandrakeCRM API.
	 *
	 * @since 3.25
	 * @var string
	 */
	const STORE_NAME_OPTION = 'mandrakecrm_store_name';

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

		// WhatsApp opt-in (3.25): classic checkout.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'add_whatsapp_checkbox' ), 11 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_whatsapp_optin' ), 10, 2 );

		// Email and WhatsApp opt-in (3.25): block checkout, through the Additional Checkout Fields API.
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_fields' ) );
		add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'save_block_field' ), 10, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'require_block_answers' ), 10, 2 );
		add_filter( 'woocommerce_get_default_value_for_' . self::MARKETING_FIELD_ID, array( __CLASS__, 'marketing_block_field_default' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_' . self::WHATSAPP_FIELD_ID, array( __CLASS__, 'whatsapp_block_field_default' ), 10, 3 );
	}

	/**
	 * Whether the WhatsApp checkbox should be shown at checkout.
	 *
	 * Decided only by the plugin option (on by default), whatever the MandrakeCRM plan: a store on any plan
	 * collects the consent, so it is ready when the plan includes WhatsApp campaigns.
	 *
	 * @since 3.25
	 * @return bool
	 */
	public static function is_whatsapp_optin_enabled() {
		if ( '' === (string) get_option( 'mandrakecrm_token', '' ) ) {
			return false;
		}
		$enabled = '1' === (string) get_option( self::WHATSAPP_OPTION, '1' );

		/**
		 * Filters whether the WhatsApp opt-in checkbox is shown at checkout.
		 *
		 * @since 3.25
		 * @param bool $enabled Whether the checkbox is shown.
		 */
		return (bool) apply_filters( 'mandrakecrm_whatsapp_optin_enabled', $enabled );
	}

	/**
	 * Store the store name reported by the MandrakeCRM API (token verification and status sync).
	 *
	 * Ignores answers without a name, so the last known value is kept.
	 *
	 * @since 3.25
	 * @param array|null $body Decoded API response.
	 */
	public static function update_store_name( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['store_name'] ) || ! is_string( $body['store_name'] ) ) {
			return;
		}
		$name = sanitize_text_field( $body['store_name'] );
		if ( '' !== $name && get_option( self::STORE_NAME_OPTION ) !== $name ) {
			update_option( self::STORE_NAME_OPTION, $name );
		}
	}

	/**
	 * The store name shown to customers: the name in MandrakeCRM or, until the API reports it, the site name.
	 *
	 * @since 3.25
	 * @return string Plain text (escape on output).
	 */
	public static function get_store_name() {
		$name = trim( (string) get_option( self::STORE_NAME_OPTION, '' ) );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $name;
	}

	/**
	 * Label of the WhatsApp checkbox, with the store name.
	 *
	 * @since 3.25
	 * @return string Plain text (escape on output).
	 */
	public static function get_whatsapp_label() {
		$store_name = self::get_store_name();

		return (string) apply_filters(
			'mandrakecrm_whatsapp_checkbox_label',
			sprintf(
				/* translators: %s: store name */
				__( 'I agree to receive offers from %s on WhatsApp', 'mandrakecrm' ),
				$store_name
			)
		);
	}

	/**
	 * Whether the WhatsApp checkbox starts ticked.
	 *
	 * @since 3.25
	 * @return bool
	 */
	private static function whatsapp_checkbox_default() {
		/**
		 * Filters whether the WhatsApp opt-in checkbox starts ticked.
		 *
		 * @since 3.25
		 * @param bool $checked Default true.
		 */
		return (bool) apply_filters( 'mandrakecrm_whatsapp_checkbox_default', true );
	}

	/**
	 * Add the WhatsApp consent checkbox to the classic checkout.
	 *
	 * @since 3.25
	 */
	public static function add_whatsapp_checkbox() {
		if ( ! self::is_whatsapp_optin_enabled() ) {
			return;
		}
		?>
		<div class="mandrakecrm-marketing-optin mandrakecrm-whatsapp-optin">
			<input type="hidden" name="mandrakecrm_whatsapp_optin_shown" value="1" />
			<label>
				<input
					type="checkbox"
					name="mandrakecrm_whatsapp_optin"
					id="mandrakecrm_whatsapp_optin"
					value="yes"
					<?php checked( self::whatsapp_checkbox_default() ); ?>
				/>
				<span><?php echo esc_html( self::get_whatsapp_label() ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save the WhatsApp opt-in of the classic checkout to the order.
	 *
	 * Only when the checkbox was shown: an order placed without it carries no value.
	 *
	 * @since 3.25
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public static function save_whatsapp_optin( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		if ( ! isset( $_POST['mandrakecrm_whatsapp_optin_shown'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$optin = isset( $_POST['mandrakecrm_whatsapp_optin'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['mandrakecrm_whatsapp_optin'] ) ) ? 'yes' : 'no';

		$order->update_meta_data( self::WHATSAPP_META_KEY, $optin );
	}

	/**
	 * Register the email and WhatsApp checkboxes in the block checkout.
	 *
	 * Uses the Additional Checkout Fields API (WooCommerce 8.9+); on older versions
	 * the block checkout simply shows no checkbox.
	 *
	 * @since 3.25
	 */
	public static function register_block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		self::register_block_field( self::MARKETING_FIELD_ID, self::get_marketing_label() );
		if ( self::is_whatsapp_optin_enabled() ) {
			self::register_block_field( self::WHATSAPP_FIELD_ID, self::get_whatsapp_label() );
		}
	}

	/**
	 * Register one optional checkbox of the block checkout.
	 *
	 * @since 3.25
	 * @param string $id    Field id.
	 * @param string $label Label (plain text).
	 */
	private static function register_block_field( $id, $label ) {
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => $id,
					'label'    => $label,
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Throwable $e ) {
			// A registration error must never break the checkout.
			return;
		}
	}

	/**
	 * Whether a block checkbox may start ticked for this object.
	 *
	 * Only while the customer is at the checkout (draft order, customer or session): a placed order
	 * without the field (for example, older than 3.25) must never read as consent.
	 *
	 * @since 3.25
	 * @param mixed $wc_object Object being read.
	 * @return bool
	 */
	private static function is_checkout_object( $wc_object ) {
		return ! ( $wc_object instanceof WC_Order && 'checkout-draft' !== $wc_object->get_status() );
	}

	/**
	 * Start the block email checkbox as the classic one does (ticked by default).
	 *
	 * @since 3.25
	 * @param mixed  $value     Default value.
	 * @param string $group     Field group.
	 * @param mixed  $wc_object Object being read.
	 * @return mixed
	 */
	public static function marketing_block_field_default( $value, $group, $wc_object ) {
		if ( ! self::is_checkout_object( $wc_object ) ) {
			return $value;
		}
		return self::marketing_checkbox_default() ? '1' : $value;
	}

	/**
	 * Start the block checkbox ticked (same default as the classic checkout).
	 *
	 * @since 3.25
	 * @param mixed  $value     Default value.
	 * @param string $group     Field group.
	 * @param mixed  $wc_object Object being read.
	 * @return mixed
	 */
	public static function whatsapp_block_field_default( $value, $group, $wc_object ) {
		if ( ! self::is_checkout_object( $wc_object ) ) {
			return $value;
		}
		return self::whatsapp_checkbox_default() ? '1' : $value;
	}

	/**
	 * Copy a block checkbox to the order meta the MandrakeCRM API reads.
	 *
	 * @since 3.25
	 * @param string $key       Field id.
	 * @param mixed  $value     Field value.
	 * @param string $group     Field group.
	 * @param mixed  $wc_object Object being saved.
	 */
	public static function save_block_field( $key, $value, $group, $wc_object ) {
		if ( ! ( $wc_object instanceof WC_Order ) ) {
			return;
		}
		$answer = ( true === $value || '1' === $value || 1 === $value ) ? 'yes' : 'no';
		if ( self::MARKETING_FIELD_ID === $key ) {
			$wc_object->update_meta_data( self::META_KEY, $answer );
			$wc_object->update_meta_data( self::DEFAULT_META_KEY, self::marketing_checkbox_default() ? 'yes' : 'no' );
		} elseif ( self::WHATSAPP_FIELD_ID === $key ) {
			$wc_object->update_meta_data( self::WHATSAPP_META_KEY, $answer );
		}
	}

	/**
	 * Record each block choice only when the checkout request actually carried it.
	 *
	 * A Store API client that never showed the checkboxes (for example, a custom headless
	 * storefront) sends no value, and WooCommerce would fill in the default: that must not
	 * read as an answer, so the order keeps no value for that checkbox then.
	 *
	 * @since 3.25
	 * @param WC_Order        $order   Order being placed.
	 * @param WP_REST_Request $request Checkout request.
	 */
	public static function require_block_answers( $order, $request ) {
		if ( ! ( $order instanceof WC_Order ) || ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return;
		}
		$fields = $request->get_param( 'additional_fields' );
		$fields = is_array( $fields ) ? $fields : array();
		if ( ! array_key_exists( self::MARKETING_FIELD_ID, $fields ) ) {
			$order->delete_meta_data( self::META_KEY );
			$order->delete_meta_data( self::DEFAULT_META_KEY );
			// WooCommerce's own copy of the field (it would show as "Yes" on the order).
			$order->delete_meta_data( '_wc_other/' . self::MARKETING_FIELD_ID );
		}
		if ( ! array_key_exists( self::WHATSAPP_FIELD_ID, $fields ) ) {
			$order->delete_meta_data( self::WHATSAPP_META_KEY );
			$order->delete_meta_data( '_wc_other/' . self::WHATSAPP_FIELD_ID );
		}
	}

	/**
	 * Whether the email checkbox starts ticked.
	 *
	 * @since 3.25
	 * @return bool
	 */
	private static function marketing_checkbox_default() {
		/**
		 * Filters whether the email opt-in checkbox starts ticked.
		 *
		 * @since 2.0.0
		 * @param bool $checked Default true.
		 */
		return (bool) apply_filters( 'mandrakecrm_marketing_checkbox_default', true );
	}

	/**
	 * Label of the email checkbox.
	 *
	 * @since 3.25
	 * @return string Plain text (escape on output).
	 */
	public static function get_marketing_label() {
		return (string) apply_filters(
			'mandrakecrm_marketing_checkbox_label',
			__( 'I would like to receive marketing emails about promotions, new products, and exclusive offers.', 'mandrakecrm' )
		);
	}

	/**
	 * Add marketing consent checkbox to checkout.
	 *
	 * @since 2.0.0
	 * @since 3.25 Hidden fields tell MandrakeCRM that the box was shown and how it started.
	 */
	public static function add_marketing_checkbox() {
		$checked = self::marketing_checkbox_default();

		?>
		<div class="mandrakecrm-marketing-optin">
			<input type="hidden" name="mandrakecrm_marketing_optin_shown" value="1" />
			<input type="hidden" name="mandrakecrm_marketing_optin_default" value="<?php echo $checked ? 'yes' : 'no'; ?>" />
			<label>
				<input
					type="checkbox"
					name="mandrakecrm_marketing_optin"
					id="mandrakecrm_marketing_optin"
					value="yes"
					<?php checked( $checked ); ?>
				/>
				<span><?php echo esc_html( self::get_marketing_label() ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save marketing opt-in value to order.
	 *
	 * Only when the checkbox was shown: an order placed without it (another checkout, the admin,
	 * an import) carries no value, and MandrakeCRM leaves the contact's consent as it is.
	 *
	 * @since 2.0.0
	 * @since 3.25 Saved as '_mandrakecrm_marketing_opt_in', the key MandrakeCRM reads, with how the box started.
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public static function save_marketing_optin( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		if ( ! isset( $_POST['mandrakecrm_marketing_optin_shown'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$optin = isset( $_POST['mandrakecrm_marketing_optin'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['mandrakecrm_marketing_optin'] ) ) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$started = isset( $_POST['mandrakecrm_marketing_optin_default'] ) && 'no' === sanitize_text_field( wp_unslash( $_POST['mandrakecrm_marketing_optin_default'] ) ) ? 'no' : 'yes';

		$order->update_meta_data( self::META_KEY, $optin );
		$order->update_meta_data( self::DEFAULT_META_KEY, $started );
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
			$optin = $order->get_meta( self::LEGACY_META_KEY );
		}

		if ( empty( $optin ) ) {
			self::display_whatsapp_optin_in_admin( $order );
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
		self::display_whatsapp_optin_in_admin( $order );
	}

	/**
	 * Display the WhatsApp opt-in of the order in the admin order page.
	 *
	 * @since 3.25
	 * @param WC_Order $order Order object.
	 */
	private static function display_whatsapp_optin_in_admin( $order ) {
		$optin = $order->get_meta( self::WHATSAPP_META_KEY );

		if ( empty( $optin ) ) {
			return;
		}
		?>
		<div class="mandrakecrm-optin-status" style="margin-top: 8px;">
			<p style="margin: 0;">
				<strong style="color: #1e293b;">
					<?php esc_html_e( 'WhatsApp Consent:', 'mandrakecrm' ); ?>
				</strong>
				<?php if ( 'yes' === $optin ) : ?>
					<span style="color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e( 'Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php else : ?>
					<span style="color: #991b1b; background: #fee2e2; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e( 'Not Subscribed', 'mandrakecrm' ); ?>
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

		$optin = $order->get_meta( self::META_KEY );
		if ( '' === $optin ) {
			$optin = $order->get_meta( self::LEGACY_META_KEY );
		}
		return 'yes' === $optin;
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
