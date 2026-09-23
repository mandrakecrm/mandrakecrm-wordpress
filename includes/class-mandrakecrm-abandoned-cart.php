<?php
/**
 * MandrakeCRM Abandoned Cart
 *
 * Handles abandoned cart tracking, capture and recovery functionality.
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      2.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abandoned Cart class.
 *
 * @since 2.1.0
 */
class MandrakeCRM_Abandoned_Cart {

	/**
	 * Table name without prefix.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const TABLE_NAME = 'mandrakecrm_abandoned_carts';

	/**
	 * Abandonment threshold in minutes.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const ABANDONMENT_MINUTES = 15;

	/**
	 * Cron interval in minutes.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const CRON_INTERVAL_MINUTES = 5;

	/**
	 * Retention days for active carts without activity.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const RETENTION_ACTIVE_DAYS = 7;

	/**
	 * Retention days for abandoned carts.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const RETENTION_ABANDONED_DAYS = 90;

	/**
	 * Retention days for recovered carts.
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const RETENTION_RECOVERED_DAYS = 30;

	/**
	 * Initialize abandoned cart hooks.
	 *
	 * @since 2.1.0
	 */
	public static function init() {
		// Always register cron schedule (needed for on_feature_toggle to work).
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );

		$token = get_option( 'mandrakecrm_token', '' );
		$enabled = get_option( 'mandrakecrm_abandoned_cart', '0' ) === '1';

		// Only manage crons and register hook if token exists AND feature is enabled.
		if ( ! empty( $token ) && $enabled ) {
			// Register crons if not already scheduled.
			if ( ! wp_next_scheduled( 'mandrakecrm_process_abandoned_carts' ) ) {
				wp_schedule_event( time(), 'mandrakecrm_five_minutes', 'mandrakecrm_process_abandoned_carts' );
			}
			if ( ! wp_next_scheduled( 'mandrakecrm_cleanup_abandoned_carts' ) ) {
				wp_schedule_event( time(), 'daily', 'mandrakecrm_cleanup_abandoned_carts' );
			}

			// Register hook that detects feature toggle (only when conditions are met).
			add_action( 'update_option_mandrakecrm_abandoned_cart', array( __CLASS__, 'on_feature_toggle' ), 10, 2 );
		} else {
			// Clear crons if conditions not met.
			wp_clear_scheduled_hook( 'mandrakecrm_process_abandoned_carts' );
			wp_clear_scheduled_hook( 'mandrakecrm_cleanup_abandoned_carts' );
		}

		// Do not load ANY abandoned cart functionality if no valid token or feature is disabled.
		if ( empty( $token ) || ! $enabled ) {
			return;
		}

