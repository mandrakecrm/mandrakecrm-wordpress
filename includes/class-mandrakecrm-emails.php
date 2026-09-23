<?php
/**
 * MandrakeCRM Emails
 *
 * Handles transactional emails through MandrakeCRM services.
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
 * Emails class.
 *
 * Handles interception and replacement of WooCommerce transactional emails
 * with MandrakeCRM professional email service.
 *
 * @since 2.0.0
 */
class MandrakeCRM_Emails {

	/**
	 * WooCommerce emails replaced by MandrakeCRM.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const EMAILS_TO_DISABLE = array(
		'customer_new_account',
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_refunded_order',
	);

	/**
	 * Initialize email hooks.
	 *
	 * Hooks into woocommerce_email action to access email class instances,
	 * then removes WooCommerce's default email triggers and replaces them with
	 * custom MandrakeCRM email sending.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$enabled = get_option( 'mandrakecrm_transactional_emails', '0' ) === '1';
		$token   = get_option( 'mandrakecrm_token', '' );

		// Do not load if feature disabled OR no valid token
		if ( ! $enabled || empty( $token ) ) {
			return;
		}

		// Hook into woocommerce_email to disable WooCommerce emails
		// This is called AFTER email classes are initialized
		add_action( 'woocommerce_email', array( __CLASS__, 'disable_woocommerce_emails' ), 10, 1 );

		// Register custom email handlers on the SAME notification hooks
		// These fire when order status changes
		add_action( 'woocommerce_order_status_pending_to_processing_notification', array( __CLASS__, 'send_order_processing' ), 20, 1 );
		add_action( 'woocommerce_order_status_pending_to_completed_notification', array( __CLASS__, 'send_order_completed' ), 20, 1 );
		add_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( __CLASS__, 'send_order_on_hold' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( __CLASS__, 'send_order_on_hold' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed_notification', array( __CLASS__, 'send_order_completed' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded_notification', array( __CLASS__, 'send_order_refunded' ), 20, 1 );

		// New account and customer note
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'send_new_account' ), 20, 3 );
		add_action( 'woocommerce_new_customer_note_notification', array( __CLASS__, 'send_customer_note' ), 20, 1 );

		// Password reset - WooCommerce customer password reset
		add_action( 'woocommerce_reset_password_notification', array( __CLASS__, 'send_wc_password_reset' ), 10, 2 );

		// WordPress Core password reset (wp-login.php "Lost your password?")
		add_filter( 'retrieve_password_message', array( __CLASS__, 'handle_password_reset' ), 10, 4 );

		// Generic hook for ANY status change (catches dashboard changes)
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_status_change' ), 20, 3 );

		error_log( 'MandrakeCRM: Email hooks initialized' );
	}

	/**
	 * Disable WooCommerce emails by removing their action hooks.
	 *
	 * This method is hooked to 'woocommerce_email' action, which fires AFTER
	 * email classes are initialized, giving us access to the email class instances.
	 *
	 * Implementation follows standard WooCommerce email unhooking patterns.
	 *
	 * @since 2.0.0
	 * @param WC_Emails $email_class WooCommerce email class instance.
	 */
	public static function disable_woocommerce_emails( $email_class ) {
		// Check if email option is enabled
		$email_option = get_option( 'mandrakecrm_transactional_emails' );
		if ( '1' !== $email_option ) {
			return;
		}

		error_log( 'MandrakeCRM: Disabling WooCommerce default email triggers' );

		// Disable admin "New Order" notifications (multiple transitions)
		if ( isset( $email_class->emails['WC_Email_New_Order'] ) ) {
			remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		}

		// Disable customer "Processing Order" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Processing_Order'] ) ) {
			remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
		}

