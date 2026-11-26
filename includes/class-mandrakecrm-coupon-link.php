<?php
/**
 * MandrakeCRM Coupon Link
 *
 * Applies the coupon of a MandrakeCRM link: after the customer activates a coupon, MandrakeCRM's customer portal and the
 * «coupon activated» email open the store with ?mandrake_coupon=<code> (and the campaign parameters).
 *
 * - Only MandrakeCRM's formats (portal XXX-XXX, cashback CB-XXXXXXXX, assistant MRL…), compared in upper case: any other
 *   value is ignored, so the plugin never becomes a generic "apply any coupon from a URL" tool.
 * - WooCommerce always decides whether the coupon is valid (WC()->cart->apply_coupon()) and shows its own notice.
 * - A coupon that cannot be applied yet waits in the WooCommerce session (up to 7 days, while the session lasts) and is
 *   applied as soon as it can: with an empty cart, when the first product is added; for a guest and a coupon restricted
 *   to an email (WooCommerce rejects those until it knows the customer's email), when the email is entered at checkout.
 *   When WooCommerce rejects it for the email (another one, a typo) or for the cart (minimum spend, products), its notice
 *   shows and the coupon keeps waiting: it is tried again only when the email changes or, for the cart, when the products,
 *   quantities, subtotal or applied coupons change (never for shipping, taxes or fees, which change with the address, the
 *   shipping method or the payment method). A coupon that can never be used (it does not exist, it expired, it was used
 *   up) shows WooCommerce's message and never waits.
 * - A waiting coupon never takes away a coupon the customer applied: while the cart has another coupon and one of them is
 *   for individual use, it keeps waiting in silence (an earlier rejection's reason shows again only while it holds).
 * - Only a guest's checkout email is taken from the checkout form; a logged-in customer is checked against the emails of
 *   the account.
 * - WooCommerce's messages about the coupon are kept in the session as notices, never as errors: an error notice left in
 *   the session makes the Store API refuse the cart (the block checkout cannot place the order). Only the notices of
 *   WooCommerce's apply_coupon() are the coupon's; what other code adds while the totals are recalculated around it is
 *   left out (the request's own recalculation adds it again).
 * - The classic checkout refresh (update_order_review) never leaves a notice of the coupon in the session: before 11.x
 *   WooCommerce treats any notice of a refresh as a failure (it scrolls up to it and marks every empty required field). The
 *   message travels as a fragment of the refresh and a small script shows it in a line of its own below the email field
 *   (role="status"), without moving the page; the discount shows in the order review. The refresh also takes out the
 *   coupon's notices another request left (an order bump added in the background, for example), and every refresh says
 *   where the coupon stands, so the line never contradicts the order review when WooCommerce aborts a refresh.
 * - A page that shows a notice of the link (with the customer's code) is never stored by a page cache (DONOTCACHEPAGE and
 *   no-cache headers). The block checkout shows the reason when the waiting coupon is rejected (Store API cart data).
 * - Link previews and crawlers change nothing. After handling the link the visitor goes to the same address without the
 *   parameter (and without mandrake_recover, already used in this request): a reload does not apply it again and the
 *   code stays out of the history and of referrers.
 * - Pages served by a full-page cache never reach PHP: a small script sends the code to the same handler through the
 *   Store API (cart/extensions) and removes the parameter from the address.
 * - Nothing is sent to MandrakeCRM: the order carries the coupon (coupon_lines) and the campaign parameters as before.
 *
 * Filters: mandrakecrm_apply_coupon_from_link (turn it off) and mandrakecrm_coupon_link_is_preview_request.
 *
 * @since 3.34 (reviews of 15/11/2026: R2 page cache, R4 the waiting coupon survives a rejection; N1 the retry ignores shipping,
 *        taxes and fees, N4 only a guest's checkout email, N5 notices instead of errors, N6 never over the customer's coupon;
 *        review of 16/11/2026: M1 the classic checkout refresh shows the message in its own line, M3, M4, M7; review of the
 *        Azure fixes: A1-A4; lab of 16/11/2026 with WooCommerce 10.9.1: CP27n and CP27k with a block theme)
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      3.34
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-mandrakecrm-i18n.php';
require_once __DIR__ . '/class-mandrakecrm-agent-cart.php';

/**
 * Coupon Link class.
 *
 * @since 3.34
 */
class MandrakeCRM_Coupon_Link {

	/**
	 * Query parameter of the link.
	 *
	 * @since 3.34
	 * @var string
	 */
	const QUERY_VAR = 'mandrake_coupon';

	/**
	 * Query parameter of the abandoned cart link, already used when the coupon is handled (see redirect_without_link()).
	 *
	 * @since 3.34
	 * @var string
	 */
	const RECOVER_VAR = 'mandrake_recover';

	/**
	 * Longest accepted code.
	 *
	 * @since 3.34
	 * @var int
	 */
	const MAX_LENGTH = 32;

	/**
	 * WooCommerce session key of the coupon that waits: array( 'code' => string, 'at' => Unix time, 'tried' => the email
	 * and cart of the last rejection and its reason ('email'|'cart'), 'msg' => WooCommerce's message of that rejection ).
	 *
	 * @since 3.34
	 * @var string
	 */
	const SESSION_KEY = 'mandrakecrm_pending_coupon';

	/**
	 * WooCommerce session key set while notices of the link wait to be shown (the page that shows them is not cached).
	 *
	 * @since 3.34
	 * @var string
	 */
	const NOTICE_FLAG = 'mandrakecrm_coupon_notice';

	/**
	 * WooCommerce session key of the notices the coupon left in the session (list of array( 'type' => string, 'text' =>
	 * the notice as stored )): the classic checkout refresh takes exactly these out before WooCommerce prints the notices
	 * (clear_link_notices(), review A1), never any other notice.
	 *
	 * @since 3.34
	 * @var string
	 */
	const NOTICES_KEY = 'mandrakecrm_coupon_notices';

	/**
	 * WooCommerce session key of how the waiting coupon ended (array( 'code' => string, 'state' => 'success'|'error',
	 * 'text' => plain text )): applied, or it can no longer be used. The classic checkout refresh keeps showing it in the
	 * coupon's line while it is still true, also when it happened in another request (review A1/A2). Forgotten when the
	 * checkout page loads again and with a new link.
	 *
	 * @since 3.34
	 * @var string
	 */
	const RESULT_KEY = 'mandrakecrm_coupon_result';

	/**
	 * How long a coupon waits (seconds).
	 *
	 * @since 3.34
	 * @var int
	 */
	const PENDING_TTL = 604800;