		// Cart tracking hooks.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_cart_updated' ) );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_cart_updated' ) );
		add_action( 'woocommerce_cart_item_restored', array( __CLASS__, 'on_cart_updated' ) );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'on_cart_updated' ) );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'on_cart_updated' ) );

		// AJAX handlers for field capture.
		add_action( 'wp_ajax_mandrakecrm_save_abandoned_cart_field', array( __CLASS__, 'ajax_save_field' ) );
		add_action( 'wp_ajax_nopriv_mandrakecrm_save_abandoned_cart_field', array( __CLASS__, 'ajax_save_field' ) );

		// Order completed - mark as recovered.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_completed' ), 10, 3 );

		// Recovery URL handler.
		// Use template_redirect to run before template is loaded.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_restore_abandoned_cart' ), 1 );

		// Enqueue scripts on checkout (classic and blocks).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Cron for processing abandoned carts.
		add_action( 'mandrakecrm_process_abandoned_carts', array( __CLASS__, 'process_abandoned_carts' ) );

		// Cron for cleanup old records.
		add_action( 'mandrakecrm_cleanup_abandoned_carts', array( __CLASS__, 'cleanup_old_carts' ) );
	}

	/**
	 * Add custom cron interval.
	 *
	 * @since 2.1.0
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules['mandrakecrm_five_minutes'] = array(
			'interval' => self::CRON_INTERVAL_MINUTES * 60,
			'display'  => sprintf(
				/* translators: %d: number of minutes */
				__('Every %d Minutes', 'mandrakecrm' ),
				self::CRON_INTERVAL_MINUTES
			),
		);
		return $schedules;
	}

	/**
	 * Plugin activation - create table and schedule crons.
	 *
	 * @since 2.1.0
	 */
	public static function activate() {
		global $wpdb;
		self::create_table();

		// Only schedule crons if token exists AND feature is enabled.
		$token = get_option( 'mandrakecrm_token', '' );
		$enabled = get_option( 'mandrakecrm_abandoned_cart', '0' ) === '1';

		if ( ! empty( $token ) && $enabled ) {
			// Schedule abandoned cart processing (every 5 minutes).
			if ( ! wp_next_scheduled( 'mandrakecrm_process_abandoned_carts' ) ) {
				wp_schedule_event( time(), 'mandrakecrm_five_minutes', 'mandrakecrm_process_abandoned_carts' );
			}

			// Schedule cleanup (daily).
			if ( ! wp_next_scheduled( 'mandrakecrm_cleanup_abandoned_carts' ) ) {
				wp_schedule_event( time(), 'daily', 'mandrakecrm_cleanup_abandoned_carts' );
			}
		}
	}

	/**
	 * Plugin deactivation - clear crons.
	 *
	 * @since 2.1.0
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mandrakecrm_process_abandoned_carts' );
		wp_clear_scheduled_hook( 'mandrakecrm_cleanup_abandoned_carts' );
	}

	/**
	 * Handle abandoned cart feature toggle.
	 *
	 * Fired when the mandrakecrm_abandoned_cart option is updated.
	 * Registers or unregisters crons based on token + feature state.
	 *
	 * @since 2.1.3
	 * @param mixed  $old_value Old option value.
	 * @param mixed  $new_value New option value.
	 */
	public static function on_feature_toggle( $old_value, $new_value ) {
		// Get token to verify it exists.
		$token = get_option( 'mandrakecrm_token', '' );

		// Feature is enabled if new_value is '1'.
		$enabled = $new_value === '1';

		if ( ! empty( $token ) && $enabled ) {
			// Feature is being ENABLED: Register crons if not already scheduled.
			if ( ! wp_next_scheduled( 'mandrakecrm_process_abandoned_carts' ) ) {
				wp_schedule_event( time(), 'mandrakecrm_five_minutes', 'mandrakecrm_process_abandoned_carts' );
			}
			if ( ! wp_next_scheduled( 'mandrakecrm_cleanup_abandoned_carts' ) ) {
				wp_schedule_event( time(), 'daily', 'mandrakecrm_cleanup_abandoned_carts' );
			}
		} else {
			// Feature is being DISABLED or token missing: Clear crons (no verification needed).
			wp_clear_scheduled_hook( 'mandrakecrm_process_abandoned_carts' );
			wp_clear_scheduled_hook( 'mandrakecrm_cleanup_abandoned_carts' );
		}
	}

	/**
	 * Create the abandoned carts table.
	 *
	 * @since 2.1.0
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			cart_hash VARCHAR(64) NOT NULL,
			recovery_token VARCHAR(64) NOT NULL,
			user_id BIGINT(20) UNSIGNED,
			email VARCHAR(100),
			phone VARCHAR(30),
			first_name VARCHAR(100),
			last_name VARCHAR(100),
			cart_contents LONGTEXT,
			cart_total DECIMAL(12,2),
			currency VARCHAR(3) DEFAULT 'USD',
			recovery_url VARCHAR(500),
			status ENUM('active', 'abandoned', 'recovered') DEFAULT 'active',
			last_activity_at DATETIME NOT NULL,
			abandoned_at DATETIME,
			recovered_at DATETIME,
			recovered_order_id BIGINT(20) UNSIGNED,
			synced_at DATETIME,
			created_at DATETIME NOT NULL,
			UNIQUE KEY cart_hash (cart_hash),
			UNIQUE KEY recovery_token (recovery_token),
			KEY ix_status (status),
			KEY ix_last_activity (last_activity_at),
			KEY ix_email (email)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check if current page is checkout (classic or blocks).
	 *
	 * @since 2.1.0
	 * @return bool True if checkout page.
	 */
	private static function is_checkout_page() {
		// Classic checkout detection.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		// Checkout Blocks detection - check if page contains checkout block.
		global $post;
		if ( $post && has_block( 'woocommerce/checkout', $post ) ) {
			return true;
		}

		// Fallback: check if this is the WooCommerce checkout page by ID.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$checkout_page_id = wc_get_page_id( 'checkout' );
			if ( $checkout_page_id && is_page( $checkout_page_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if store uses Checkout Blocks.
	 *
	 * @since 2.1.0
	 * @return bool True if using Checkout Blocks.
	 */
	private static function uses_checkout_blocks() {
		global $post;

		if ( ! $post ) {
			return false;
		}

		return has_block( 'woocommerce/checkout', $post );
	}

	/**
	 * Enqueue scripts on checkout page (classic and blocks).
	 *
	 * @since 2.1.0
	 */
	public static function enqueue_scripts() {
		if ( ! self::is_checkout_page() ) {
			return;
		}

		$is_blocks = self::uses_checkout_blocks();

		wp_enqueue_script(
			'mandrakecrm-abandoned-cart',
			MANDRAKECRM_PLUGIN_URL . 'assets/js/abandoned-cart.js',
			array( 'jquery' ),
			MANDRAKECRM_VERSION,
			true
		);

		wp_localize_script(
			'mandrakecrm-abandoned-cart',
			'mandrakeCRMAbandonedCart',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'mandrakecrm_abandoned_cart' ),
				'isCheckoutBlocks' => $is_blocks,
			)
		);
	}

	/**
	 * Generate unique cart hash based on session.
	 *
	 * v2.2.0: Added timestamp support for multi-cycle tracking and session persistence.
	 * Priority: recovered_hash > current_hash > generate_new_with_timestamp
	 *
	 * @since 2.1.0
	 * @return string MD5 hash.
	 */
	private static function generate_cart_hash() {
		if ( WC()->session ) {
			// Priority 1: Hash from recovered cart (cross-device recovery).
			$recovered_hash = WC()->session->get( 'mandrakecrm_abandoned_cart_hash' );
			if ( $recovered_hash ) {
				return $recovered_hash;
			}

			// Priority 2: Hash from current cycle (persisted).
			$current_hash = WC()->session->get( 'mandrakecrm_current_cart_hash' );
			if ( $current_hash ) {
				return $current_hash;
			}
		}

		// Priority 3: Generate NEW deterministic hash with cycle counter.
		// Uses cycle counter instead of time() to prevent race condition
		// when parallel requests (add-to-cart + cart fragments) generate different hashes.
		$session_key = WC()->session ? WC()->session->get_customer_id() : '';
		$site_url    = get_site_url();
		$cycle       = WC()->session ? (int) WC()->session->get( 'mandrakecrm_cart_cycle', 0 ) : 0;
		$new_hash    = md5( $session_key . ':' . $site_url . ':' . $cycle );

		// Persist for consistency during this cycle.
		if ( WC()->session ) {
			WC()->session->set( 'mandrakecrm_current_cart_hash', $new_hash );
		}

		return $new_hash;
	}

	/**
	 * Generate recovery token for cart recovery URL.
	 * Should only be called once when creating a new cart record.
	 *
	 * @since 2.1.0
	 * @param int $cart_id Cart record ID.
	 * @return string SHA256 token.
	 */
	private static function generate_recovery_token( $cart_id ) {
		$email     = self::get_customer_email() ?? '';
		$timestamp = time();

		return hash_hmac(
			'sha256',
			$cart_id . ':' . $email . ':' . $timestamp,
			wp_salt( 'auth' )
		);
	}

	/**
	 * Get customer email with priority.
	 *
	 * @since 2.1.0
	 * @return string|null Email or null.
	 */
	private static function get_customer_email() {
		// Priority 1: Logged in user.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( ! empty( $user->user_email ) ) {
				return $user->user_email;
			}
		}

		// Priority 2: Captured via AJAX.
		$cart_data = self::get_local_cart_data();
		if ( ! empty( $cart_data['billing_email'] ) ) {
			return $cart_data['billing_email'];
		}

		return null;
	}

	/**
	 * Sanitize phone number: keep only digits and leading + if present.
	 *
	 * Examples:
	 * - "(11) 98-420-2020" → "11984202020"
	 * - "+55 11 98420-2020" → "+5511984202020"
	 * - "11.98420.2020" → "11984202020"
	 *
	 * @since 2.1.0
	 * @param string $phone Raw phone number.
	 * @return string Sanitized phone number.
	 */
	private static function sanitize_phone( $phone ) {
		if ( empty( $phone ) ) {
			return '';
		}

		$phone = trim( $phone );

		// Check if starts with +
		$has_plus = ( substr( $phone, 0, 1 ) === '+' );

		// Remove all non-digit characters
		$digits = preg_replace( '/\D/', '', $phone );

		// Add back the + if it was at the beginning
		return $has_plus ? '+' . $digits : $digits;
	}

	/**
	 * Get customer phone with priority: cellphone > phone.
	 *
	 * For Brazilian Market, cellphone is preferred as it's the mobile number.
	 * Falls back to regular phone if cellphone not available.
	 *
	 * @since 2.1.0
	 * @return string|null Best available phone number (sanitized).
	 */
	private static function get_customer_phone() {
		$cellphone = null;
		$phone     = null;

		// Priority 1: Logged in user.
		if ( is_user_logged_in() ) {
			$user_id   = get_current_user_id();
			$cellphone = get_user_meta( $user_id, 'billing_cellphone', true );
			$phone     = get_user_meta( $user_id, 'billing_phone', true );
		}

		// Priority 2: Captured via AJAX.
		$cart_data = self::get_local_cart_data();
		if ( empty( $cellphone ) && ! empty( $cart_data['billing_cellphone'] ) ) {
			$cellphone = $cart_data['billing_cellphone'];
		}
		if ( empty( $phone ) && ! empty( $cart_data['billing_phone'] ) ) {
			$phone = $cart_data['billing_phone'];
		}

		// Return cellphone first (preferred), then phone as fallback - sanitized.
		$raw_phone = ! empty( $cellphone ) ? $cellphone : $phone;
		return ! empty( $raw_phone ) ? self::sanitize_phone( $raw_phone ) : null;
	}

	/**
	 * Get customer name data with priority.
	 *
	 * @since 2.1.0
	 * @return array Array with 'first_name' and 'last_name' keys.
	 */
	private static function get_customer_name() {
		$first_name = null;
		$last_name  = null;

		// Priority 1: Logged in user.
		if ( is_user_logged_in() ) {
			$user_id    = get_current_user_id();
			$first_name = get_user_meta( $user_id, 'billing_first_name', true );
			$last_name  = get_user_meta( $user_id, 'billing_last_name', true );

			// Fallback to user display name if billing name not set.
			if ( empty( $first_name ) ) {
				$user       = wp_get_current_user();
				$first_name = $user->first_name;
				$last_name  = $user->last_name;
			}
		}

		// Priority 2: Captured via AJAX.
		$cart_data = self::get_local_cart_data();
		if ( empty( $first_name ) && ! empty( $cart_data['billing_first_name'] ) ) {
			$first_name = $cart_data['billing_first_name'];
		}
		if ( empty( $last_name ) && ! empty( $cart_data['billing_last_name'] ) ) {
			$last_name = $cart_data['billing_last_name'];
		}

		return array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);
	}

	/**
	 * Get local cart data from session/transient.
	 *
	 * @since 2.1.0
	 * @return array Cart data.
	 */
	private static function get_local_cart_data() {
		$cart_hash = self::generate_cart_hash();
		$data      = get_transient( 'mandrakecrm_cart_' . $cart_hash );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save local cart data to transient.
	 *
	 * @since 2.1.0
	 * @param array $data Cart data to save.
	 */
	private static function save_local_cart_data( $data ) {
		$cart_hash     = self::generate_cart_hash();
		$existing_data = self::get_local_cart_data();
		$merged_data   = array_merge( $existing_data, $data );

		set_transient( 'mandrakecrm_cart_' . $cart_hash, $merged_data, DAY_IN_SECONDS );
	}

	/**
	 * Check if guest or logged in user.
	 *
	 * @since 2.1.0
	 * @return bool True if guest.
	 */
	private static function is_guest() {
		return ! is_user_logged_in();
	}

	/**
	 * Get user ID if logged in.
	 *
	 * @since 2.1.0
	 * @return int|null User ID or null.
	 */
	private static function get_user_id() {
		return is_user_logged_in() ? get_current_user_id() : null;
	}

	/**
	 * Handler for cart update events.
	 *
	 * @since 2.1.0
	 */
	public static function on_cart_updated() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$cart_hash  = self::generate_cart_hash();

		// Check if record exists (v2.2.0: include status for cycle detection).
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, recovery_token, status FROM $table_name WHERE cart_hash = %s",
				$cart_hash
			)
		);

		// v2.2.0: If previous cart was recovered, start NEW cycle.
		if ( $existing && 'recovered' === $existing->status ) {
			// Clear session hashes and increment cycle for new unique hash.
			if ( WC()->session ) {
				WC()->session->__unset( 'mandrakecrm_current_cart_hash' );
				WC()->session->__unset( 'mandrakecrm_abandoned_cart_hash' );
				$new_cycle = (int) WC()->session->get( 'mandrakecrm_cart_cycle', 0 ) + 1;
				WC()->session->set( 'mandrakecrm_cart_cycle', $new_cycle );
			}

			// Regenerate hash (deterministic with new cycle value).
			$cart_hash = self::generate_cart_hash();

			// Force INSERT instead of UPDATE.
			$existing = null;
		}

		// Get cart data.
		$cart_contents = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			$cart_contents[] = array(
				'product_id'   => $cart_item['product_id'],
				'name'         => $product->get_name(),
				'sku'          => $product->get_sku(),
				'quantity'     => $cart_item['quantity'],
				'price'        => (float) $product->get_price(),
				'image'        => wp_get_attachment_url( $product->get_image_id() ),
				'variation_id' => $cart_item['variation_id'] ?? 0,
				'variation'    => $cart_item['variation'] ?? array(),
			);
		}

		$now = current_time( 'mysql' );

		// Get customer data from logged in user or captured fields.
		$email     = self::get_customer_email();
		$phone     = self::get_customer_phone(); // Returns cellphone if available, else phone.
		$name_data = self::get_customer_name();

		if ( $existing ) {
			// Update existing record.
			$update_data = array(
				'user_id'          => self::get_user_id(),
				'cart_contents'    => wp_json_encode( $cart_contents ),
				'cart_total'       => WC()->cart->get_total( 'edit' ),
				'currency'         => get_woocommerce_currency(),
				'last_activity_at' => $now,
				'status'           => 'active',
			);

			// Only update customer fields if we have new data (don't overwrite with null).
			if ( ! empty( $email ) ) {
				$update_data['email'] = $email;
			}
			if ( ! empty( $phone ) ) {
				$update_data['phone'] = $phone;
			}
			if ( ! empty( $name_data['first_name'] ) ) {
				$update_data['first_name'] = $name_data['first_name'];
			}
			if ( ! empty( $name_data['last_name'] ) ) {
				$update_data['last_name'] = $name_data['last_name'];
			}

			$wpdb->update(
				$table_name,
				$update_data,
				array( 'id' => $existing->id )
			);

			// v2.2.0: Notify backend if cart was abandoned and now resumed.
			if ( 'abandoned' === $existing->status ) {
				// Prepare cart data for webhook.
				$cart_contents_for_webhook = array();
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$product = $cart_item['data'];
					$cart_contents_for_webhook[] = array(
						'product_id'   => $cart_item['product_id'],
						'name'         => $product->get_name(),
						'quantity'     => $cart_item['quantity'],
						'price'        => (float) $product->get_price(),
						'image'        => wp_get_attachment_url( $product->get_image_id() ),
						'variation_id' => $cart_item['variation_id'] ?? 0,
					);
				}

				// Convert last_activity to UTC.
				$dt = new DateTime( $now, new DateTimeZone( wp_timezone_string() ) );
				$dt->setTimezone( new DateTimeZone( 'UTC' ) );

				self::send_webhook( 'upsert', $existing->cart_hash, array(
					'recovery_token'   => $existing->recovery_token,
					'status'           => 'active',
					'resumed_at'       => gmdate( 'c' ),
					'last_activity_at' => $dt->format( 'c' ),
					'customer'         => array(
						'email'      => $email,
						'phone'      => $phone,
						'first_name' => $name_data['first_name'],
						'last_name'  => $name_data['last_name'],
						'is_guest'   => ! is_user_logged_in(),
						'user_id'    => self::get_user_id(),
					),
					'cart'             => array(
						'items'      => $cart_contents_for_webhook,
						'total'      => (float) WC()->cart->get_total( 'edit' ),
						'currency'   => get_woocommerce_currency(),
						'item_count' => WC()->cart->get_cart_contents_count(),
					),
				) );
			}
		} else {
			// Insert new record with customer data.
			// Suppress errors: parallel request may INSERT same deterministic hash,
			// causing expected duplicate key error handled below.
			$wpdb->suppress_errors( true );
			$result = $wpdb->insert(
				$table_name,
				array(
					'cart_hash'        => $cart_hash,
					'recovery_token'   => '', // Temporary, will be updated.
					'user_id'          => self::get_user_id(),
					'email'            => $email,
					'phone'            => $phone,
					'first_name'       => $name_data['first_name'],
					'last_name'        => $name_data['last_name'],
					'cart_contents'    => wp_json_encode( $cart_contents ),
					'cart_total'       => WC()->cart->get_total( 'edit' ),
					'currency'         => get_woocommerce_currency(),
					'status'           => 'active',
					'last_activity_at' => $now,
					'created_at'       => $now,
				)
			);
			$wpdb->suppress_errors( false );

			if ( false === $result ) {
				// Parallel request already inserted with same deterministic hash.
				// Both requests have identical cart data (same session), so no update needed.
				return;
			}

			$cart_id = $wpdb->insert_id;

			// Generate and update recovery token.
			$recovery_token = self::generate_recovery_token( $cart_id );
			$recovery_url   = add_query_arg( 'mandrake_recover', $recovery_token, wc_get_cart_url() );

			$wpdb->update(
				$table_name,
				array(
					'recovery_token' => $recovery_token,
					'recovery_url'   => $recovery_url,
				),
				array( 'id' => $cart_id )
			);
		}
	}

	/**
	 * AJAX handler for saving checkout field data.
	 *
	 * @since 2.1.0
	 */
	public static function ajax_save_field() {
		check_ajax_referer( 'mandrakecrm_abandoned_cart', 'nonce' );

		$data = isset( $_POST['data'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['data'] ) ) : array();

		if ( empty( $data ) ) {
			wp_send_json_error( 'No data provided' );
		}

		// Save to transient.
		self::save_local_cart_data( $data );

		// Update database record if exists.
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$cart_hash  = self::generate_cart_hash();

		$update_data = array(
			'last_activity_at' => current_time( 'mysql' ),
		);

		if ( ! empty( $data['billing_email'] ) ) {
			$update_data['email'] = sanitize_email( $data['billing_email'] );
		}
		// Phone priority: cellphone > phone (for Brazilian Market).
		// Sanitize to digits only, keeping leading + if present.
		if ( ! empty( $data['billing_cellphone'] ) ) {
			$update_data['phone'] = self::sanitize_phone( $data['billing_cellphone'] );
		} elseif ( ! empty( $data['billing_phone'] ) ) {
			$update_data['phone'] = self::sanitize_phone( $data['billing_phone'] );
		}
		if ( ! empty( $data['billing_first_name'] ) ) {
			$update_data['first_name'] = sanitize_text_field( $data['billing_first_name'] );
		}
		if ( ! empty( $data['billing_last_name'] ) ) {
			$update_data['last_name'] = sanitize_text_field( $data['billing_last_name'] );
		}

		$wpdb->update(
			$table_name,
			$update_data,
			array( 'cart_hash' => $cart_hash )
		);

		wp_send_json_success();
	}

	/**
	 * Handle order completion - mark cart as recovered.
	 *
	 * @since 2.1.0
	 * @param int      $order_id Order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order Order object.
	 */
	public static function on_order_completed( $order_id, $posted_data, $order ) {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// v2.2.2: Save recovery token to order meta if from recovery link.
		// Read directly from WC SESSION (database-backed, reliable between HTTP requests).
		$recovery_token = null;
		$cart_hash      = null;

		if ( WC()->session ) {
			// Get token directly from session (set in restore_cart when recovery link was used).
			$recovery_token = WC()->session->get( 'mandrake_recovery_token' );
			$cart_hash      = WC()->session->get( 'mandrakecrm_abandoned_cart_hash' );

			if ( $recovery_token ) {
				// Recovery link was used! Save token to order meta.
				update_post_meta( $order_id, '_mandrake_recovery_token', $recovery_token );

				// Clear token from session after saving.
				WC()->session->set( 'mandrake_recovery_token', null );
			}
		}

		// Fallback: Generate cart_hash if not from recovery.
		if ( empty( $cart_hash ) ) {
			$cart_hash = self::generate_cart_hash();
		}

		// Update local record to recovered status.
		$wpdb->update(
			$table_name,
			array(
				'status'             => 'recovered',
				'recovered_at'       => current_time( 'mysql' ),
				'recovered_order_id' => $order_id,
			),
			array( 'cart_hash' => $cart_hash )
		);

		// v2.2.0: REMOVED webhook 'recovered' - backend determines recovered/completed via woo_migrate_order()

		// Clear ALL session variables for new cycle.
		if ( WC()->session ) {
			WC()->session->__unset( 'mandrakecrm_abandoned_cart_hash' );
			WC()->session->__unset( 'mandrakecrm_current_cart_hash' );
		}
	}

	/**
	 * Process abandoned carts (cron job).
	 *
	 * @since 2.1.0
	 */
	public static function process_abandoned_carts() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Find carts with >15 min inactivity that are still active.
		// v2.2.2 FIX: Use current_time('mysql') as base to match WordPress local timezone.
		// NOTE: date() is used here only for formatting MySQL datetime string after timezone calculation.
		// Timezone handling is done by current_time('mysql'). This value is only used for SQL comparison.
		$threshold = date( 'Y-m-d H:i:s', strtotime( '-' . self::ABANDONMENT_MINUTES . ' minutes', strtotime( current_time( 'mysql' ) ) ) );

		$abandoned_carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name
				WHERE status = 'active'
				AND last_activity_at < %s
				AND (email IS NOT NULL AND email != '' OR phone IS NOT NULL AND phone != '')
				LIMIT 50",
				$threshold
			)
		);

		foreach ( $abandoned_carts as $cart ) {
			// Update status to abandoned.
			$wpdb->update(
				$table_name,
				array(
					'status'       => 'abandoned',
					'abandoned_at' => current_time( 'mysql' ),
				),
				array( 'id' => $cart->id )
			);

			// Send webhook to backend with abandoned_at timestamp.
			$payload = self::prepare_webhook_payload( $cart );
			$payload['abandoned_at'] = gmdate( 'c' );  // Current UTC as ISO 8601.
			$payload['status'] = 'abandoned';  // Explicit status.
			self::send_webhook( 'upsert', $cart->cart_hash, $payload );

			// Update synced_at.
			$wpdb->update(
				$table_name,
				array( 'synced_at' => current_time( 'mysql' ) ),
				array( 'id' => $cart->id )
			);
		}
	}

	/**
	 * Cleanup old cart records (daily cron job).
	 *
	 * Retention policy:
	 * - Active carts without activity > 7 days: deleted (never abandoned)
	 * - Abandoned carts > 90 days: deleted
	 * - Recovered carts > 30 days: deleted (already served purpose)
	 *
	 * @since 2.1.0
	 */
	public static function cleanup_old_carts() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Delete active carts with no activity for > 7 days.
		// v2.2.2 FIX: Use current_time('mysql') as base to match WordPress local timezone.
		// NOTE: date() used only for MySQL formatting. Timezone handled by current_time('mysql').
		$active_threshold = date( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_ACTIVE_DAYS . ' days', strtotime( current_time( 'mysql' ) ) ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE status = 'active' AND last_activity_at < %s",
				$active_threshold
			)
		);

		// Delete abandoned carts older than 90 days.
		// v2.2.2 FIX: Use current_time('mysql') as base to match WordPress local timezone.
		// NOTE: date() used only for MySQL formatting. Timezone handled by current_time('mysql').
		$abandoned_threshold = date( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_ABANDONED_DAYS . ' days', strtotime( current_time( 'mysql' ) ) ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE status = 'abandoned' AND abandoned_at < %s",
				$abandoned_threshold
			)
		);

		// Delete recovered carts older than 30 days.
		// v2.2.2 FIX: Use current_time('mysql') as base to match WordPress local timezone.
		// NOTE: date() used only for MySQL formatting. Timezone handled by current_time('mysql').
		$recovered_threshold = date( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_RECOVERED_DAYS . ' days', strtotime( current_time( 'mysql' ) ) ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE status = 'recovered' AND recovered_at < %s",
				$recovered_threshold
			)
		);

		// Also cleanup orphaned transients.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_mandrakecrm_cart_%'
			AND option_name NOT LIKE '_transient_timeout_%'
			AND option_id NOT IN (
				SELECT option_id FROM (
					SELECT o.option_id
					FROM {$wpdb->options} o
					JOIN {$wpdb->options} t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12))
					WHERE o.option_name LIKE '_transient_mandrakecrm_cart_%'
					AND o.option_name NOT LIKE '_transient_timeout_%'
					AND CAST(t.option_value AS UNSIGNED) > UNIX_TIMESTAMP()
				) AS valid_transients
			)"
		);
	}

	/**
	 * Prepare webhook payload from cart record.
	 *
	 * @since 2.1.0
	 * @param object $cart Cart record.
	 * @return array Payload data.
	 */
	private static function prepare_webhook_payload( $cart ) {
		$cart_contents = json_decode( $cart->cart_contents, true );
		$item_count    = 0;
		$subtotal      = 0;

		if ( is_array( $cart_contents ) ) {
			foreach ( $cart_contents as $item ) {
				$item_count += $item['quantity'] ?? 0;
				$subtotal   += ( $item['price'] ?? 0 ) * ( $item['quantity'] ?? 0 );
			}
		}

		// Convert last_activity_at from WordPress local time to UTC
		$wp_tz = wp_timezone_string();
		$dt = new DateTime( $cart->last_activity_at, new DateTimeZone( $wp_tz ) );
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		$last_activity_utc = $dt->format( 'c' );

		return array(
			'recovery_token' => $cart->recovery_token,
			'customer'       => array(
				'email'      => $cart->email,
				'phone'      => $cart->phone, // Contains cellphone if available, else phone.
				'first_name' => $cart->first_name,
				'last_name'  => $cart->last_name,
				'is_guest'   => empty( $cart->user_id ),
				'user_id'    => $cart->user_id ? (int) $cart->user_id : null,
			),
			'cart'           => array(
				'items'      => $cart_contents,
				'subtotal'   => $subtotal,
				'total'      => (float) $cart->cart_total,
				'currency'   => $cart->currency,
				'item_count' => $item_count,
			),
			'recovery_url'     => $cart->recovery_url,
			'last_activity_at' => $last_activity_utc,
		);
	}

	/**
	 * Send webhook to MandrakeCRM backend.
	 *
	 * @since 2.1.0
	 * @param string $action Action: 'upsert' or 'recovered'.
	 * @param string $cart_hash Cart hash identifier.
	 * @param array  $extra_data Additional payload data.
	 */
	private static function send_webhook( $action, $cart_hash, $extra_data = array() ) {
		$token = get_option( 'mandrakecrm_token', '' );

		if ( empty( $token ) ) {
			return;
		}

		$payload = array_merge(
			array(
				'token'     => $token,
				'action'    => $action,
				'cart_hash' => $cart_hash,
			),
			$extra_data
		);

		$response = wp_remote_post(
			MANDRAKECRM_API_BASE . '/store-abandoned-cart',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Origin'       => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'MandrakeCRM Abandoned Cart webhook error: ' . $response->get_error_message() );
		}
	}

	/**
	 * Maybe restore abandoned cart from recovery URL.
	 *
	 * @since 2.1.0
	 */
	public static function maybe_restore_abandoned_cart() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['mandrake_recover'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['mandrake_recover'] ) );

		if ( empty( $token ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE recovery_token = %s AND status != 'recovered' LIMIT 1",
				$token
			)
		);

		if ( ! $cart ) {
			return;
		}

		// Restore the abandoned cart to the current session.
		self::restore_cart( $cart );
	}

	/**
	 * Restore cart from saved data.
	 *
	 * @since 2.1.0
	 * @param object $cart Cart record.
	 */
	private static function restore_cart( $cart ) {
		// Ensure WooCommerce is loaded.
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		// v2.2.2: Store recovery token in WC SESSION (database-backed, reliable between HTTP requests).
		// This is the "witness" that restore_cart() was called = recovery link was used.
		if ( WC()->session ) {
			WC()->session->set( 'mandrake_recovery_token', $cart->recovery_token );
			WC()->session->set( 'mandrakecrm_abandoned_cart_hash', $cart->cart_hash );
		}

		// Clear current cart.
		WC()->cart->empty_cart();

		// Deserialize cart contents.
		$cart_contents = json_decode( $cart->cart_contents, true );

		if ( empty( $cart_contents ) ) {
			return;
		}

		// Add each product to cart.
		foreach ( $cart_contents as $item ) {
			$product_id   = $item['product_id'] ?? 0;
			$quantity     = $item['quantity'] ?? 1;
			$variation_id = $item['variation_id'] ?? 0;
			$variation    = $item['variation'] ?? array();

			$product = wc_get_product( $product_id );
			if ( $product && $product->is_in_stock() ) {
				WC()->cart->add_to_cart(
					$product_id,
					$quantity,
					$variation_id,
					$variation
				);
			}
		}

		// Calculate totals and persist cart.
		WC()->cart->calculate_totals();

		// Force session save to ensure cart is persisted.
		if ( WC()->session ) {
			WC()->session->save_data();
		}

		// Pre-fill customer data.
		if ( WC()->customer ) {
			if ( ! empty( $cart->email ) ) {
				WC()->customer->set_billing_email( $cart->email );
			}
			if ( ! empty( $cart->first_name ) ) {
				WC()->customer->set_billing_first_name( $cart->first_name );
			}
			if ( ! empty( $cart->last_name ) ) {
				WC()->customer->set_billing_last_name( $cart->last_name );
			}
			if ( ! empty( $cart->phone ) ) {
				WC()->customer->set_billing_phone( $cart->phone );
				// Also set cellphone if Brazilian Market plugin is active.
				if ( method_exists( WC()->customer, 'set_billing_cellphone' ) ) {
					WC()->customer->set_billing_cellphone( $cart->phone );
				}
			}

			WC()->customer->save();
		}

		// Update last activity timestamp.
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->update(
			$table_name,
			array( 'last_activity_at' => current_time( 'mysql' ) ),
			array( 'id' => $cart->id )
		);
	}

	/**
	 * Build checkout URL preserving UTM and marketing parameters.
	 *
	 * @since 2.1.0
	 * @return string Checkout URL with preserved params.
	 */
	private static function build_checkout_url_with_utm() {
		$checkout_url = wc_get_checkout_url();

		// Parameters to preserve.
		$preserve_params = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'utm_id',
			'gclid',
			'fbclid',
			'msclkid',
			'ref',
			'coupon',
		);

		$query_params = array();

		foreach ( $preserve_params as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && ! empty( $_GET[ $param ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$query_params[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}

		if ( ! empty( $query_params ) ) {
			$checkout_url = add_query_arg( $query_params, $checkout_url );
		}

		return $checkout_url;
	}
}
