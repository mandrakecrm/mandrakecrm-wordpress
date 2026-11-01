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
	 * WooCommerce customer emails (WC_Email ids) whose WooCommerce copy is turned off while MandrakeCRM
	 * sends the transactional emails (see disable_replaced_wc_email()).
	 *
	 * MandrakeCRM sends each of them on every transition that WooCommerce would. The refund email is not
	 * here on purpose: MandrakeCRM does not send partial refunds, and a store that turned its refund email
	 * off in MandrakeCRM keeps WooCommerce's, as before.
	 *
	 * Off in MandrakeCRM = WooCommerce's default email goes out (3.27): when MandrakeCRM does not send one
	 * of the order emails or the customer note (turned off in MandrakeCRM, or the API failed), the
	 * WooCommerce email that was turned off here is sent instead. See after_api_send().
	 *
	 * customer_invoice (3.27, "Order details" sent by hand from the order screen) is listed for
	 * completeness: WooCommerce sends it without checking whether it is enabled, so its copy is held back
	 * through the recipient instead (see before_resend_order_email()).
	 *
	 * @since 3.25
	 * @since 3.27 customer_invoice.
	 * @var array
	 */
	const WC_EMAILS_REPLACED = array(
		'customer_new_account',
		'customer_processing_order',
		'customer_on_hold_order',
		'customer_completed_order',
		'customer_cancelled_order',
		'customer_failed_order',
		'customer_note',
		'customer_invoice',
	);

	/**
	 * MandrakeCRM email type => WooCommerce email (WC_Email id) it stands in for.
	 *
	 * Used to send WooCommerce's default email when MandrakeCRM does not send one (3.27).
	 *
	 * @since 3.27
	 * @var array
	 */
	const WC_EMAIL_IDS = array(
		'order_processing' => 'customer_processing_order',
		'order_completed'  => 'customer_completed_order',
		'order_on_hold'    => 'customer_on_hold_order',
		'order_refunded'   => 'customer_refunded_order',
		'order_cancelled'  => 'customer_cancelled_order',
		'order_failed'     => 'customer_failed_order',
		'customer_note'    => 'customer_note',
		'order_invoice'    => 'customer_invoice',
	);

	/**
	 * API error codes that are the merchant's choice (the email is off or not set up in MandrakeCRM),
	 * not a connection problem: they do not count for the "API Connection Issue" notice.
	 *
	 * @since 3.27
	 * @var array
	 */
	const NOT_CONNECTION_FAILURES = array(
		'CONFIG_DISABLED',
		'CONFIG_NOT_FOUND',
	);

	/**
	 * How long the plugin remembers that WooCommerce wanted to send an email it turned off (seconds).
	 *
	 * Covers a background job that runs late (Action Scheduler on a quiet site).
	 *
	 * @since 3.27
	 * @var int
	 */
	const WC_WANTED_TTL = 86400;

	/**
	 * Source name of the WooCommerce log (WooCommerce > Status > Logs).
	 *
	 * @since 3.27
	 * @var string
	 */
	const LOG_SOURCE = 'mandrakecrm';

	/**
	 * True while the plugin itself sends a WooCommerce default email (fallback).
	 *
	 * @since 3.27
	 * @var bool
	 */
	private static $sending_wc_default = false;

	/**
	 * Action Scheduler hook that sends an order email in the background.
	 *
	 * @since 3.24
	 * @var string
	 */
	const ASYNC_HOOK = 'mandrakecrm_send_order_email';

	/**
	 * Action Scheduler group for MandrakeCRM background jobs.
	 *
	 * @since 3.24
	 * @var string
	 */
	const ASYNC_GROUP = 'mandrakecrm';

	/**
	 * Order email types that may be sent in the background.
	 *
	 * @since 3.24
	 * @var array
	 */
	const ASYNC_ORDER_EMAIL_TYPES = array(
		'order_processing',
		'order_completed',
		'order_on_hold',
		'order_refunded',
		'order_cancelled',
		'order_failed',
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

		// v3.24: background sender. Registered before the early return so emails already
		// queued are still handled (run_async_order_email() re-checks the setting).
		add_action( self::ASYNC_HOOK, array( __CLASS__, 'run_async_order_email' ), 10, 3 );

		// Do not load if feature disabled OR no valid token
		if ( ! $enabled || empty( $token ) ) {
			return;
		}

		// Hook into woocommerce_email to disable WooCommerce emails
		// This is called AFTER email classes are initialized
		add_action( 'woocommerce_email', array( __CLASS__, 'disable_woocommerce_emails' ), 10, 1 );

		// 3.25: removing WooCommerce's hooks is not enough on recent versions, which send some customer emails
		// through other hooks: WooCommerce 11 sends the new account email from WC_Emails::customer_new_account(),
		// the processing email also on on-hold/cancelled -> processing, and adds a customer "failed order" email
		// (on by default). Turning off WooCommerce's copy of each email MandrakeCRM sends works whatever the hook,
		// so the customer never gets two.
		foreach ( self::WC_EMAILS_REPLACED as $wc_email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $wc_email_id, array( __CLASS__, 'disable_replaced_wc_email' ), 20, 3 );
		}

		// 3.27: "Order details" (customer_invoice), sent by hand from the order screen or the REST API.
		add_action( 'woocommerce_before_resend_order_emails', array( __CLASS__, 'before_resend_order_email' ), 10, 2 );
		add_action( 'woocommerce_after_resend_order_email', array( __CLASS__, 'after_resend_order_email' ), 10, 2 );

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

		// Disable admin "New Order" notifications (multiple transitions)
		if ( isset( $email_class->emails['WC_Email_New_Order'] ) ) {
			remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		}

		// 3.27: the customer processing, on-hold, completed and note emails are no longer unhooked here.
		// WooCommerce still runs them and disable_replaced_wc_email() (WC_EMAILS_REPLACED) turns its copy
		// off, which tells the plugin that WooCommerce would have sent that email: if MandrakeCRM then does
		// not send it, WooCommerce's email goes out instead (after_api_send()).

		// Disable customer "Refunded Order" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Refunded_Order'] ) ) {
			remove_action( 'woocommerce_order_status_refunded_notification', array( $email_class->emails['WC_Email_Customer_Refunded_Order'], 'trigger' ) );
		}

		// Disable customer "New Account" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_New_Account'] ) ) {
			remove_action( 'woocommerce_created_customer_notification', array( $email_class->emails['WC_Email_Customer_New_Account'], 'trigger' ) );
		}

		// Disable customer "Reset Password" notifications
		if ( isset( $email_class->emails['WC_Email_Customer_Reset_Password'] ) ) {
			remove_action( 'woocommerce_reset_password_notification', array( $email_class->emails['WC_Email_Customer_Reset_Password'], 'trigger' ) );
		}

		// Disable stock notifications
		remove_action( 'woocommerce_low_stock_notification', array( $email_class, 'low_stock' ) );
		remove_action( 'woocommerce_no_stock_notification', array( $email_class, 'no_stock' ) );
		remove_action( 'woocommerce_product_on_backorder_notification', array( $email_class, 'backorder' ) );
	}

	/**
	 * Turn off WooCommerce's copy of an email that MandrakeCRM sends.
	 *
	 * Hooked to woocommerce_email_enabled_{id} (WC_EMAILS_REPLACED) only while MandrakeCRM sends the
	 * transactional emails.
	 *
	 * Since 3.27 it also remembers, for that order, that WooCommerce would have sent the email (its own
	 * setting is on and the order went through a transition that sends it). That keeps the fallback
	 * faithful: off in MandrakeCRM = the email WooCommerce would have sent goes out, and nothing that
	 * WooCommerce would not have sent (e.g. its cancelled email is only for processing/on-hold orders,
	 * not for unpaid orders cancelled by the stock timeout).
	 *
	 * @since 3.25
	 * @since 3.27 $object and $email; remembers that WooCommerce wanted to send it.
	 * @param bool          $enabled Whether WooCommerce would send it.
	 * @param mixed         $object  Object the email is about (the order).
	 * @param WC_Email|null $email   WooCommerce email.
	 * @return bool
	 */
	public static function disable_replaced_wc_email( $enabled, $object = null, $email = null ) {
		if ( $enabled && ! self::$sending_wc_default && is_object( $email ) && isset( $email->id )
			&& 'customer_invoice' !== $email->id && in_array( $email->id, self::WC_EMAIL_IDS, true )
			&& is_object( $object ) && is_a( $object, 'WC_Order' ) ) {
			$note = ( 'customer_note' === $email->id && isset( $email->customer_note ) ) ? (string) $email->customer_note : '';
			set_transient( self::wc_wanted_key( $email->id, $object->get_id(), $note ), 1, self::WC_WANTED_TTL );
		}

		return false;
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
			// API failure: Increment failure counter (admin notice from 5)
			self::record_api_failure();

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
			return; // Let WooCommerce send default email
		}

		// Get user by login
		$user = get_user_by( 'login', $user_login );
		if ( ! $user ) {
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
			return;
		}

		// Send via API
		$result = MandrakeCRM_API_Client::send_email_result(
			'customer_note',
			$customer_email,
			$customer_name,
			array( 'note' => $args['customer_note'] )
		);

		// 3.27: not sent by MandrakeCRM (off there, or the API failed) = WooCommerce's note email goes out.
		self::after_api_send( $result, 'customer_note', (int) $order_id, (string) $args['customer_note'] );
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
				break;
			case 'checkout-draft':
				// No email for draft status
				break;
			default:
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
			return true;
		}

		// Mark as sent for next 10 seconds to prevent duplicates
		set_transient( $transient_key, true, 10 );
		return false;
	}

	/**
	 * Send order email via MandrakeCRM.
	 *
	 * Since 3.24 the email is queued with Action Scheduler and sent in the background, so the
	 * API call (several seconds) no longer runs inside the checkout or the admin request that
	 * changed the order status. Falls back to sending right away when Action Scheduler is not
	 * available or the 'mandrakecrm_async_order_emails' filter returns false.
	 *
	 * @since 2.0.0
	 * @param int    $order_id   Order ID.
	 * @param string $email_type Email type (order_processing, order_completed, etc).
	 */
	private static function send_order_email( $order_id, $email_type ) {
		if ( self::queue_order_email( $order_id, $email_type ) ) {
			return;
		}

		$result = self::deliver_order_email( $order_id, $email_type );
		if ( null !== $result ) {
			self::after_api_send( $result, $email_type, (int) $order_id );
		}
	}

	/**
	 * Queue an order email for background sending.
	 *
	 * The order status is captured now, so the email reflects the status that triggered it even
	 * if the order moves on before the job runs (e.g. on-hold → processing a few seconds later).
	 * Only IDs and the status are stored in the job: no personal data.
	 *
	 * @since 3.24
	 * @param int    $order_id   Order ID.
	 * @param string $email_type Email type.
	 * @return bool True if queued (or already queued), false if it must be sent right away.
	 */
	private static function queue_order_email( $order_id, $email_type ) {
		if ( ! in_array( $email_type, self::ASYNC_ORDER_EMAIL_TYPES, true ) ) {
			return false;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		if ( ! apply_filters( 'mandrakecrm_async_order_emails', true, $order_id, $email_type ) ) {
			return false;
		}
		if ( empty( get_option( 'mandrakecrm_token', '' ) ) ) {
			return true; // Nothing to send; same outcome as deliver_order_email() without a token.
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return true;
		}

		$args = array(
			'order_id'   => (int) $order->get_id(),
			'email_type' => (string) $email_type,
			'status'     => (string) $order->get_status(),
		);

		// The same email can be triggered twice in one request (the *_notification hook and
		// handle_status_change()). Queue it once.
		if ( as_has_scheduled_action( self::ASYNC_HOOK, $args, self::ASYNC_GROUP ) ) {
			return true;
		}

		$action_id = as_enqueue_async_action( self::ASYNC_HOOK, $args, self::ASYNC_GROUP );

		return ! empty( $action_id );
	}

	/**
	 * Action Scheduler callback: send a queued order email.
	 *
	 * @since 3.24
	 * @param int    $order_id   Order ID.
	 * @param string $email_type Email type.
	 * @param string $status     Order status when the email was triggered.
	 */
	public static function run_async_order_email( $order_id, $email_type = '', $status = '' ) {
		if ( get_option( 'mandrakecrm_transactional_emails', '0' ) !== '1' ) {
			return;
		}
		if ( ! in_array( $email_type, self::ASYNC_ORDER_EMAIL_TYPES, true ) ) {
			return;
		}

		$result = self::deliver_order_email( (int) $order_id, (string) $email_type, (string) $status );
		if ( null !== $result ) {
			self::after_api_send( $result, (string) $email_type, (int) $order_id );
		}
	}

	/**
	 * Build the order email payload and send it to the MandrakeCRM API.
	 *
	 * Gathers customer data, billing/shipping addresses, order items, totals, and downloads.
	 *
	 * @since 3.24 (was the body of send_order_email() since 2.0.0)
	 * @since 3.27 Returns the API result.
	 * @param int    $order_id        Order ID.
	 * @param string $email_type      Email type (order_processing, order_completed, order_invoice, etc).
	 * @param string $status_snapshot Order status when the email was triggered ('' = current).
	 * @return array|null Result of MandrakeCRM_API_Client::send_email_result(), or null when nothing was
	 *                    sent (no token or no order).
	 */
	private static function deliver_order_email( $order_id, $email_type, $status_snapshot = '' ) {
		// Verify token BEFORE processing
		$token = get_option( 'mandrakecrm_token', '' );
		if ( empty( $token ) ) {
			return null;
		}

		// Get order object
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

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
			'persontype'   => $order->get_meta( '_shipping_persontype' ),
			'cpf'          => $order->get_meta( '_shipping_cpf' ),
			'cnpj'         => $order->get_meta( '_shipping_cnpj' ),
			'rg'           => $order->get_meta( '_shipping_rg' ),
			'ie'           => $order->get_meta( '_shipping_ie' ),
			'birthdate'    => $order->get_meta( '_shipping_birthdate' ),
			'gender'       => $order->get_meta( '_shipping_gender' ),
			'number'       => $order->get_meta( '_shipping_number' ),
			'neighborhood' => $order->get_meta( '_shipping_neighborhood' ),
			'cellphone'    => $order->get_meta( '_shipping_cellphone' ),
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
			'persontype'   => $order->get_meta( '_billing_persontype' ),
			'cpf'          => $order->get_meta( '_billing_cpf' ),
			'cnpj'         => $order->get_meta( '_billing_cnpj' ),
			'rg'           => $order->get_meta( '_billing_rg' ),
			'ie'           => $order->get_meta( '_billing_ie' ),
			'birthdate'    => $order->get_meta( '_billing_birthdate' ),
			'gender'       => $order->get_meta( '_billing_gender' ),
			'number'       => $order->get_meta( '_billing_number' ),
			'neighborhood' => $order->get_meta( '_billing_neighborhood' ),
			'cellphone'    => $order->get_meta( '_billing_cellphone' ),
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

		// v3.24: status that triggered the email. A queued email can run after the order has
		// moved on (e.g. on-hold → processing), and must still describe the status it was sent for.
		$status = '' !== $status_snapshot ? $status_snapshot : $order->get_status();

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
				'status'           => $status,
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
		}

	// Extract downloadable products from order.
	// v3.24: a queued email for an earlier status (e.g. on-hold) must not include download links
	// the customer did not have at that point; WooCommerce only grants them on processing/completed.
	$downloads = ( $status === $order->get_status() || in_array( $status, array( 'processing', 'completed' ), true ) )
		? self::get_order_downloads( $order )
		: array();

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
			'status'           => $status,
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
	return MandrakeCRM_API_Client::send_email_result(
		$email_type,
		$order->get_billing_email(),
		trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		$email_data
	);
	}

	/**
	 * "Order details" (customer_invoice) about to be sent by hand: send it through MandrakeCRM.
	 *
	 * Hooked to woocommerce_before_resend_order_emails, which WooCommerce fires right before
	 * WC()->mailer()->customer_invoice( $order ) from the order screen ("Send order details to
	 * customer", also for orders pending payment) and from the REST API
	 * (POST /wc/v3/orders/{id}/actions/send_order_details), and closes with
	 * woocommerce_after_resend_order_email.
	 *
	 * WooCommerce sends this email without checking whether it is enabled, so:
	 * - MandrakeCRM took it (sent, or a duplicate it did not send again): WooCommerce's copy is held back
	 *   by emptying its recipient until woocommerce_after_resend_order_email.
	 * - MandrakeCRM did not send it (off in MandrakeCRM, or the API failed): nothing is held back and
	 *   WooCommerce sends its own email right after this hook. Off in MandrakeCRM = WooCommerce's default
	 *   email goes out. The plugin does not call trigger() itself here: that would send it twice.
	 *
	 * The API call runs in the request (not in the background): the plugin needs the answer before
	 * WooCommerce sends its own copy. It is a manual action in the admin.
	 *
	 * @since 3.27
	 * @param WC_Order|int $order         Order.
	 * @param string       $email_to_send WooCommerce email being resent ('customer_invoice', 'new_order').
	 */
	public static function before_resend_order_email( $order, $email_to_send ) {
		if ( 'customer_invoice' !== $email_to_send ) {
			return;
		}
		if ( get_option( 'mandrakecrm_transactional_emails', '0' ) !== '1' ) {
			return;
		}

		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : (int) $order;
		if ( $order_id <= 0 ) {
			return;
		}

		$result = self::deliver_order_email( $order_id, 'order_invoice' );

		if ( null !== $result && $result['success'] ) {
			add_filter( 'woocommerce_email_recipient_customer_invoice', array( __CLASS__, 'hold_back_wc_email_recipient' ), 999 );
			return;
		}

		if ( null !== $result ) {
			self::count_failure( $result );
			self::log(
				'info',
				sprintf( 'Order details for order %d not sent by MandrakeCRM (%s): WooCommerce sends its own email.', $order_id, $result['code'] )
			);
		}

		// WooCommerce's own copy goes out, even on a version that checks whether it is enabled.
		add_filter( 'woocommerce_email_enabled_customer_invoice', array( __CLASS__, 'force_wc_email_enabled' ), 30 );
	}

	/**
	 * "Order details" sent: undo what before_resend_order_email() set for that one email.
	 *
	 * @since 3.27
	 * @param WC_Order|int $order         Order.
	 * @param string       $email_to_send WooCommerce email that was resent.
	 */
	public static function after_resend_order_email( $order, $email_to_send ) {
		if ( 'customer_invoice' !== $email_to_send ) {
			return;
		}

		remove_filter( 'woocommerce_email_recipient_customer_invoice', array( __CLASS__, 'hold_back_wc_email_recipient' ), 999 );
		remove_filter( 'woocommerce_email_enabled_customer_invoice', array( __CLASS__, 'force_wc_email_enabled' ), 30 );
	}

	/**
	 * Empty recipient: WooCommerce skips its copy of an email MandrakeCRM already sent.
	 *
	 * @since 3.27
	 * @param string $recipient Recipient.
	 * @return string
	 */
	public static function hold_back_wc_email_recipient( $recipient ) {
		return '';
	}

	/**
	 * Turn a WooCommerce email back on for the fallback (priority 30, after disable_replaced_wc_email()).
	 *
	 * @since 3.27
	 * @param bool $enabled Whether WooCommerce would send it.
	 * @return bool
	 */
	public static function force_wc_email_enabled( $enabled ) {
		return true;
	}

	/**
	 * After MandrakeCRM answered for an order email or a customer note: count the failure and, when
	 * MandrakeCRM did not send it, send WooCommerce's default email.
	 *
	 * Off in MandrakeCRM = WooCommerce's default email goes out. With "Transactional Emails" on, the
	 * plugin turns WooCommerce's copy off; if MandrakeCRM then does not send the email (the store turned
	 * it off in MandrakeCRM, CONFIG_DISABLED / CONFIG_NOT_FOUND, or any other API error or a timeout), the
	 * customer would get nothing. So WooCommerce's email is sent instead, but only when WooCommerce would
	 * have sent it (see disable_replaced_wc_email()). A duplicate MandrakeCRM did not send again
	 * (success with suppressed) is a success: nothing else goes out.
	 *
	 * @since 3.27
	 * @param array  $result     Result of MandrakeCRM_API_Client::send_email_result().
	 * @param string $email_type MandrakeCRM email type (WC_EMAIL_IDS keys).
	 * @param int    $order_id   Order ID.
	 * @param string $note       Customer note text (customer_note only).
	 */
	private static function after_api_send( array $result, $email_type, $order_id, $note = '' ) {
		$wc_email_id = isset( self::WC_EMAIL_IDS[ $email_type ] ) ? self::WC_EMAIL_IDS[ $email_type ] : '';

		// Whether WooCommerce wanted to send its copy of this email for this order. Read once.
		$woo_wanted = false;
		if ( '' !== $wc_email_id && $order_id > 0 ) {
			$key        = self::wc_wanted_key( $wc_email_id, $order_id, 'customer_note' === $wc_email_id ? $note : '' );
			$woo_wanted = (bool) get_transient( $key );
			if ( $woo_wanted ) {
				delete_transient( $key );
			}
		}

		if ( ! empty( $result['success'] ) ) {
			return;
		}

		self::count_failure( $result );

		if ( '' === $wc_email_id || ! in_array( $wc_email_id, self::WC_EMAILS_REPLACED, true ) ) {
			// e.g. the refund email: WooCommerce's copy is not turned off, so it already went out.
			self::log(
				'info',
				sprintf( '%s for order %d not sent by MandrakeCRM (%s); WooCommerce sends its own copy of this email.', $email_type, $order_id, $result['code'] )
			);
			return;
		}

		if ( ! $woo_wanted ) {
			self::log(
				'info',
				sprintf( '%s for order %d not sent by MandrakeCRM (%s); WooCommerce would not have sent %s either.', $email_type, $order_id, $result['code'], $wc_email_id )
			);
			return;
		}

		self::log(
			'info',
			sprintf( '%s for order %d not sent by MandrakeCRM (%s); sending WooCommerce\'s %s instead.', $email_type, $order_id, $result['code'], $wc_email_id )
		);
		self::send_wc_default_email( $wc_email_id, $order_id, $note );
	}

	/**
	 * Send one of WooCommerce's default emails the plugin turned off (fallback).
	 *
	 * Takes the email from WC()->mailer()->get_emails(), turns it back on for this send only
	 * (woocommerce_email_enabled_{id} at priority 30, after disable_replaced_wc_email() at 20) and calls
	 * its trigger() with WooCommerce's own arguments. An email this WooCommerce version does not have
	 * (e.g. customer_cancelled_order or customer_failed_order on older versions) is logged and skipped.
	 *
	 * @since 3.27
	 * @param string $wc_email_id WC_Email id.
	 * @param int    $order_id    Order ID.
	 * @param string $note        Customer note text (customer_note only).
	 * @return bool Whether trigger() was called.
	 */
	private static function send_wc_default_email( $wc_email_id, $order_id, $note = '' ) {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! method_exists( WC(), 'mailer' ) ) {
			return false;
		}

		$wc_email = null;
		foreach ( (array) WC()->mailer()->get_emails() as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->id ) && $wc_email_id === $candidate->id ) {
				$wc_email = $candidate;
				break;
			}
		}

		if ( ! $wc_email || ! method_exists( $wc_email, 'trigger' ) ) {
			self::log( 'warning', sprintf( 'WooCommerce email %s not available in this WooCommerce version; order %d got no email.', $wc_email_id, $order_id ) );
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Like WooCommerce before sending order emails by hand: payment instructions (e.g. bank
		// transfer) are added to the email by the gateways, which may not be loaded in a background job.
		if ( method_exists( WC(), 'payment_gateways' ) ) {
			WC()->payment_gateways();
		}
		if ( method_exists( WC(), 'shipping' ) ) {
			WC()->shipping();
		}

		self::$sending_wc_default = true;
		add_filter( 'woocommerce_email_enabled_' . $wc_email_id, array( __CLASS__, 'force_wc_email_enabled' ), 30 );

		try {
			if ( 'customer_note' === $wc_email_id ) {
				// WC_Email_Customer_Note::trigger( $args ).
				$wc_email->trigger(
					array(
						'order_id'      => $order_id,
						'customer_note' => $note,
					)
				);
			} else {
				// WC_Email_Customer_*_Order::trigger( $order_id, $order ).
				$wc_email->trigger( $order_id, $order );
			}
		} catch ( Throwable $e ) {
			self::log( 'error', sprintf( 'WooCommerce email %s for order %d failed: %s', $wc_email_id, $order_id, $e->getMessage() ) );
		} finally {
			remove_filter( 'woocommerce_email_enabled_' . $wc_email_id, array( __CLASS__, 'force_wc_email_enabled' ), 30 );
			self::$sending_wc_default = false;
		}

		return true;
	}

	/**
	 * Transient key: WooCommerce wanted to send this email for this order.
	 *
	 * @since 3.27
	 * @param string $wc_email_id WC_Email id.
	 * @param int    $order_id    Order ID.
	 * @param string $note        Customer note text (several notes can be added to one order).
	 * @return string
	 */
	private static function wc_wanted_key( $wc_email_id, $order_id, $note = '' ) {
		return 'mandrakecrm_wcw_' . md5( $wc_email_id . '|' . (int) $order_id . '|' . $note );
	}

	/**
	 * Count a failed send for the "MandrakeCRM API Connection Issue" notice.
	 *
	 * The store's own choice (email off or not set up in MandrakeCRM) is not a connection problem.
	 *
	 * @since 3.27
	 * @param array $result Result of MandrakeCRM_API_Client::send_email_result().
	 */
	private static function count_failure( array $result ) {
		if ( ! empty( $result['success'] ) || in_array( $result['code'], self::NOT_CONNECTION_FAILURES, true ) ) {
			return;
		}

		self::record_api_failure();
	}

	/**
	 * Add one to the failure counter; from 5 failures in a week the admin notice shows.
	 *
	 * @since 3.27 (was inline in handle_password_reset() since 2.0.0)
	 */
	private static function record_api_failure() {
		$failure_count = get_transient( 'mandrakecrm_email_failures' );
		$failure_count = $failure_count ? (int) $failure_count + 1 : 1;
		set_transient( 'mandrakecrm_email_failures', $failure_count, WEEK_IN_SECONDS );

		// Show admin notice if threshold exceeded
		if ( $failure_count >= 5 ) {
			set_transient( 'mandrakecrm_show_api_failure_notice', true, WEEK_IN_SECONDS );
		}
	}

	/**
	 * Write to the WooCommerce log (source "mandrakecrm"). Only IDs and codes: no personal data.
	 *
	 * @since 3.27
	 * @param string $level   Log level (info, warning, error).
	 * @param string $message Message.
	 */
	private static function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