	/**
	 * Namespace of the Store API callback (cart/extensions) used by the script for cached pages.
	 *
	 * @since 3.34
	 * @var string
	 */
	const STORE_API_NAMESPACE = 'mandrakecrm-coupon-link';

	/**
	 * Key of the classic checkout refresh fragment that carries the coupon's message (review M1). WooCommerce replaces each
	 * fragment key as a selector: no element ever has this class, so the fragment only reaches the script of
	 * enqueue_classic_checkout(), which reads it in updated_checkout.
	 *
	 * @since 3.34
	 * @var string
	 */
	const FRAGMENT_KEY = '.mandrakecrm-coupon-link-refresh';

	/**
	 * The coupon's line for this classic checkout refresh, for its fragment: array( 'state' => 'success'|'error'|'', 'text'
	 * => plain text, 'new' => whether this refresh tried the coupon ), or null when there is nothing to say (no coupon
	 * waiting and no result).
	 *
	 * @since 3.34
	 * @var array|null
	 */
	private static $refresh_message = null;

	/**
	 * Whether this page view started with notices of the link in the session (maybe_prevent_caching(), template_redirect 17):
	 * the page prints them at the top. Known before any output: block themes render the page, and print the notices,
	 * before wp_head and wp_enqueue_scripts.
	 *
	 * @since 3.34
	 * @var bool
	 */
	private static $page_shows_link_notices = false;

	/**
	 * Accepted formats, in upper case: customer portal, cashback and the store assistant.
	 *
	 * @since 3.34
	 * @var string[]
	 */
	const PATTERNS = array(
		'/^[A-HJ-NP-Z2-9]{3}-[A-HJ-NP-Z2-9]{3}$/',
		'/^CB-[A-Z0-9]{8}$/',
		'/^MRL[A-Z0-9]{8,}$/',
	);