		// Disable customer "On Hold Order" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_On_Hold_Order'] ) ) {
			remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_Customer_On_Hold_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $email_class->emails['WC_Email_Customer_On_Hold_Order'], 'trigger' ) );
		}

		// Disable customer "Completed Order" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Completed_Order'] ) ) {
			remove_action( 'woocommerce_order_status_completed_notification', array( $email_class->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );
		}

		// Disable customer "Refunded Order" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Refunded_Order'] ) ) {
			remove_action( 'woocommerce_order_status_refunded_notification', array( $email_class->emails['WC_Email_Customer_Refunded_Order'], 'trigger' ) );
		}

		// Disable customer "Note" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Note'] ) ) {
			remove_action( 'woocommerce_new_customer_note_notification', array( $email_class->emails['WC_Email_Customer_Note'], 'trigger' ) );
		}

		// Disable customer "New Account" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_New_Account'] ) ) {
			remove_action( 'woocommerce_created_customer_notification', array( $email_class->emails['WC_Email_Customer_New_Account'], 'trigger' ) );
		}

		// Disable customer "Reset Password" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Reset_Password'] ) ) {
			remove_action( 'woocommerce_reset_password_notification', array( $email_class->emails['WC_Email_Customer_Reset_Password'], 'trigger' ) );
			error_log( 'MandrakeCRM: Disabled WooCommerce password reset email' );
		}

		// Disable stock notifications
		remove_action( 'woocommerce_low_stock_notification', array( $email_class, 'low_stock' ) );
		remove_action( 'woocommerce_no_stock_notification', array( $email_class, 'no_stock' ) );
		remove_action( 'woocommerce_product_on_backorder_notification', array( $email_class, 'backorder' ) );

		error_log( 'MandrakeCRM: WooCommerce default emails disabled successfully' );
	}

	/**
	 * Handle password reset email.
	 *
	 * Intercepts password reset requests from WordPress/WooCommerce and sends
	 * via MandrakeCRM API. Blocks WordPress default email to prevent duplicates.
	 *
	 * @since 2.0.0
	 * @param string  $message    Email message.
	 * @param string  $key        Reset key.
	 * @param string  $user_login User login.
	 * @param WP_User $user_data  User data.
	 * @return string Modified message.
	 */
	public static function handle_password_reset( $message, $key, $user_login, $user_data ) {
		// Check if MandrakeCRM transactional emails are enabled
		$enabled = get_option( 'mandrakecrm_transactional_emails', '0' ) === '1';
		$token   = get_option( 'mandrakecrm_token', '' );

		if ( ! $enabled || empty( $token ) ) {
			// Feature disabled or no token - let WordPress send default email
			error_log( 'MandrakeCRM: Password reset feature disabled or no token available' );
			return $message;
		}

		$user = get_user_by( 'email', $user_data->user_email );

		if ( ! $user ) {
			return $message;
		}

		$reset_url = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $user->user_login ),
			),
			wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
		);

		error_log( 'MandrakeCRM: Sending password reset email to ' . $user_data->user_email );

		$sent = MandrakeCRM_API_Client::send_email(
			'password_reset',
			$user_data->user_email,
			$user_data->display_name ? $user_data->display_name : $user_login,
			array( 'reset_url' => $reset_url )
		);

		if ( $sent ) {
			// API success: Block WordPress default email to prevent duplicates
			add_filter( 'wp_mail', array( __CLASS__, 'block_password_email' ), 1 );
		} else {
			// API failure: Increment failure counter and log
			error_log( 'MandrakeCRM: Password reset email FAILED to send via API' );

			$failure_count = get_transient( 'mandrakecrm_email_failures' );
			$failure_count = $failure_count ? (int) $failure_count + 1 : 1;
			set_transient( 'mandrakecrm_email_failures', $failure_count, WEEK_IN_SECONDS );

			// Show admin notice if threshold exceeded
			if ( $failure_count >= 5 ) {
				set_transient( 'mandrakecrm_show_api_failure_notice', true, WEEK_IN_SECONDS );
			}

			// Let WordPress send default email as fallback
		}

		return $message;
	}

	/**
	 * Block the default password reset email.
	 *
	 * @since 2.0.0
	 * @param array $args wp_mail arguments.
	 * @return array Modified arguments.
	 */
	public static function block_password_email( $args ) {
		$subjects = array( 'Password Reset', 'password reset', 'Restablecer', 'Redefinir' );

		foreach ( $subjects as $subject ) {
			if ( strpos( $args['subject'], $subject ) !== false ) {
				$args['to'] = '';
				remove_filter( 'wp_mail', array( __CLASS__, 'block_password_email' ), 1 );
				break;
			}
		}

		return $args;
	}

	/**
	 * Send WooCommerce customer password reset email.
	 *
	 * Triggered by woocommerce_reset_password_notification.
	 * Replaces WooCommerce default password reset email with MandrakeCRM transactional email.
	 *
	 * @since 2.1.0
	 * @param string $user_login Customer username
	 * @param string $key Password reset key generated by WooCommerce
	 */
	public static function send_wc_password_reset( $user_login, $key ) {
		// Check if feature is enabled
		$enabled = get_option( 'mandrakecrm_transactional_emails', '0' ) === '1';
		$token   = get_option( 'mandrakecrm_token', '' );

		if ( ! $enabled || empty( $token ) ) {
			error_log( 'MandrakeCRM: Password reset email disabled or no token configured' );
			return; // Let WooCommerce send default email
		}

		// Get user by login
		$user = get_user_by( 'login', $user_login );
		if ( ! $user ) {
			error_log( 'MandrakeCRM: User not found for password reset: ' . $user_login );
			return;
		}

		// Build password reset URL (same as WooCommerce)
		$reset_url = add_query_arg(
			array(
				'key'   => $key,
				'login' => rawurlencode( $user->user_login ),
			),
			wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
		);

		error_log( 'MandrakeCRM: Sending WooCommerce password reset email to ' . $user->user_email );
		error_log( 'MandrakeCRM: Reset URL: ' . $reset_url );

		// Send via MandrakeCRM API
		MandrakeCRM_API_Client::send_email(
			'password_reset',
			$user->user_email,
			$user->display_name ? $user->display_name : $user->user_login,
			array(
				'reset_url' => $reset_url,
				'user_login' => $user->user_login,
			)
		);
	}

	/**
	 * Send order processing email.
	 *
	 * Triggered by woocommerce_order_status_pending_to_processing_notification
	 *
	 * @since 2.0.0
	 * @param int $order_id Order ID.
	 */
	public static function send_order_processing( $order_id ) {
		self::send_order_email( $order_id, 'order_processing' );
	}

	/**
	 * Send order completed email.
	 *
	 * Triggered by woocommerce_order_status_pending_to_completed_notification
	 * and woocommerce_order_status_completed_notification
	 *
	 * @since 2.0.0
	 * @param int $order_id Order ID.
	 */
	public static function send_order_completed( $order_id ) {
		self::send_order_email( $order_id, 'order_completed' );
	}

	/**
	 * Send order on hold email.
	 *
	 * Triggered by woocommerce_order_status_pending_to_on-hold_notification
	 * and woocommerce_order_status_failed_to_on-hold_notification
	 *
	 * @since 2.0.0
	 * @param int $order_id Order ID.
	 */
	public static function send_order_on_hold( $order_id ) {
		self::send_order_email( $order_id, 'order_on_hold' );
	}

	/**
	 * Send order refunded email.
	 *
	 * Triggered by woocommerce_order_status_refunded_notification
	 *
	 * @since 2.0.0
	 * @param int $order_id Order ID.
	 */
	public static function send_order_refunded( $order_id ) {
		self::send_order_email( $order_id, 'order_refunded' );
	}

	/**
	 * Send order cancelled email.
	 *
	 * Triggered by status change to cancelled
	 *
	 * @since 2.1.0
	 * @param int $order_id Order ID.
	 */
	public static function send_order_cancelled( $order_id ) {
		self::send_order_email( $order_id, 'order_cancelled' );
	}

	/**
	 * Send order failed email.
	 *
	 * Triggered by status change to failed
	 *
	 * @since 2.1.0
	 * @param int $order_id Order ID.
	 */
	public static function send_order_failed( $order_id ) {
		self::send_order_email( $order_id, 'order_failed' );
	}

	/**
	 * Send new account email.
	 *
	 * Triggered by woocommerce_created_customer
	 *
	 * Replicates WC_Email_Customer_New_Account behavior by passing:
	 * - user_login: Username of the customer
	 * - user_email: Email of the customer
	 * - user_pass: Generated password (if available)
	 * - set_password_url: Magic link to set password (using get_password_reset_key)
	 *
	 * @since 2.0.0
	 * @param int    $customer_id       Customer ID.
	 * @param array  $new_customer_data Customer data.
	 * @param string $password_generated Generated password.
	 */
	public static function send_new_account( $customer_id, $new_customer_data, $password_generated = '' ) {
		$user = get_user_by( 'id', $customer_id );

		if ( ! $user ) {
			return;
		}

		error_log( 'MandrakeCRM: Sending new account email for customer ID: ' . $customer_id );

		// Build additional data array with user info (mirroring WC_Email_Customer_New_Account)
		$additional_data = array(
			'user_id'    => $customer_id,
			'user_login' => stripslashes( $user->user_login ),
			'user_email' => stripslashes( $user->user_email ),
		);

		// Include password if generated
		// Note: WooCommerce keeps this for backwards compatibility but recommends using set_password_url
		if ( ! empty( $password_generated ) ) {
			$additional_data['user_pass'] = $password_generated;
			$additional_data['password']  = $password_generated; // Alias for compatibility

			error_log( 'MandrakeCRM: Password generated for new account, length: ' . strlen( $password_generated ) );
		}

		// Generate set_password_url using WordPress Core get_password_reset_key()
		// Same method used by WooCommerce WC_Email_Customer_New_Account
		$key = get_password_reset_key( $user );

		if ( ! is_wp_error( $key ) ) {
			$set_password_url = add_query_arg(
				array(
					'key'   => $key,
					'login' => rawurlencode( $user->user_login ),
				),
				wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
			);

			$additional_data['set_password_url'] = $set_password_url;

			error_log( 'MandrakeCRM: Generated set_password_url: ' . $set_password_url );
		} else {
			error_log( 'MandrakeCRM: Failed to generate password reset key: ' . $key->get_error_message() );
		}

		MandrakeCRM_API_Client::send_email(
			'new_account',
			$user->user_email,
			$user->display_name ? $user->display_name : $user->user_login,
			$additional_data
		);
	}

	/**
	 * Send customer note email.
	 *
	 * Triggered by woocommerce_new_customer_note_notification
	 *
	 * @since 2.0.0
	 * @param array $args Customer note arguments.
	 */
	public static function send_customer_note( $args ) {
		if ( ! isset( $args['customer_note'] ) ) {
			return;
		}

		// Extract data from args - may come from order note
		$order_id = isset( $args['order_id'] ) ? $args['order_id'] : 0;
		$customer_email = isset( $args['customer_email'] ) ? $args['customer_email'] : '';
		$customer_name = isset( $args['customer_name'] ) ? $args['customer_name'] : '';

		// If email/name not in args, try to get from order
		if ( empty( $customer_email ) && $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$customer_email = $order->get_billing_email();
				$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			}
		}

		// Don't send if no email
		if ( empty( $customer_email ) ) {
			error_log( 'MandrakeCRM: Customer note email skipped - no email address' );
			return;
		}

		error_log( 'MandrakeCRM: Sending customer note email to ' . $customer_email );

		// Send via API
		MandrakeCRM_API_Client::send_email(
			'customer_note',
			$customer_email,
			$customer_name,
			array( 'note' => $args['customer_note'] )
		);
	}

	/**
	 * Handle generic status change for any order status transition.
	 *
	 * This method catches status changes that occur outside the specific
	 * notification hooks (e.g., from dashboard changes) by listening to
	 * the generic woocommerce_order_status_changed hook.
	 *
	 * @since 2.1.0
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old status (without wc- prefix).
	 * @param string $new_status New status (without wc- prefix).
	 */
	public static function handle_status_change( $order_id, $old_status, $new_status ) {
		error_log( 'MandrakeCRM: Generic status change - Order #' . $order_id . ' from ' . $old_status . ' to ' . $new_status );

		// Don't process if status didn't actually change
		if ( $old_status === $new_status ) {
			return;
		}

		// Prevent duplicate emails within 10 seconds
		if ( self::email_already_sent_for_status( $order_id, $new_status ) ) {
			return;
		}

		// Route to appropriate handler based on new status
		switch ( $new_status ) {
			case 'processing':
				self::send_order_processing( $order_id );
				break;
			case 'completed':
				self::send_order_completed( $order_id );
				break;
			case 'on-hold':
				self::send_order_on_hold( $order_id );
				break;
			case 'refunded':
				self::send_order_refunded( $order_id );
				break;
			case 'cancelled':
				self::send_order_cancelled( $order_id );
				break;
			case 'failed':
				self::send_order_failed( $order_id );
				break;
			case 'pending':
				// No email for pending status by default
				// (Could be customized if needed)
				error_log( 'MandrakeCRM: Order #' . $order_id . ' changed to pending - no email sent' );
				break;
			case 'checkout-draft':
				// No email for draft status
				break;
			default:
				error_log( 'MandrakeCRM: Unknown status ' . $new_status . ' for order #' . $order_id );
				break;
		}
	}

	/**
	 * Extract downloadable products from order.
	 *
	 * @since 2.1.0
	 * @param WC_Order $order Order object.
	 * @return array Array of downloadable products with access details.
	 */
	private static function get_order_downloads( $order ) {
		$downloads = array();
		$downloadable_items = $order->get_downloadable_items();

		if ( empty( $downloadable_items ) ) {
			return $downloads;
		}

		foreach ( $downloadable_items as $download ) {
			$downloads[] = array(
				'product_id'         => $download['product_id'],
				'product_name'       => $download['product_name'],
				'download_name'      => $download['download_name'],
				'download_url'       => $download['download_url'],
				'access_expires'     => $download['access_expires'],
				'downloads_remaining' => $download['downloads_remaining'],
			);
		}

		return $downloads;
	}

	/**
	 * Check if email was already sent for this status to avoid duplicates.
	 *
	 * @since 2.1.0
	 * @param int    $order_id Order ID.
	 * @param string $status   Status name.
	 * @return bool True if email was recently sent, false otherwise.
	 */
	private static function email_already_sent_for_status( $order_id, $status ) {
		$transient_key = 'mandrakecrm_email_' . $order_id . '_' . $status;
		$sent = get_transient( $transient_key );

		if ( $sent ) {
			error_log( 'MandrakeCRM: Email for order #' . $order_id . ' status ' . $status . ' already sent (debounced)' );
			return true;
		}

		// Mark as sent for next 10 seconds to prevent duplicates
		set_transient( $transient_key, true, 10 );
		return false;
	}

	/**
	 * Send order email via MandrakeCRM.
	 *
	 * Core method that gathers complete order data and sends to MandrakeCRM API.
	 * Extracts customer data, billing/shipping addresses, order items, totals, and downloads.
	 *
	 * @since 2.0.0
	 * @param int    $order_id   Order ID.
	 * @param string $email_type Email type (order_processing, order_completed, etc).
	 */
	private static function send_order_email( $order_id, $email_type ) {
		// Verify token BEFORE processing
		$token = get_option( 'mandrakecrm_token', '' );
		if ( empty( $token ) ) {
			error_log( 'MandrakeCRM: No token - email not sent for order #' . $order_id );
			return;
		}

		// Get order object
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			error_log( 'MandrakeCRM: Order not found - Order ID: ' . $order_id );
			return;
		}

		error_log( 'MandrakeCRM: Preparing to send ' . $email_type . ' email for order #' . $order_id );

		// Gather order items with complete product data
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$quantity = $item->get_quantity();
			$subtotal = $item->get_subtotal();
			$unit_price = $quantity > 0 ? $subtotal / $quantity : 0;

			$items[] = array(
				'product_id'  => $item->get_product_id(),
				'name'        => $item->get_name(),
				'sku'         => $product ? $product->get_sku() : '',
				'quantity'    => $quantity,
				'unit_price'  => $unit_price,
				'total'       => $item->get_total(),
				'image'       => $product ? wp_get_attachment_url( $product->get_image_id() ) : '',
			);
		}

		// Extract shipping address (fallback to billing if not set)
		if ( ! $order->get_shipping_address_1() ) {
			$shipping_address = array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'company'    => $order->get_billing_company(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			);
		} else {
			$shipping_address = array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'company'    => $order->get_shipping_company(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			);
		}

		// Extract Brazilian Market fields (shipping) - compatible with WooCommerce Brazilian Market plugin
		$shipping_brazilian_fields = array(
			'persontype'   => get_post_meta( $order->get_id(), '_shipping_persontype', true ),
			'cpf'          => get_post_meta( $order->get_id(), '_shipping_cpf', true ),
			'cnpj'         => get_post_meta( $order->get_id(), '_shipping_cnpj', true ),
			'rg'           => get_post_meta( $order->get_id(), '_shipping_rg', true ),
			'ie'           => get_post_meta( $order->get_id(), '_shipping_ie', true ),
			'birthdate'    => get_post_meta( $order->get_id(), '_shipping_birthdate', true ),
			'gender'       => get_post_meta( $order->get_id(), '_shipping_gender', true ),
			'number'       => get_post_meta( $order->get_id(), '_shipping_number', true ),
			'neighborhood' => get_post_meta( $order->get_id(), '_shipping_neighborhood', true ),
			'cellphone'    => get_post_meta( $order->get_id(), '_shipping_cellphone', true ),
		);

		// Only include brazilian fields if at least one has data
		$has_shipping_brazilian_data = array_filter( $shipping_brazilian_fields );
		if ( ! empty( $has_shipping_brazilian_data ) ) {
			$shipping_address['brazilian_fields'] = $shipping_brazilian_fields;
		}

		// Extract billing address
		$billing_address = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'company'    => $order->get_billing_company(),
			'address_1'  => $order->get_billing_address_1(),
			'address_2'  => $order->get_billing_address_2(),
			'city'       => $order->get_billing_city(),
			'state'      => $order->get_billing_state(),
			'postcode'   => $order->get_billing_postcode(),
			'country'    => $order->get_billing_country(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
		);

		// Extract Brazilian Market fields (billing) - compatible with WooCommerce Brazilian Market plugin
		$billing_brazilian_fields = array(
			'persontype'   => get_post_meta( $order->get_id(), '_billing_persontype', true ),
			'cpf'          => get_post_meta( $order->get_id(), '_billing_cpf', true ),
			'cnpj'         => get_post_meta( $order->get_id(), '_billing_cnpj', true ),
			'rg'           => get_post_meta( $order->get_id(), '_billing_rg', true ),
			'ie'           => get_post_meta( $order->get_id(), '_billing_ie', true ),
			'birthdate'    => get_post_meta( $order->get_id(), '_billing_birthdate', true ),
			'gender'       => get_post_meta( $order->get_id(), '_billing_gender', true ),
			'number'       => get_post_meta( $order->get_id(), '_billing_number', true ),
			'neighborhood' => get_post_meta( $order->get_id(), '_billing_neighborhood', true ),
			'cellphone'    => get_post_meta( $order->get_id(), '_billing_cellphone', true ),
		);

		// Only include brazilian fields if at least one has data
		$has_billing_brazilian_data = array_filter( $billing_brazilian_fields );
		if ( ! empty( $has_billing_brazilian_data ) ) {
			$billing_address['brazilian_fields'] = $billing_brazilian_fields;
		}

		// Extract shipping method
		$shipping_lines = $order->get_shipping_methods();
		$shipping_method = array();
		foreach ( $shipping_lines as $shipping ) {
			$shipping_method = array(
				'method_id'    => $shipping->get_method_id(),
				'method_title' => $shipping->get_method_title(),
				'total'        => $shipping->get_total(),
			);
			break; // Only get first shipping method
		}

		// Prepare complete payload
		$payload = array(
			'token'              => $token,
			'email_type'         => $email_type,
			'customer_email'     => $order->get_billing_email(),
			'customer_name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer_first_name' => $order->get_billing_first_name(),
			'customer_last_name' => $order->get_billing_last_name(),
			'customer_phone'     => $order->get_billing_phone(),
			'customer_country'   => $order->get_billing_country(),
			'order_data'         => array(
				'order_id'         => $order->get_id(),
				'number'           => $order->get_order_number(),
				'date'             => $order->get_date_created()->date( 'Y-m-d' ),
				'status'           => $order->get_status(),
				'currency'         => $order->get_currency(),
				'subtotal'         => $order->get_subtotal(),
				'tax_total'        => $order->get_total_tax(),
				'shipping_total'   => $order->get_shipping_total(),
				'total'            => $order->get_total(),
				'payment_method'   => $order->get_payment_method_title(),
				'items'            => $items,
				'shipping_method'  => $shipping_method,
			),
			'billing_address'    => $billing_address,
			'shipping_address'   => $shipping_address,
		);

		// Log for debugging
		error_log( 'MandrakeCRM: Email payload ready - Order #' . $order_id . ' (' . $email_type . ')' );

		// Send to webhook.site for testing (OPTIONAL - remove in production)
		$webhook_url = apply_filters( 'mandrakecrm_webhook_debug_url', '' );
		if ( ! empty( $webhook_url ) ) {
			wp_remote_post(
				$webhook_url,
				array(
					'method'  => 'POST',
					'body'    => wp_json_encode( $payload ),
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => 5,
				)
			);
			error_log( 'MandrakeCRM: Debug webhook called' );
		}

	// Extract downloadable products from order
	$downloads = self::get_order_downloads( $order );

	if ( ! empty( $downloads ) ) {
		error_log( 'MandrakeCRM: Order #' . $order_id . ' has ' . count( $downloads ) . ' downloadable products' );
	}

	// Build email data
	$email_data = array(
		'order_id'           => $order->get_id(),
		'customer_first_name' => $order->get_billing_first_name(),
		'customer_last_name'  => $order->get_billing_last_name(),
		'customer_phone'      => $order->get_billing_phone(),
		'customer_country'    => $order->get_billing_country(),
		'order_data'          => array(
			'order_id'         => $order->get_id(),
			'number'           => $order->get_order_number(),
			'date'             => $order->get_date_created()->date( 'Y-m-d' ),
			'status'           => $order->get_status(),
			'currency'         => $order->get_currency(),
			'subtotal'         => $order->get_subtotal(),
			'tax_total'        => $order->get_total_tax(),
			'shipping_total'   => $order->get_shipping_total(),
			'total'            => $order->get_total(),
			'payment_method'   => $order->get_payment_method_title(),
			'items'            => $items,
			'shipping_method'  => $shipping_method,
		),
		'billing_address'    => $billing_address,
		'shipping_address'   => $shipping_address,
	);

	// Add downloads if present
	if ( ! empty( $downloads ) ) {
		$email_data['downloads'] = $downloads;
	}

	// Send to actual MandrakeCRM API
	MandrakeCRM_API_Client::send_email(
		$email_type,
		$order->get_billing_email(),
		trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		$email_data
	);

		error_log( 'MandrakeCRM: Email sent to API for order #' . $order_id );
	}
}
