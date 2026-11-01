<?php
/**
 * MandrakeCRM Agent Cart
 *
 * Signed cart links: MandrakeCRM's sales assistant sends the customer a link that fills the
 * WooCommerce cart with the agreed products and opens the checkout.
 *
 * Link format: ?mandrake_cart=<payload>.<signature>
 *
 * - payload   = base64url (no padding) of a JSON object:
 *               {"v":1,"i":[[product_id,variation_id,qty],...],"c":null|"coupon","r":"ref","e":unix_expiry,"m":"replace"|"add"}
 * - m (optional, 3.30) = "replace" (default): the cart is emptied, filled with the items and the checkout opens;
 *               "add": the items are added to the current cart (nothing is removed) and the cart page opens.
 * - signature = base64url (no padding) of HMAC-SHA256( payload as sent in the URL, derived key )
 * - derived key = HMAC-SHA256( key = store token, message = "mandrake-agent-cart-v1" ), raw 32 bytes.
 *   In PHP: hash_hmac( 'sha256', 'mandrake-agent-cart-v1', $token, true ). The token is never used raw.
 *
 * Prices always come from WooCommerce, never from the link. Items that cannot be bought are skipped
 * with a notice. Requests without a valid signature change nothing.
 *
 * The order records the conversation it came from (_mandrakecrm_agent_ref) and how
 * (_mandrakecrm_agent_touch): "link" when a signed link filled the cart in this session, or, since 3.30,
 * "chat" when there was no link but the assistant's chat on the site left its reference in the
 * first-party cookie mcrm_agent_ref. The signed link always wins over the cookie.
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      3.28
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agent Cart class.
 *
 * @since 3.28
 */
class MandrakeCRM_Agent_Cart {

	/**
	 * Query parameter that carries the signed cart.
	 *
	 * @since 3.28
	 * @var string
	 */
	const QUERY_VAR = 'mandrake_cart';

	/**
	 * Label used to derive the signing key from the store token.
	 *
	 * @since 3.28
	 * @var string
	 */
	const KEY_LABEL = 'mandrake-agent-cart-v1';

	/**
	 * Payload format version.
	 *
	 * @since 3.28
	 * @var int
	 */
	const FORMAT_VERSION = 1;

	/**
	 * Longest accepted lifetime of a link, counted from now (72 hours).
	 *
	 * @since 3.28
	 * @var int
	 */
	const MAX_LIFETIME_SECONDS = 259200;

	/**
	 * Clock difference tolerated between MandrakeCRM and the store (5 minutes).
	 *
	 * @since 3.28
	 * @var int
	 */
	const CLOCK_SKEW_SECONDS = 300;

	/**
	 * Maximum number of items in a link.
	 *
	 * @since 3.28
	 * @var int
	 */
	const MAX_ITEMS = 20;

	/**
	 * Maximum quantity of one item.
	 *
	 * @since 3.28
	 * @var int
	 */
	const MAX_QTY = 99;

	/**
	 * Maximum length of the query parameter.
	 *
	 * @since 3.28
	 * @var int
	 */
	const MAX_PARAM_LENGTH = 4096;

	/**
	 * Session key with the conversation reference of the last link used.
	 *
	 * @since 3.28
	 * @var string
	 */
	const SESSION_REF = 'mandrakecrm_agent_ref';

	/**
	 * Session key with the signature of the last link used (opening it again does not rebuild the cart).
	 *
	 * @since 3.28
	 * @var string
	 */
	const SESSION_SIG = 'mandrakecrm_agent_cart_sig';

	/**
	 * Order meta with the conversation reference.
	 *
	 * @since 3.28
	 * @var string
	 */
	const ORDER_META = '_mandrakecrm_agent_ref';

	/**
	 * Order meta with how the conversation reached the order: "link" (signed cart link) or "chat" (cookie).
	 *
	 * @since 3.30
	 * @var string
	 */
	const ORDER_META_TOUCH = '_mandrakecrm_agent_touch';