	/**
	 * Initialize hooks.
	 *
	 * @since 3.34
	 */
	public static function init() {
		// After the abandoned cart and the assistant's cart links (priority 1, they empty the cart). Since 3.34 this is the only
		// coupon parameter of the plugin: the cashback coupons (CB-) come by mandrake_coupon too; apply_coupon, coupon_code and
		// cb_coupon are not read any more.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_apply_from_link' ), 15 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_apply_pending_on_page' ), 16 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_prevent_caching' ), 17 );

		// The moments a waiting coupon may become applicable: a product added (classic and Store API), the email entered
		// at checkout (classic refresh, Store API customer update).
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'maybe_apply_pending' ), 20, 0 );
		// Before anything else of the refresh (FunnelKit reads and clears the session notices at -10 when its coupon field
		// applies a coupon): the coupon's own notices left by another request never reach it (review A1).
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'clear_link_notices' ), -1000, 0 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'on_update_order_review' ), 10, 1 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'maybe_apply_pending' ), 20, 0 );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'add_refresh_fragment' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_classic_checkout' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_blocks_notice' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_cached_page_script' ), 2 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api_callback();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_callback' ) );
		}
	}

	/**
	 * Normalize a code from a link: sanitized, in WooCommerce's format (lower case) and only if it has one of
	 * MandrakeCRM's formats. Null otherwise.
	 *
	 * @since 3.34
	 * @param mixed $raw Raw value (already unslashed).
	 * @return string|null
	 */
	public static function normalize( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 256 ) {
			return null;
		}
		$code = wc_format_coupon_code( sanitize_text_field( $raw ) );
		if ( ! is_string( $code ) || '' === $code || strlen( $code ) > self::MAX_LENGTH ) {
			return null;
		}
		$upper = strtoupper( $code );
		foreach ( self::PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $upper ) ) {
				return $code;
			}
		}
		return null;
	}

	/**
	 * Whether the store applies coupons from links (filter), for one code or, with an empty code, at all.
	 *
	 * @since 3.34
	 * @param string $code Normalized code, or '' when deciding whether to print the script for cached pages.
	 * @return bool
	 */
	private static function enabled( $code ) {
		if ( ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() ) {
			return false;
		}

		/**
		 * Filters whether the coupon of a MandrakeCRM link (?mandrake_coupon=) is applied to the cart.
		 *
		 * Called with an empty code to decide whether to print the small script that handles the link on pages served
		 * by a full-page cache.
		 *
		 * @since 3.34
		 * @param bool   $apply Whether to apply it. Default true.
		 * @param string $code  Coupon code (WooCommerce format, lower case), or ''.
		 */
		return (bool) apply_filters( 'mandrakecrm_apply_coupon_from_link', true, $code );
	}

	/**
	 * Whether this request is a page view of the store (GET, not admin, AJAX, REST nor cron).
	 *
	 * @since 3.34
	 * @return bool
	 */
	private static function is_page_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return 'GET' === $method && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Whether the request comes from a link preview or a crawler (email scanners and WhatsApp previews open links too).
	 *
	 * @since 3.34
	 * @return bool
	 */
	private static function is_preview() {
		$agent   = MandrakeCRM_Agent_Cart::user_agent();
		$preview = MandrakeCRM_Agent_Cart::is_preview_agent( $agent );

		/**
		 * Filters whether a coupon link request is a link preview or a crawler (nothing changes for them).
		 *
		 * @since 3.34
		 * @param bool   $preview Whether the request looks like a preview or a crawler.
		 * @param string $agent   User agent.
		 */
		return (bool) apply_filters( 'mandrakecrm_coupon_link_is_preview_request', $preview, $agent );
	}

	/**
	 * Whether WooCommerce's cart and session are available.
	 *
	 * @since 3.34
	 * @return bool
	 */
	private static function cart_ready() {
		return function_exists( 'WC' ) && WC() && WC()->cart && WC()->session;
	}

	/**
	 * Handle ?mandrake_coupon= on a page view, then reload the same address without it.
	 *
	 * @since 3.34
	 */
	public static function maybe_apply_from_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only link; the code is checked against MandrakeCRM's formats and WooCommerce validates the coupon.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! self::is_page_request() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized and checked in normalize().
		$code = self::normalize( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		if ( null === $code || ! self::enabled( $code ) || self::is_preview() || ! self::cart_ready() ) {
			return;
		}

		self::handle( $code, 'all' );
		self::redirect_without_link();
	}

	/**
	 * Apply a code now or keep it waiting.
	 *
	 * @since 3.34
	 * @param string $code    Normalized code.
	 * @param string $notices Which notices of the attempt stay: 'all', 'errors', 'inline' or 'none' (see apply_now()).
	 * @return string 'applied', 'pending' or 'tried' (WooCommerce decided, with its notice).
	 */
	private static function handle( $code, $notices ) {
		MandrakeCRM_Agent_Cart::ensure_session();

		// A newer link replaces a coupon that was waiting (and how an older one ended).
		self::forget_pending();
		self::forget_result();

		if ( WC()->cart->has_discount( $code ) ) {
			return 'applied';
		}

		$coupon = new WC_Coupon( $code );
		if ( null !== self::fatal_error( $coupon ) ) {
			// It does not exist, it expired or it was used up: WooCommerce says why and it never waits.
			self::apply_now( $code, $notices );
			return 'tried';
		}
		if ( WC()->cart->is_empty() ) {
			self::keep_pending( $code );
			self::notice( self::waiting_text( 'cart', $code ) );
			return 'pending';
		}
		if ( self::waits_for_email( $coupon ) ) {
			self::keep_pending( $code );
			self::notice( self::waiting_text( 'email', $code ) );
			return 'pending';
		}

		return self::try_apply( $code, $coupon, $notices );
	}

	/**
	 * Apply now; when WooCommerce rejects it (another email, minimum spend, products), keep it waiting with the reason.
	 *
	 * @since 3.34
	 * @param string    $code    Code.
	 * @param WC_Coupon $coupon  Coupon.
	 * @param string    $notices Which notices of the attempt stay: 'all', 'errors', 'inline' or 'none' (see apply_now()).
	 * @return string 'applied' or 'waiting'.
	 */
	private static function try_apply( $code, $coupon, $notices ) {
		$result = self::apply_now( $code, $notices );
		if ( $result['applied'] ) {
			self::forget_pending();
			self::remember_result( $code, 'success', $result['success'] );
			return 'applied';
		}
		self::keep_pending(
			$code,
			array(
				'tried' => self::state( null !== self::blocking_error( $coupon ) ? 'email' : 'cart' ),
				'msg'   => $result['message'],
			)
		);
		return 'waiting';
	}

	/**
	 * What a retry compares: the customer's emails, the cart and the reason of the last rejection.
	 *
	 * @since 3.34
	 * @param string $why 'email' or 'cart'.
	 * @return array
	 */
	private static function state( $why ) {
		return array(
			'email' => self::email_signature(),
			'cart'  => self::cart_key(),
			'why'   => $why,
		);
	}

	/**
	 * What WooCommerce's cart checks of a coupon look at, as one string: the products and their quantities, the subtotal as
	 * WooCommerce checks the minimum and maximum spend (the displayed subtotal: with taxes when prices are shown with them,
	 * review M4) and the coupons applied (individual use). Never the cart hash: it includes the total, with shipping, taxes
	 * and fees, which change with the address, the shipping method or the payment method (and the classic checkout refresh
	 * runs before WooCommerce takes the new address), so a rejected coupon would be tried again, with its message, on every
	 * change of the checkout.
	 *
	 * @since 3.34
	 * @return string
	 */
	private static function cart_key() {
		$cart  = WC()->cart;
		$items = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$items[ (string) $key ] = isset( $item['quantity'] ) ? (string) $item['quantity'] : '0';
		}
		ksort( $items );
		$coupons = array_map( 'strval', array_values( $cart->get_applied_coupons() ) );
		sort( $coupons );
		return md5( (string) wp_json_encode( array( $items, wc_format_decimal( $cart->get_displayed_subtotal(), wc_get_price_decimals() ), $coupons ) ) );
	}

	/**
	 * The emails WooCommerce checks a coupon against (account and billing), as one string.
	 *
	 * @since 3.34
	 * @return string
	 */
	private static function email_signature() {
		$user = wp_get_current_user();
		return strtolower( ( $user ? (string) $user->user_email : '' ) . '|' . ( WC()->customer ? (string) WC()->customer->get_billing_email() : '' ) );
	}

	/**
	 * Whether a waiting coupon rejected before should be tried again: for a rejection because of the email, the email
	 * changed; because of the cart, the cart changed (cart_key()). Only what can change WooCommerce's answer.
	 *
	 * @since 3.34
	 * @param array|null $tried State of the last rejection.
	 * @return bool
	 */
	private static function changed_since( $tried ) {
		if ( ! is_array( $tried ) || ! isset( $tried['email'], $tried['cart'], $tried['why'] ) ) {
			return true;
		}
		if ( 'cart' === $tried['why'] ) {
			return self::cart_key() !== $tried['cart'];
		}
		return self::email_signature() !== $tried['email'];
	}

	/**
	 * Whether applying the waiting coupon now would take away a coupon the customer applied, or clash with it: the cart
	 * has another coupon and one of the two is for individual use (WooCommerce would remove the other one, or refuse ours).
	 *
	 * @since 3.34
	 * @param WC_Coupon $coupon The waiting coupon.
	 * @return bool
	 */
	private static function clashes_with_applied( $coupon ) {
		$others = array_diff( WC()->cart->get_applied_coupons(), array( $coupon->get_code() ) );
		if ( empty( $others ) ) {
			return false;
		}
		if ( $coupon->get_individual_use() ) {
			return true;
		}
		foreach ( $others as $other ) {
			$applied = new WC_Coupon( $other );
			if ( $applied->get_individual_use() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WooCommerce's error for a coupon that can never be used here (it does not exist, it expired, it was used up), or null.
	 *
	 * @since 3.34
	 * @param WC_Coupon $coupon Coupon.
	 * @return string|null
	 */
	private static function fatal_error( $coupon ) {
		$check = self::checker();
		return $check ? $check->blocking_error( $coupon, false ) : null;
	}

	/**
	 * The error of WooCommerce's checks that do not depend on the cart's contents, or null (see MandrakeCRM_Coupon_Check).
	 *
	 * @since 3.34
	 * @param WC_Coupon $coupon Coupon.
	 * @return string|null
	 */
	private static function blocking_error( $coupon ) {
		$check = self::checker();
		return $check ? $check->blocking_error( $coupon, self::email_known() ) : null;
	}

	/**
	 * WooCommerce's validators on the current cart (null without WC_Discounts).
	 *
	 * @since 3.34
	 * @return MandrakeCRM_Coupon_Check|null
	 */
	private static function checker() {
		if ( ! class_exists( 'MandrakeCRM_Coupon_Check' ) ) {
			if ( ! class_exists( 'WC_Discounts' ) ) {
				return null;
			}
			require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-coupon-check.php';
		}
		return new MandrakeCRM_Coupon_Check( WC()->cart );
	}

	/**
	 * Whether an email of the customer is known: the account's or the billing email of the session (what WooCommerce
	 * checks a coupon restricted to emails against).
	 *
	 * @since 3.34
	 * @return bool
	 */
	private static function email_known() {
		$user = wp_get_current_user();
		if ( $user && '' !== (string) $user->user_email ) {
			return true;
		}
		return WC()->customer && '' !== (string) WC()->customer->get_billing_email();
	}

	/**
	 * Whether the coupon is restricted to emails and no email of the customer is known yet.
	 *
	 * @since 3.34
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 */
	private static function waits_for_email( $coupon ) {
		$restrictions = $coupon->get_email_restrictions();
		return is_array( $restrictions ) && ! empty( $restrictions ) && ! self::email_known();
	}

	/**
	 * Apply with WooCommerce (it validates and adds its notice), with the totals up to date before and after.
	 *
	 * Only the notices WooCommerce's apply_coupon() adds are the coupon's (review M7). Its error stays as a notice of type
	 * 'notice', never 'error' (review N5): an error notice left in the session makes the Store API refuse the cart (409 at the
	 * block checkout) and the classic checkout refuse the order until a page prints it, and it may land on a page that does
	 * not print notices. What other code adds while the totals are recalculated here is left out: it comes from this extra
	 * recalculation, and the request's own one adds it again (WooCommerce itself adds nothing there).
	 *
	 * @since 3.34
	 * @param string $code    Code.
	 * @param string $notices Which notices of the attempt stay: 'all', 'errors' (only WooCommerce's message of a rejection),
	 *                        'inline' (none; the message goes to the classic checkout refresh fragment) or 'none'.
	 * @return array array( 'applied' => bool, 'message' => WooCommerce's error, plain text, or '' ).
	 */
	private static function apply_now( $code, $notices ) {
		$before = wc_get_notices();

		WC()->cart->calculate_totals();
		$start   = wc_get_notices();
		$applied = (bool) WC()->cart->apply_coupon( $code );
		$own     = self::added_notices( $start, wc_get_notices() );
		WC()->cart->calculate_totals();

		$message = self::last_text( $own, 'error' );
		// WooCommerce's success message goes before woocommerce_applied_coupon, where other code may add its own (review A3).
		$success = $applied ? self::first_text( $own, 'success' ) : '';

		$kept = $before;
		$left = array();
		if ( 'all' === $notices || 'errors' === $notices ) {
			foreach ( $own as $type => $list ) {
				if ( empty( $list ) || ( 'all' !== $notices && 'error' !== $type ) ) {
					continue;
				}
				$as          = 'error' === $type ? 'notice' : $type;
				$kept[ $as ] = array_merge( isset( $kept[ $as ] ) ? (array) $kept[ $as ] : array(), $list );
				$left[ $as ] = array_merge( isset( $left[ $as ] ) ? $left[ $as ] : array(), $list );
			}
			self::flag_notices();
		}
		wc_set_notices( array_filter( $kept ) );
		self::remember_notices( $left );

		if ( $applied ) {
			self::show_in_refresh( $notices, 'success', $success );
		} else {
			self::show_in_refresh( $notices, 'error', $message );
		}

		return array(
			'applied' => $applied,
			'message' => $message,
			'success' => $success,
		);
	}

	/**
	 * Remember the notices the coupon leaves in the session, as stored, so the classic checkout refresh can take exactly
	 * these out (clear_link_notices()).
	 *
	 * @since 3.34
	 * @param array $notices Notices by type, as stored by WooCommerce.
	 */
	private static function remember_notices( $notices ) {
		if ( empty( $notices ) ) {
			return;
		}
		$list = WC()->session->get( self::NOTICES_KEY );
		$list = is_array( $list ) ? $list : array();
		foreach ( $notices as $type => $items ) {
			foreach ( (array) $items as $item ) {
				$list[] = array(
					'type' => (string) $type,
					'text' => self::raw_text( $item ),
				);
			}
		}
		WC()->session->set( self::NOTICES_KEY, $list );
	}

	/**
	 * Classic checkout refresh, before anything else runs in it (review A1): take out of the session the notices the coupon
	 * left there in another request (the product of an order bump added in the background, the Store API, AJAX) and that
	 * no page printed yet. WooCommerce would print them in this refresh, and before 11.x scroll up to them and mark the
	 * empty fields. Only those notices, matched by type and text; any other notice stays. What they said is in the
	 * coupon's line (on_update_order_review()).
	 *
	 * @since 3.34
	 */
	public static function clear_link_notices() {
		if ( ! self::cart_ready() ) {
			return;
		}
		$list = WC()->session->get( self::NOTICES_KEY );
		if ( empty( $list ) || ! is_array( $list ) ) {
			return;
		}
		WC()->session->set( self::NOTICES_KEY, null );

		$notices = wc_get_notices();
		$changed = false;
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['type'], $entry['text'] ) || empty( $notices[ $entry['type'] ] ) ) {
				continue;
			}
			foreach ( (array) $notices[ $entry['type'] ] as $i => $item ) {
				if ( self::raw_text( $item ) === $entry['text'] ) {
					unset( $notices[ $entry['type'] ][ $i ] );
					$changed = true;
					break;
				}
			}
		}
		if ( $changed ) {
			foreach ( $notices as $type => $items ) {
				$notices[ $type ] = array_values( (array) $items );
			}
			wc_set_notices( array_filter( $notices ) );
		}
	}

	/**
	 * A notice as WooCommerce stores it (array( 'notice' => …, 'data' => … ), or a plain string before 3.9), as text.
	 *
	 * @since 3.34
	 * @param mixed $item Stored notice.
	 * @return string
	 */
	private static function raw_text( $item ) {
		return (string) ( is_array( $item ) && isset( $item['notice'] ) ? $item['notice'] : $item );
	}

	/**
	 * Remember how the waiting coupon ended (see RESULT_KEY).
	 *
	 * @since 3.34
	 * @param string $code  Code.
	 * @param string $state 'success' or 'error'.
	 * @param string $text  Plain text.
	 */
	private static function remember_result( $code, $state, $text ) {
		WC()->session->set(
			self::RESULT_KEY,
			array(
				'code'  => $code,
				'state' => $state,
				'text'  => $text,
			)
		);
	}

	/**
	 * Forget how the waiting coupon ended.
	 *
	 * @since 3.34
	 */
	private static function forget_result() {
		if ( null !== WC()->session->get( self::RESULT_KEY ) ) {
			WC()->session->set( self::RESULT_KEY, null );
		}
	}

	/**
	 * The notices of $after that were not in $before yet, by type (WooCommerce only appends notices).
	 *
	 * @since 3.34
	 * @param array $before Notices before (wc_get_notices()).
	 * @param array $after  Notices after.
	 * @return array
	 */
	private static function added_notices( $before, $after ) {
		$added = array();
		foreach ( (array) $after as $type => $list ) {
			$added[ $type ] = array_slice( (array) $list, isset( $before[ $type ] ) ? count( (array) $before[ $type ] ) : 0 );
		}
		return $added;
	}

	/**
	 * The last notice of a type, as plain text, or ''.
	 *
	 * @since 3.34
	 * @param array  $notices Notices by type.
	 * @param string $type    Type.
	 * @return string
	 */
	private static function last_text( $notices, $type ) {
		if ( empty( $notices[ $type ] ) ) {
			return '';
		}
		return self::plain_text( end( $notices[ $type ] ) );
	}

	/**
	 * The first notice of a type, as plain text, or ''.
	 *
	 * @since 3.34
	 * @param array  $notices Notices by type.
	 * @param string $type    Type.
	 * @return string
	 */
	private static function first_text( $notices, $type ) {
		if ( empty( $notices[ $type ] ) ) {
			return '';
		}
		return self::plain_text( reset( $notices[ $type ] ) );
	}

	/**
	 * A stored notice as plain text.
	 *
	 * @since 3.34
	 * @param mixed $item Stored notice.
	 * @return string
	 */
	private static function plain_text( $item ) {
		return trim( wp_strip_all_tags( html_entity_decode( self::raw_text( $item ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Keep the coupon's message for the fragment of this classic checkout refresh ('inline' only; see add_refresh_fragment()).
	 *
	 * @since 3.34
	 * @param string $notices Mode of the attempt (see apply_now()).
	 * @param string $state   'success', 'error' or '' (clear the line).
	 * @param string $text    Plain text, or '' (clear the line).
	 */
	private static function show_in_refresh( $notices, $state, $text ) {
		if ( 'inline' === $notices ) {
			self::$refresh_message = array(
				'state' => $state,
				'text'  => $text,
				'new'   => true,
			);
		}
	}

	/**
	 * How the notices of an attempt are handled in this request: 'none' in the Store API (the discount line, or the block
	 * checkout notice of a rejection), 'inline' in the classic checkout refresh (a fragment, never a notice: before 11.x
	 * WooCommerce scrolls up to any notice of a refresh and marks the empty fields), 'errors' in other AJAX requests (only the
	 * reason of a rejection, for the next page) and 'all' on a page view.
	 *
	 * @since 3.34
	 * @return string
	 */
	private static function notices_mode() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'none';
		}
		if ( doing_action( 'woocommerce_checkout_update_order_review' ) ) {
			return 'inline';
		}
		return wp_doing_ajax() ? 'errors' : 'all';
	}

	/**
	 * Remember that notices of the link wait to be shown, so the page that shows them is not cached (maybe_prevent_caching()).
	 *
	 * @since 3.34
	 */
	private static function flag_notices() {
		WC()->session->set( self::NOTICE_FLAG, 1 );
	}

	/**
	 * A page that shows notices of the link (they carry the customer's coupon code) must never be stored by a page cache:
	 * WooCommerce only keeps cart, checkout and account out of the cache, and a guest with an empty cart has no
	 * woocommerce_items_in_cart cookie. Runs after the link and the waiting coupon (priorities 15 and 16).
	 *
	 * @since 3.34
	 */
	public static function maybe_prevent_caching() {
		if ( ! self::cart_ready() || ! WC()->session->get( self::NOTICE_FLAG ) ) {
			return;
		}
		if ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) {
			self::$page_shows_link_notices = true;
			wc_maybe_define_constant( 'DONOTCACHEPAGE', true );
			nocache_headers();
			return;
		}
		WC()->session->set( self::NOTICE_FLAG, null );
		WC()->session->set( self::NOTICES_KEY, null );
	}

	/**
	 * Add a notice about a waiting coupon.
	 *
	 * @since 3.34
	 * @param string $text Plain text (waiting_text()).
	 */
	private static function notice( $text ) {
		wc_add_notice( esc_html( $text ), 'notice' );
		$added = wc_get_notices( 'notice' );
		if ( ! empty( $added ) ) {
			self::remember_notices( array( 'notice' => array( end( $added ) ) ) );
		}
		self::flag_notices();
	}

	/**
	 * What a waiting coupon waits for, as plain text (translation bundled with the plugin while the language pack lacks it).
	 *
	 * @since 3.34
	 * @param string $what 'cart' (a product) or 'email'.
	 * @param string $code Code.
	 * @return string
	 */
	private static function waiting_text( $what, $code ) {
		if ( 'cart' === $what ) {
			/* translators: %s: coupon code */
			$translated = __( 'Your coupon %s will be applied when you add a product to your cart.', 'mandrakecrm' );
			$original   = 'Your coupon %s will be applied when you add a product to your cart.';
		} else {
			/* translators: %s: coupon code */
			$translated = __( 'Your coupon %s will be applied at checkout as soon as you enter your email.', 'mandrakecrm' );
			$original   = 'Your coupon %s will be applied at checkout as soon as you enter your email.';
		}
		return MandrakeCRM_I18n::format( MandrakeCRM_I18n::translate( $translated, $original ), $original, strtoupper( $code ) );
	}

	/**
	 * Keep a code waiting in the session.
	 *
	 * @since 3.34
	 * @param string $code  Code.
	 * @param array  $extra 'tried' and 'msg' of a rejection (see SESSION_KEY).
	 */
	private static function keep_pending( $code, $extra = array() ) {
		$pending = WC()->session->get( self::SESSION_KEY );
		$at      = ( is_array( $pending ) && isset( $pending['code'], $pending['at'] ) && $pending['code'] === $code ) ? (int) $pending['at'] : time();
		$base    = array(
			'code' => $code,
			'at'   => $at,
		);
		WC()->session->set( self::SESSION_KEY, array_merge( $base, $extra ) );
	}

	/**
	 * The waiting coupon as kept in the session (array), or null.
	 *
	 * @since 3.34
	 * @return array|null
	 */
	private static function pending() {
		$pending = WC()->session->get( self::SESSION_KEY );
		return is_array( $pending ) ? $pending : null;
	}

	/**
	 * Forget the waiting code.
	 *
	 * @since 3.34
	 */
	private static function forget_pending() {
		if ( null !== WC()->session->get( self::SESSION_KEY ) ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * The reason of the last rejection of the waiting coupon, while it still holds (reviews M3, A4 and the lab's CP27n): not
	 * while ours waits behind a coupon of the customer (they clash), and not once the cart or the email changed since the
	 * rejection (it is tried again at the next chance). The reason itself is kept: when the customer's coupon goes away and
	 * the cart is the one WooCommerce rejected, it holds again and shows again.
	 *
	 * @since 3.34
	 * @param string $code Waiting code.
	 * @return string Plain text, or ''.
	 */
	private static function visible_message( $code ) {
		$pending = self::pending();
		$msg     = isset( $pending['msg'] ) ? (string) $pending['msg'] : '';
		if ( '' === $msg || self::clashes_with_applied( new WC_Coupon( $code ) ) || self::changed_since( isset( $pending['tried'] ) ? $pending['tried'] : null ) ) {
			return '';
		}
		return $msg;
	}

	/**
	 * The waiting code, or null (an expired, malformed or turned off one is forgotten).
	 *
	 * @since 3.34
	 * @return string|null
	 */
	private static function pending_code() {
		$pending = WC()->session->get( self::SESSION_KEY );
		if ( empty( $pending ) ) {
			return null;
		}
		$code = ( is_array( $pending ) && isset( $pending['code'] ) ) ? self::normalize( $pending['code'] ) : null;
		$at   = ( is_array( $pending ) && isset( $pending['at'] ) ) ? (int) $pending['at'] : 0;
		if ( null === $code || $at < time() - self::PENDING_TTL || ! self::enabled( $code ) ) {
			self::forget_pending();
			return null;
		}
		return $code;
	}

	/**
	 * On a page view of the store (not for previews), try the waiting coupon: a logged-in customer or a known billing
	 * email may make it applicable. WooCommerce's own AJAX endpoints (wc-ajax) also run in template_redirect: they are
	 * not page views and go through their own hooks.
	 *
	 * @since 3.34
	 */
	public static function maybe_apply_pending_on_page() {
		if ( self::is_page_request() && ! self::is_preview() ) {
			self::maybe_apply_pending();
		}
	}

	/**
	 * Apply the waiting coupon when it can be: products in the cart and, for a coupon restricted to emails, an email.
	 * WooCommerce decides. Applied: it stops waiting. Rejected because of the email or the cart: WooCommerce's notice and it
	 * keeps waiting, tried again only when the email or the cart changes. A coupon that can no longer be used: it stops.
	 * Never over a coupon the customer applied (clashes_with_applied()). In the classic checkout refresh the message goes
	 * to the refresh fragment, never to the notices (notices_mode()).
	 *
	 * @since 3.34
	 */
	public static function maybe_apply_pending() {
		if ( ! self::cart_ready() ) {
			return;
		}
		$code = self::pending_code();
		if ( null === $code ) {
			return;
		}
		$notices = self::notices_mode();
		if ( WC()->cart->has_discount( $code ) ) {
			self::forget_pending();
			self::show_in_refresh( $notices, '', '' );
			return;
		}
		if ( WC()->cart->is_empty() ) {
			return;
		}
		$coupon = new WC_Coupon( $code );
		if ( null !== self::fatal_error( $coupon ) ) {
			self::forget_pending();
			$result = self::apply_now( $code, $notices );
			self::remember_result( $code, 'error', $result['message'] );
			return;
		}
		if ( self::waits_for_email( $coupon ) ) {
			return;
		}
		$pending = self::pending();
		if ( ! self::changed_since( isset( $pending['tried'] ) ? $pending['tried'] : null ) ) {
			return;
		}
		if ( self::clashes_with_applied( $coupon ) ) {
			// The customer's coupon stays; ours keeps waiting, in silence, until the cart's coupons change. The reason of an
			// earlier rejection is not shown meanwhile, but kept (visible_message()).
			self::show_in_refresh( $notices, '', '' );
			return;
		}
		self::try_apply( $code, $coupon, $notices );
	}

	/**
	 * Classic checkout refresh: with a coupon waiting, take a guest's billing email from the form (as WooCommerce does when
	 * a coupon is applied at checkout) and try the coupon. A logged-in customer is checked against the account's emails:
	 * the email typed in the form is not kept as the customer's billing email before paying (review N4). Every refresh
	 * then says where the coupon stands for its line (review A2): the result of this try, or what it waits for, or how it
	 * ended (also in another request: an order bump added in the background, review A1).
	 *
	 * @since 3.34
	 * @param string $post_data Serialized checkout form.
	 */
	public static function on_update_order_review( $post_data ) {
		if ( ! self::cart_ready() ) {
			return;
		}
		if ( null === self::pending_code() ) {
			self::line_from_result();
			return;
		}
		if ( ! is_user_logged_in() && WC()->customer ) {
			$fields = array();
			// A real form never has a NUL byte, and PHP 8.6 parse_str() throws a ValueError on one.
			parse_str( ( is_string( $post_data ) && false === strpos( $post_data, "\0" ) ) ? $post_data : '', $fields );
			$email = ( isset( $fields['billing_email'] ) && is_string( $fields['billing_email'] ) ) ? sanitize_email( $fields['billing_email'] ) : '';
			if ( '' !== $email && is_email( $email ) ) {
				WC()->customer->set_billing_email( $email );
			}
		}
		self::maybe_apply_pending();

		// Every refresh says where the coupon stands (review A2): WooCommerce aborts a refresh when another one starts, and
		// the line must never contradict the order review.
		if ( null === self::$refresh_message ) {
			$code = self::pending_code();
			if ( null === $code ) {
				self::line_from_result();
			} else {
				$line                  = self::current_line( $code );
				self::$refresh_message = array(
					'state' => $line['state'],
					'text'  => $line['text'],
					'new'   => false,
				);
			}
		}
	}

	/**
	 * The coupon's line for a coupon that waits: what it waits for (the email), or the reason of the last rejection, or
	 * nothing (it waits in silence behind the customer's coupon).
	 *
	 * @since 3.34
	 * @param string $code Waiting code.
	 * @return array array( 'state' => 'error'|'', 'text' => plain text ).
	 */
	private static function current_line( $code ) {
		$coupon  = new WC_Coupon( $code );
		$pending = self::pending();
		if ( self::clashes_with_applied( $coupon ) ) {
			return array(
				'state' => '',
				'text'  => '',
			);
		}
		if ( self::waits_for_email( $coupon ) ) {
			return array(
				'state' => '',
				'text'  => self::waiting_text( 'email', $code ),
			);
		}
		$msg = self::visible_message( $code );
		return array(
			'state' => '' === $msg ? '' : 'error',
			'text'  => $msg,
		);
	}

	/**
	 * The coupon's line when no coupon waits any more: how it ended, while that is still true (applied and still in the
	 * cart, or it can no longer be used), also when it ended in another request (review A1/A2).
	 *
	 * @since 3.34
	 */
	private static function line_from_result() {
		$result = WC()->session->get( self::RESULT_KEY );
		if ( ! is_array( $result ) || ! isset( $result['code'], $result['state'], $result['text'] ) ) {
			return;
		}
		if ( 'success' === $result['state'] && ! WC()->cart->has_discount( $result['code'] ) ) {
			self::forget_result(); // The customer removed it: the line says nothing any more.
			$result['state'] = '';
			$result['text']  = '';
		}
		self::$refresh_message = array(
			'state' => (string) $result['state'],
			'text'  => (string) $result['text'],
			'new'   => false,
		);
	}

	/**
	 * Classic checkout refresh fragment with the coupon's line (review M1): the refresh itself leaves no notice of the
	 * coupon, so WooCommerce answers it as a success (no scrolling, no fields marked) and the script of
	 * enqueue_classic_checkout() shows the line. Sent in every refresh while a coupon waits or its result holds (review
	 * A2); data-new="1" when this refresh tried the coupon (the line is read out again even with the same text).
	 *
	 * @since 3.34
	 * @param array $fragments Fragments of the refresh, by selector.
	 * @return array
	 */
	public static function add_refresh_fragment( $fragments ) {
		if ( null === self::$refresh_message || ! is_array( $fragments ) ) {
			return $fragments;
		}
		$fragments[ self::FRAGMENT_KEY ] = '<span data-state="' . esc_attr( self::$refresh_message['state'] ) . '" data-new="' . ( empty( self::$refresh_message['new'] ) ? '0' : '1' ) . '">' . esc_html( self::$refresh_message['text'] ) . '</span>';
		self::$refresh_message           = null;
		return $fragments;
	}

	/**
	 * Classic checkout with a coupon waiting (review M1): a line of its own below the email field (role="status",
	 * aria-live="polite"; at the top of the checkout form when the email field is not on the visible step) shows the
	 * coupon's line of each refresh (the fragment of add_refresh_fragment(): applied, rejected and why, or what it waits
	 * for), without moving the page or touching the fields; the text only changes when the coupon's state changes. When the
	 * page opens it already says what the coupon waits for (the email, or the reason of the last rejection), unless the
	 * page prints the link's own notice (then the line does not repeat that text either). Like the discount itself, the line
	 * shows inside the checkout form, in WooCommerce's checkout and in page builders that keep its fields (FunnelKit, for
	 * example), and it never carries WooCommerce's notice classes, so their scripts never remove it.
	 *
	 * For a guest whose coupon waits for the email (or was rejected for it, for example a typo) the order review is also
	 * refreshed when the email changes (WooCommerce refreshes it only for address fields), so the discount shows before
	 * paying. Not for a logged-in customer: the email of the form is not taken (on_update_order_review()).
	 *
	 * @since 3.34
	 */
	public static function enqueue_classic_checkout() {
		if ( ! self::cart_ready() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! wp_script_is( 'wc-checkout', 'enqueued' ) ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return;
		}
		self::forget_result(); // A new checkout page: how an earlier coupon ended is no news here.
		$code = self::pending_code();
		if ( null === $code ) {
			return;
		}
		$pending = self::pending();
		$waits   = self::waits_for_email( new WC_Coupon( $code ) );
		$line    = self::current_line( $code );
		// This page prints the link's notices at the top: the line starts empty and does not repeat that same text. Known
		// at template_redirect (a block theme has already printed them here); the session check covers a page that did not
		// go through it.
		$shown  = self::$page_shows_link_notices || ( WC()->session->get( self::NOTICE_FLAG ) && wc_notice_count() > 0 );
		$config = wp_json_encode(
			array(
				'key'   => self::FRAGMENT_KEY,
				'text'  => $shown ? '' : $line['text'],
				'state' => $shown ? '' : $line['state'],
				'quiet' => $shown ? $line['text'] : '',
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $config ) {
			return;
		}

		$script = 'jQuery(function($){var c=' . $config . ',box=null,hint=c.text?[c.text,c.state]:null;' .
			'function inPage(){return !!box&&$.contains(document.documentElement,box[0]);}' .
			'function ready(){var a=$("#billing_email_field");return !a.length||a.is(":visible");}' .
			'function place(){if(inPage()&&box.is(":visible")){return false;}' .
			'if(!box){box=$("<div class=\"mandrakecrm-coupon-link-status\" role=\"status\" aria-live=\"polite\" aria-atomic=\"true\"></div>")' .
			'.css({clear:"both",width:"100%",flexBasis:"100%",gridColumn:"1 / -1",boxSizing:"border-box",fontSize:".875em",lineHeight:"1.5"});}' .
			'var a=$("#billing_email_field").first();' .
			'if(a.length&&a.is(":visible")){box.css({paddingLeft:a.css("padding-left"),paddingRight:a.css("padding-right")});a.after(box);return true;}' .
			'var f=$("form.checkout").first();if(!f.length){return false;}box.css({paddingLeft:"",paddingRight:""});f.prepend(box);return true;}' .
			'function show(t,s,nw){t=t?String(t):"";s=s?String(s):"";if(!nw&&c.quiet&&t===c.quiet){return;}' .
			'var has=inPage()&&box.text()!=="";if(!t&&!has){return;}c.quiet="";' .
			'if(!nw&&has&&box.text()===t&&box.attr("data-state")===s){return;}var moved=place();if(!inPage()){return;}' .
			'box.attr("data-state",s).css("margin",t?"0 0 1em":"0");' .
			'if(moved||box.text()===t){box.text("");setTimeout(function(){box.text(t);},100);}else{box.text(t);}}' .
			'$(document.body).on("updated_checkout",function(e,d){var f=d&&d.fragments;' .
			'if(f&&Object.prototype.hasOwnProperty.call(f,c.key)){hint=null;var n=$($.parseHTML(String(f[c.key]))||[]);show(n.text(),n.attr("data-state"),n.attr("data-new")==="1");return;}' .
			'if(hint&&ready()){show(hint[0],hint[1],false);hint=null;}});' .
			'if(hint&&ready()){show(hint[0],hint[1],false);hint=null;}';
		if ( ! is_user_logged_in() && ( $waits || ( isset( $pending['tried']['why'] ) && 'email' === $pending['tried']['why'] ) ) ) {
			$script .= '$(document.body).on("change","input#billing_email",function(){$(document.body).trigger("update_checkout");});';
		}
		$script .= '});';

		wp_add_inline_script( 'wc-checkout', $script );
	}

	/**
	 * Block checkout with a coupon waiting: show WooCommerce's reason when it is rejected (another email, minimum spend),
	 * from the Store API cart data (register_store_api_callback()), as a notice of the checkout; it goes away when the
	 * coupon is applied. The Store API cannot carry WooCommerce notices, and an error notice left in the session would
	 * make it refuse the cart (see apply_now()).
	 *
	 * @since 3.34
	 */
	public static function enqueue_blocks_notice() {
		if ( ! self::cart_ready() || ! function_exists( 'is_checkout' ) || ! is_checkout() || null === self::pending_code() ) {
			return;
		}
		$page = (int) wc_get_page_id( 'checkout' );
		if ( $page <= 0 || ! has_block( 'woocommerce/checkout', $page ) ) {
			return;
		}
		$deps = array( 'wp-data' );
		if ( wp_script_is( 'wc-blocks-data-store', 'registered' ) ) {
			$deps[] = 'wc-blocks-data-store';
		}
		wp_register_script( 'mandrakecrm-coupon-link-blocks', false, $deps, MANDRAKECRM_VERSION, true );
		wp_enqueue_script( 'mandrakecrm-coupon-link-blocks' );
		wp_add_inline_script(
			'mandrakecrm-coupon-link-blocks',
			'(function(ns){var d=window.wp&&wp.data;if(!d||!d.subscribe){return;}var last="";d.subscribe(function(){var s=d.select("wc/store/cart");' .
			'if(!s||!s.getCartData){return;}var x=((s.getCartData()||{}).extensions||{})[ns]||{};var m=x.message||"";if(m===last){return;}last=m;' .
			'var n=d.dispatch("core/notices");if(!n){return;}if(m){n.createNotice("error",m,{id:ns,context:"wc/checkout",isDismissible:true});}' .
			'else{n.removeNotice(ns,"wc/checkout");}});})(' . wp_json_encode( self::STORE_API_NAMESPACE ) . ');'
		);
	}

	/**
	 * Store API cart data: WooCommerce's message of the last rejection of the waiting coupon, while it holds (for the block
	 * checkout; visible_message(): computed with each answer, so it goes away in the same answer that applies a coupon of the
	 * customer that clashes, and comes back when that coupon goes away). Only in Store API requests and on the cart and
	 * checkout pages (which WooCommerce keeps out of page caches): the cart data a page preloads (mini cart) must not carry
	 * the customer's code into a page a cache may store.
	 *
	 * @since 3.34
	 * @return array
	 */
	public static function store_api_cart_data() {
		$visible = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) );
		$code    = ( $visible && self::cart_ready() ) ? self::pending_code() : null;
		return array(
			'message' => null === $code ? '' : self::visible_message( $code ),
		);
	}

	/**
	 * Schema of store_api_cart_data().
	 *
	 * @since 3.34
	 * @return array
	 */
	public static function store_api_cart_schema() {
		return array(
			'message' => array(
				'description' => 'Why WooCommerce rejected the coupon of a MandrakeCRM link that waits, the last time.',
				'type'        => 'string',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Send the visitor to the same address without the coupon parameter and without mandrake_recover (that cart was
	 * restored earlier in this request; keeping it would empty the cart again on the next load, and with it the coupon).
	 *
	 * @since 3.34
	 */
	private static function redirect_without_link() {
		$target = wp_validate_redirect( remove_query_arg( array( self::QUERY_VAR, self::RECOVER_VAR ) ), home_url( '/' ) );
		$query  = wp_parse_url( $target, PHP_URL_QUERY );
		$args   = array();
		if ( is_string( $query ) ) {
			wp_parse_str( $query, $args );
		}
		if ( isset( $args[ self::QUERY_VAR ] ) ) {
			return; // Never a loop.
		}
		nocache_headers();
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Register the Store API callback the script for cached pages calls (POST wc/store/v1/cart/extensions).
	 *
	 * @since 3.34
	 */
	public static function register_store_api_callback() {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		try {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::STORE_API_NAMESPACE,
					'callback'  => array( __CLASS__, 'apply_from_store_api' ),
				)
			);
		} catch ( Exception $e ) {
			return; // Already registered.
		}
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			try {
				woocommerce_store_api_register_endpoint_data(
					array(
						'endpoint'        => 'cart',
						'namespace'       => self::STORE_API_NAMESPACE,
						'data_callback'   => array( __CLASS__, 'store_api_cart_data' ),
						'schema_callback' => array( __CLASS__, 'store_api_cart_schema' ),
						'schema_type'     => ARRAY_A,
					)
				);
			} catch ( Exception $e ) {
				return; // Already registered.
			}
		}
	}

	/**
	 * Store API callback: the same handling as the link, for a page served by a cache.
	 *
	 * @since 3.34
	 * @param array $data Request data: array( 'code' => string ).
	 */
	public static function apply_from_store_api( $data ) {
		$code = self::normalize( ( is_array( $data ) && isset( $data['code'] ) ) ? $data['code'] : null );
		if ( null === $code || ! self::enabled( $code ) || self::is_preview() || ! self::cart_ready() ) {
			return;
		}
		self::handle( $code, 'all' );
	}

	/**
	 * Script for pages served by a full-page cache (PHP did not run, so the link was not handled): with a code in a
	 * MandrakeCRM format in the address, it asks the Store API for a nonce, sends the code to apply_from_store_api() and
	 * removes the parameter from the address. Does nothing on any other page view.
	 *
	 * @since 3.34
	 */
	public static function print_cached_page_script() {
		if ( is_admin() || ! function_exists( 'WC' ) || ! self::enabled( '' ) ) {
			return;
		}

		$config = array(
			'param' => self::QUERY_VAR,
			'max'   => self::MAX_LENGTH,
			'cart'  => rest_url( 'wc/store/v1/cart' ),
			'ext'   => rest_url( 'wc/store/v1/cart/extensions' ),
			'ns'    => self::STORE_API_NAMESPACE,
		);
		$json   = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $json ) {
			return;
		}

		$script = '(function(c){try{' .
			'var q=new URLSearchParams(window.location.search),v=q.get(c.param);' .
			'if(!v||!window.fetch||!window.history||!history.replaceState){return;}' .
			'v=String(v).trim().toUpperCase();' .
			'if(v.length>c.max||!/^(?:[A-HJ-NP-Z2-9]{3}-[A-HJ-NP-Z2-9]{3}|CB-[A-Z0-9]{8}|MRL[A-Z0-9]{8,})$/.test(v)){return;}' .
			'var done=function(){q.delete(c.param);var s=q.toString();history.replaceState(history.state,"",window.location.pathname+(s?"?"+s:"")+window.location.hash);};' .
			'fetch(c.cart,{credentials:"same-origin",cache:"no-store"}).then(function(r){var n=r.headers.get("Nonce");if(!n){throw new Error("nonce");}' .
			'return fetch(c.ext,{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Content-Type":"application/json","Nonce":n},body:JSON.stringify({namespace:c.ns,data:{code:v}})});})' .
			'.then(done,function(){});' .
			'}catch(e){}})(' . $json . ');';

		wp_print_inline_script_tag( $script, array( 'id' => 'mandrakecrm-coupon-link-js' ) );
	}
}