	/**
	 * First-party cookie with the reference of the assistant's chat on the site (written by the widget).
	 *
	 * @since 3.30
	 * @var string
	 */
	const COOKIE_REF = 'mcrm_agent_ref';

	/**
	 * Link mode that empties the cart first (default).
	 *
	 * @since 3.30
	 * @var string
	 */
	const MODE_REPLACE = 'replace';

	/**
	 * Link mode that adds to the current cart.
	 *
	 * @since 3.30
	 * @var string
	 */
	const MODE_ADD = 'add';

	/**
	 * Initialize hooks (only when the store is connected).
	 *
	 * @since 3.28
	 */
	public static function init() {
		if ( '' === self::get_token() ) {
			return;
		}

		// Same early priority as the abandoned cart recovery link.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_build_cart' ), 1 );

		// The order records which conversation it came from (classic checkout and FunnelKit, then blocks).
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_ref_to_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'save_ref_to_block_order' ), 10, 1 );

		// Once the order is placed, the reference leaves the session.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_session_ref' ), 20, 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'clear_session_ref' ), 20, 0 );
	}

	/**
	 * Store token (the shared secret with MandrakeCRM).
	 *
	 * @since 3.28
	 * @return string
	 */
	private static function get_token() {
		$token = get_option( 'mandrakecrm_token', '' );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Signing key derived from the token: HMAC-SHA256 with key = token and message = KEY_LABEL.
	 *
	 * @since 3.28
	 * @param string $token Store token.
	 * @return string Raw 32-byte key.
	 */
	public static function derive_key( $token ) {
		return hash_hmac( 'sha256', self::KEY_LABEL, (string) $token, true );
	}

	/**
	 * Signature of a payload: base64url (no padding) of HMAC-SHA256( payload, derived key ).
	 *
	 * @since 3.28
	 * @param string $payload Payload exactly as it appears in the URL (base64url).
	 * @param string $token   Store token.
	 * @return string
	 */
	public static function sign( $payload, $token ) {
		return self::base64url_encode( hash_hmac( 'sha256', (string) $payload, self::derive_key( $token ), true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @since 3.28
	 * @param string $data Binary data.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Strict base64url decode.
	 *
	 * @since 3.28
	 * @param string $data Base64url string (with or without padding).
	 * @return string|false
	 */
	private static function base64url_decode( $data ) {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( 1 === $pad ) {
			return false;
		}
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Whether the request comes from a link preview or a crawler (they must not create carts).
	 *
	 * @since 3.28
	 * @return bool
	 */
	private static function is_preview_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return true;
		}

		$agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$preview = '' !== $agent && (bool) preg_match( '/facebookexternalhit|facebookcatalog|meta-externalagent|whatsapp|telegrambot|twitterbot|slackbot|discordbot|linkedinbot|skypeuripreview|pinterest|googlebot|google-inspectiontool|adsbot|bingbot|applebot|yandex|duckduckbot|embedly|iframely|preview|crawler|spider/i', $agent );

		/**
		 * Filters whether a signed cart link request is a link preview (nothing is changed for previews).
		 *
		 * @since 3.28
		 * @param bool   $preview Whether the request looks like a preview or a crawler.
		 * @param string $agent   User agent.
		 */
		return (bool) apply_filters( 'mandrakecrm_agent_cart_is_preview_request', $preview, $agent );
	}

	/**
	 * Read and verify the link. Returns null when there is no link or the signature is not valid
	 * (nothing happens), or an array with 'error' ('expired'|'invalid') or 'cart' (normalized payload).
	 *
	 * @since 3.28
	 * @param string $raw   Query parameter value.
	 * @param string $token Store token.
	 * @param int    $now   Current Unix time.
	 * @return array|null
	 */
	public static function parse_link( $raw, $token, $now ) {
		if ( ! is_string( $raw ) || '' === $token || strlen( $raw ) > self::MAX_PARAM_LENGTH ) {
			return null;
		}
		if ( ! preg_match( '/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/', $raw, $m ) ) {
			return null;
		}

		$payload   = $m[1];
		$signature = $m[2];
		if ( ! hash_equals( self::sign( $payload, $token ), $signature ) ) {
			return null;
		}

		// From here on the link was made by MandrakeCRM for this store.
		$json = self::base64url_decode( $payload );
		$data = false === $json ? null : json_decode( $json, true, 4 );
		if ( ! is_array( $data ) || ! isset( $data['v'], $data['e'], $data['i'] ) || self::FORMAT_VERSION !== $data['v'] || ! is_int( $data['e'] ) ) {
			return array( 'error' => 'invalid' );
		}

		$expiry = $data['e'];
		if ( $expiry < $now ) {
			return array( 'error' => 'expired' );
		}
		if ( $expiry > $now + self::MAX_LIFETIME_SECONDS + self::CLOCK_SKEW_SECONDS ) {
			return array( 'error' => 'invalid' );
		}

		$items = $data['i'];
		if ( ! is_array( $items ) || ! $items || count( $items ) > self::MAX_ITEMS || array_values( $items ) !== $items ) {
			return array( 'error' => 'invalid' );
		}
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || 3 !== count( $item ) || array_values( $item ) !== $item ) {
				return array( 'error' => 'invalid' );
			}
			list( $product_id, $variation_id, $qty ) = $item;
			if ( ! is_int( $product_id ) || ! is_int( $variation_id ) || ! is_int( $qty )
				|| $product_id < 1 || $variation_id < 0 || $qty < 1 || $qty > self::MAX_QTY ) {
				return array( 'error' => 'invalid' );
			}
			$clean[] = array( $product_id, $variation_id, $qty );
		}

		$coupon = null;
		if ( isset( $data['c'] ) ) {
			if ( ! is_string( $data['c'] ) || '' === trim( $data['c'] ) || strlen( $data['c'] ) > 100 ) {
				return array( 'error' => 'invalid' );
			}
			$coupon = $data['c'];
		}

		$ref = isset( $data['r'] ) ? $data['r'] : null;
		if ( ! is_string( $ref ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $ref ) ) {
			return array( 'error' => 'invalid' );
		}

		$mode = self::MODE_REPLACE;
		if ( array_key_exists( 'm', $data ) ) {
			if ( self::MODE_REPLACE !== $data['m'] && self::MODE_ADD !== $data['m'] ) {
				return array( 'error' => 'invalid' );
			}
			$mode = $data['m'];
		}

		return array(
			'cart' => array(
				'items'     => $clean,
				'coupon'    => $coupon,
				'ref'       => $ref,
				'expires'   => $expiry,
				'signature' => $signature,
				'mode'      => $mode,
			),
		);
	}

	/**
	 * Build the cart from a signed link and open the checkout (or, in "add" mode, add to the cart and open it).
	 *
	 * @since 3.28
	 * @since 3.30 "add" mode.
	 */
	public static function maybe_build_cart() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the HMAC signature authenticates the link.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || is_admin() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against a strict base64url pattern and the signature in parse_link().
		$raw    = wp_unslash( $_GET[ self::QUERY_VAR ] );
		$parsed = self::parse_link( $raw, self::get_token(), time() );
		if ( null === $parsed || self::is_preview_request() ) {
			return;
		}

		self::ensure_session();

		if ( isset( $parsed['error'] ) ) {
			if ( 'expired' === $parsed['error'] ) {
				wc_add_notice( __( 'This cart link has expired. Please ask for a new one.', 'mandrakecrm' ), 'error' );
			} else {
				wc_add_notice( __( 'This cart link is not valid. Please ask for a new one.', 'mandrakecrm' ), 'error' );
			}
			self::redirect( wc_get_cart_url() );
		}

		$cart = $parsed['cart'];
		$add  = self::MODE_ADD === $cart['mode'];
		$same = WC()->session->get( self::SESSION_SIG ) === $cart['signature'];

		// The same link opened again in this session: keep the cart as the customer left it
		// (an "add" link never adds its items twice).
		if ( $add && $same ) {
			self::redirect( wc_get_cart_url() );
		}
		if ( $same && ! WC()->cart->is_empty() ) {
			self::redirect( self::checkout_url() );
		}

		self::fill_cart( $cart, ! $add );

		WC()->session->set( self::SESSION_REF, $cart['ref'] );
		WC()->session->set( self::SESSION_SIG, $cart['signature'] );
		WC()->session->save_data();

		self::redirect( $add || WC()->cart->is_empty() ? wc_get_cart_url() : self::checkout_url() );
	}

	/**
	 * Start a WooCommerce session for a new visitor (guests have none until they add something).
	 *
	 * @since 3.28
	 */
	private static function ensure_session() {
		if ( method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Add the link's items with WooCommerce prices (after emptying the cart, unless in "add" mode);
	 * apply the coupon if valid.
	 *
	 * @since 3.28
	 * @since 3.30 $empty_first.
	 * @param array $cart        Normalized payload from parse_link().
	 * @param bool  $empty_first Whether to empty the cart first ("replace" mode).
	 */
	private static function fill_cart( $cart, $empty_first = true ) {
		if ( $empty_first ) {
			WC()->cart->empty_cart();
		}

		foreach ( $cart['items'] as $item ) {
			list( $product_id, $variation_id, $qty ) = $item;
			self::add_item( $product_id, $variation_id, $qty );
		}

		if ( null !== $cart['coupon'] && ! WC()->cart->is_empty() ) {
			$code = wc_format_coupon_code( $cart['coupon'] );
			if ( '' !== $code && ! WC()->cart->has_discount( $code ) ) {
				// WooCommerce checks that the coupon exists and is valid, and shows its own notice.
				WC()->cart->apply_coupon( $code );
			}
		}

		WC()->cart->calculate_totals();
	}

	/**
	 * Add one item, or skip it with a notice when it cannot be bought.
	 *
	 * @since 3.28
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @param int $qty          Quantity.
	 * @return bool Whether something was added.
	 */
	private static function add_item( $product_id, $variation_id, $qty ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$parent  = $variation_id ? wc_get_product( $product_id ) : $product;

		if ( ! $product || ! $parent ) {
			self::notice_unavailable( null );
			return false;
		}

		$attributes = array();
		if ( $variation_id ) {
			if ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) {
				self::notice_unavailable( $parent );
				return false;
			}
			$attributes = wc_get_product_variation_attributes( $variation_id );
			foreach ( $attributes as $value ) {
				if ( '' === (string) $value ) {
					// A variation that accepts "any" value of an attribute: the link cannot choose for the customer.
					self::notice_unavailable( $product );
					return false;
				}
			}
		} elseif ( $product->is_type( array( 'variable', 'variation', 'grouped', 'external' ) ) ) {
			// Variable, grouped and external products need a choice or are not sold here; a variation needs its parent.
			self::notice_unavailable( $product );
			return false;
		}

		if ( 'publish' !== $parent->get_status() || ( $variation_id && 'publish' !== $product->get_status() )
			|| ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			self::notice_unavailable( $product );
			return false;
		}

		if ( ! $product->has_enough_stock( $qty ) ) {
			$available = (int) $product->get_stock_quantity();
			if ( $available < 1 ) {
				self::notice_unavailable( $product );
				return false;
			}
			$qty = $available;
			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: quantity in stock */
					__( '%1$s: quantity reduced to %2$d (available stock).', 'mandrakecrm' ),
					esc_html( $product->get_name() ),
					$available
				),
				'notice'
			);
		}

		$errors_before = wc_notice_count( 'error' );
		$key           = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $attributes );
		if ( ! $key ) {
			// WooCommerce usually explains why; add ours only when it did not.
			if ( wc_notice_count( 'error' ) === $errors_before ) {
				self::notice_unavailable( $product );
			}
			return false;
		}

		return true;
	}

	/**
	 * Notice for an item that was skipped.
	 *
	 * @since 3.28
	 * @param WC_Product|null $product Product, or null when it no longer exists.
	 */
	private static function notice_unavailable( $product ) {
		$name = $product ? $product->get_name() : __( 'A product', 'mandrakecrm' );
		wc_add_notice(
			sprintf(
				/* translators: %s: product name */
				__( '%s is not available and was not added to your cart.', 'mandrakecrm' ),
				esc_html( $name )
			),
			'error'
		);
	}

	/**
	 * Checkout URL keeping the campaign parameters of the link.
	 *
	 * @since 3.28
	 * @return string
	 */
	private static function checkout_url() {
		$url  = wc_get_checkout_url();
		$keep = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id' );
		$args = array();
		foreach ( $keep as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $param ] = rawurlencode( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) );
			}
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Redirect without caching and stop.
	 *
	 * @since 3.28
	 * @param string $url Destination.
	 */
	private static function redirect( $url ) {
		nocache_headers();
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Conversation reference in the session, if any.
	 *
	 * @since 3.28
	 * @return string|null
	 */
	private static function get_session_ref() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$ref = WC()->session->get( self::SESSION_REF );
		return ( is_string( $ref ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $ref ) ) ? $ref : null;
	}

	/**
	 * Conversation reference left by the assistant's chat on the site (cookie), if valid.
	 *
	 * @since 3.30
	 * @return string|null
	 */
	private static function get_cookie_ref() {
		if ( ! isset( $_COOKIE[ self::COOKIE_REF ] ) || ! is_string( $_COOKIE[ self::COOKIE_REF ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as a whole against a strict pattern (nothing is stripped).
		$ref = wp_unslash( $_COOKIE[ self::COOKIE_REF ] );
		return preg_match( '/^[A-Za-z0-9-]{1,64}$/', $ref ) ? $ref : null;
	}

	/**
	 * Classic checkout (and FunnelKit): save the conversation reference on the order object.
	 *
	 * The reference of a signed link used in this session wins ("link"); without one, the chat cookie ("chat").
	 *
	 * @since 3.28
	 * @since 3.30 Chat cookie and _mandrakecrm_agent_touch.
	 * @param WC_Order $order Order object (not saved yet).
	 * @param array    $data  Posted checkout data (unused).
	 * @return bool True when the meta was added to the order object.
	 */
	public static function save_ref_to_order( $order, $data = array() ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( self::ORDER_META ) ) {
			return false; // A retried payment keeps the first reference.
		}

		$ref   = self::get_session_ref();
		$touch = 'link';
		if ( ! $ref ) {
			$ref   = self::get_cookie_ref();
			$touch = 'chat';
		}
		if ( ! $ref ) {
			return false;
		}

		$order->update_meta_data( self::ORDER_META, $ref );
		$order->update_meta_data( self::ORDER_META_TOUCH, $touch );
		return true;
	}

	/**
	 * Block checkout: save the conversation reference on the draft order.
	 *
	 * @since 3.28
	 * @param WC_Order $order Order object.
	 */
	public static function save_ref_to_block_order( $order ) {
		if ( self::save_ref_to_order( $order ) && $order->get_id() ) {
			$order->save_meta_data();
		}
	}

	/**
	 * The order was placed: the next purchase in this session is not the conversation's.
	 *
	 * @since 3.28
	 */
	public static function clear_session_ref() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_REF );
			WC()->session->__unset( self::SESSION_SIG );
		}
	}
}
