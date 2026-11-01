<?php
/**
 * MandrakeCRM Agent Shipping
 *
 * Signed shipping quotes for MandrakeCRM's sales assistant, server to server:
 *
 *   POST /wp-json/mandrakecrm/v1/agent/shipping-quote
 *   X-Mandrake-Agent-Signature: v1.<timestamp>.<signature>
 *   Content-Type: application/json
 *   {"postcode":"20040020","country":"BR","state":"RJ","city":"...","items":[{"product_id":101,"variation_id":0,"qty":2}]}
 *
 * The shipping options are calculated by WooCommerce itself, with the store's own zones, shipping classes and
 * shipping methods (also the ones added by shipping plugins): a virtual package (the products, with WooCommerce
 * prices, and the destination) goes through WC()->shipping()->calculate_shipping_for_package(). No cart, no
 * session and no customer are created or changed: the quote lends the shipping engine an in-memory session,
 * customer and cart for the duration of the calculation and puts the previous ones back. No cart action fires
 * (add to cart, cart updated, totals calculated), so no abandoned cart is recorded, by this plugin or another.
 *
 * Signature (nothing is answered without it):
 *
 * - timestamp   = Unix time in seconds, in decimal, no sign and no leading zeros; valid 5 minutes either side.
 * - body hash   = SHA-256 of the raw request body, in lower-case hex (64 characters).
 * - signature   = base64url (no padding) of HMAC-SHA256( "v1.<timestamp>.<body hash>", derived key ): 43 characters.
 * - derived key = HMAC-SHA256( key = store token, message = "mandrake-agent-shipping-v1" ), raw 32 bytes.
 *                 In PHP: hash_hmac( 'sha256', 'mandrake-agent-shipping-v1', $token, true ). The token is never used
 *                 raw, and this key differs from the cart link one (3.28) and the Store API quote mark one (3.29).
 *
 * Errors (WordPress REST format, {"code","message","data":{"status",...}}): INVALID_SIGNATURE (401), INVALID_INPUT
 * (400, data.field), NO_ITEMS (422, data.skipped), NO_RATES (422, data.reason), QUOTE_FAILED (500).
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      3.29
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agent Shipping class.
 *
 * @since 3.29
 */
class MandrakeCRM_Agent_Shipping {

	/**
	 * REST namespace.
	 *
	 * @since 3.29
	 * @var string
	 */
	const REST_NAMESPACE = 'mandrakecrm/v1';

	/**
	 * REST route.
	 *
	 * @since 3.29
	 * @var string
	 */
	const REST_ROUTE = '/agent/shipping-quote';

	/**
	 * Request header with the signature.
	 *
	 * @since 3.29
	 * @var string
	 */
	const HEADER = 'X-Mandrake-Agent-Signature';

	/**
	 * Label used to derive the signing key from the store token.
	 *
	 * @since 3.29
	 * @var string
	 */
	const KEY_LABEL = 'mandrake-agent-shipping-v1';

	/**
	 * Signature format version (first part of the header).
	 *
	 * @since 3.29
	 * @var string
	 */
	const FORMAT_VERSION = 'v1';

	/**
	 * Accepted difference between the timestamp and the store's clock, either side (5 minutes).
	 *
	 * @since 3.29
	 * @var int
	 */
	const WINDOW_SECONDS = 300;

	/**
	 * Maximum length of the header value.
	 *
	 * @since 3.29
	 * @var int
	 */
	const MAX_HEADER_LENGTH = 64;

	/**
	 * Maximum size of the request body, in bytes.
	 *
	 * @since 3.29
	 * @var int
	 */
	const MAX_BODY_BYTES = 8192;

	/**
	 * Maximum number of items.
	 *
	 * @since 3.29
	 * @var int
	 */
	const MAX_ITEMS = 20;

	/**
	 * Maximum quantity of one item.
	 *
	 * @since 3.29
	 * @var int
	 */
	const MAX_QTY = 99;

	/**
	 * Maximum number of options answered.
	 *
	 * @since 3.29
	 * @var int
	 */
	const MAX_OPTIONS = 20;

	/**
	 * Rate metadata keys that may carry a delivery time (Correios, Melhor Envio, Frenet and others).
	 *
	 * @since 3.29
	 * @var string
	 */
	const DELIVERY_META_PATTERN = '/delivery|forecast|prazo|entrega/i';

	/**
	 * Initialize hooks (only when the store is connected).
	 *
	 * @since 3.29
	 */
	public static function init() {
		if ( '' === self::get_token() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the REST route.
	 *
	 * @since 3.29
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Store token (the shared secret with MandrakeCRM).
	 *
	 * @since 3.29
	 * @return string
	 */
	private static function get_token() {
		$token = get_option( 'mandrakecrm_token', '' );
		return is_string( $token ) ? $token : '';
	}

	// ------------------------------------------------------------------------------------------------ signature

	/**
	 * Signing key derived from the token: HMAC-SHA256 with key = token and message = KEY_LABEL.
	 *
	 * @since 3.29
	 * @param string $token Store token.
	 * @return string Raw 32-byte key.
	 */
	public static function derive_key( $token ) {
		return hash_hmac( 'sha256', self::KEY_LABEL, (string) $token, true );
	}

	/**
	 * The signed message: "v1.<timestamp>.<sha256 hex of the body>".
	 *
	 * @since 3.29
	 * @param string|int $timestamp Unix time in seconds, as written in the header.
	 * @param string     $body      Raw request body.
	 * @return string
	 */
	public static function signed_message( $timestamp, $body ) {
		return self::FORMAT_VERSION . '.' . $timestamp . '.' . hash( 'sha256', (string) $body );
	}

	/**
	 * Signature: base64url (no padding) of HMAC-SHA256( signed message, derived key ).
	 *
	 * @since 3.29
	 * @param string|int $timestamp Unix time in seconds, as written in the header.
	 * @param string     $body      Raw request body.
	 * @param string     $token     Store token.
	 * @return string
	 */
	public static function sign( $timestamp, $body, $token ) {
		$mac = hash_hmac( 'sha256', self::signed_message( $timestamp, $body ), self::derive_key( $token ), true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Full header value ("v1.<timestamp>.<signature>").
	 *
	 * @since 3.29
	 * @param int    $timestamp Unix time in seconds.
	 * @param string $body      Raw request body.
	 * @param string $token     Store token.
	 * @return string
	 */
	public static function header_value( $timestamp, $body, $token ) {
		$timestamp = (string) (int) $timestamp;
		return self::FORMAT_VERSION . '.' . $timestamp . '.' . self::sign( $timestamp, $body, $token );
	}

	/**
	 * Whether a header value signs this body for this store now.
	 *
	 * @since 3.29
	 * @param mixed  $value Header value.
	 * @param mixed  $body  Raw request body.
	 * @param string $token Store token.
	 * @param int    $now   Current Unix time.
	 * @return bool
	 */
	public static function verify( $value, $body, $token, $now ) {
		if ( ! is_string( $value ) || ! is_string( $body ) || ! is_string( $token ) || '' === $token ) {
			return false;
		}
		$value = trim( $value );
		if ( strlen( $value ) > self::MAX_HEADER_LENGTH || ! preg_match( '/^v1\.([1-9][0-9]{0,11})\.([A-Za-z0-9_-]{43})$/', $value, $m ) ) {
			return false;
		}
		if ( abs( (int) $now - (int) $m[1] ) > self::WINDOW_SECONDS ) {
			return false;
		}
		return hash_equals( self::sign( $m[1], $body, $token ), $m[2] );
	}

	/**
	 * Permission callback: the signature of the request, before anything else is read.
	 *
	 * @since 3.29
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		$value = $request->get_header( self::HEADER );
		$body  = $request->get_body();
		if ( self::verify( $value, $body, self::get_token(), time() ) ) {
			return true;
		}
		return new WP_Error( 'INVALID_SIGNATURE', 'Missing, invalid or expired signature.', array( 'status' => 401 ) );
	}

	// ------------------------------------------------------------------------------------------------ input

	/**
	 * Error helper.
	 *
	 * @since 3.29
	 * @param string $code   Error code.
	 * @param string $message Message (English, for logs).
	 * @param int    $status HTTP status.
	 * @param array  $data   Extra data.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status, $data = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * Strict positive (or non-negative) JSON integer.
	 *
	 * @since 3.29
	 * @param mixed $value Value.
	 * @param int   $min   Smallest accepted value.
	 * @param int   $max   Largest accepted value.
	 * @return bool
	 */
	private static function is_int_between( $value, $min, $max ) {
		return is_int( $value ) && $value >= $min && $value <= $max;
	}

	/**
	 * Parses and validates the body. The items keep the ids exactly as sent (for added/skipped).
	 *
	 * @since 3.29
	 * @param string $body Raw body.
	 * @return array|WP_Error {postcode, country, state, city, items: [{product_id, variation_id, qty}]}
	 */
	public static function parse_input( $body ) {
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::error( 'INVALID_INPUT', 'Body must be a JSON object of at most 8 KB.', 400, array( 'field' => 'body' ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || array_values( $data ) === $data ) {
			return self::error( 'INVALID_INPUT', 'Body must be a JSON object.', 400, array( 'field' => 'body' ) );
		}

		$country = isset( $data['country'] ) && is_string( $data['country'] ) ? strtoupper( trim( $data['country'] ) ) : '';
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return self::error( 'INVALID_INPUT', 'country must be an ISO 3166-1 alpha-2 code.', 400, array( 'field' => 'country' ) );
		}

		$raw_postcode = isset( $data['postcode'] ) && is_string( $data['postcode'] ) ? trim( $data['postcode'] ) : '';
		if ( '' === $raw_postcode || strlen( $raw_postcode ) > 20 ) {
			return self::error( 'INVALID_INPUT', 'postcode is required.', 400, array( 'field' => 'postcode' ) );
		}
		$postcode = wc_format_postcode( wc_clean( $raw_postcode ), $country );
		if ( ! WC_Validation::is_postcode( $postcode, $country ) ) {
			return self::error( 'INVALID_INPUT', 'postcode is not valid for the country.', 400, array( 'field' => 'postcode' ) );
		}

		$state = '';
		if ( isset( $data['state'] ) && null !== $data['state'] && '' !== $data['state'] ) {
			if ( ! is_string( $data['state'] ) || strlen( $data['state'] ) > 10 ) {
				return self::error( 'INVALID_INPUT', 'state must be a state code.', 400, array( 'field' => 'state' ) );
			}
			$state  = strtoupper( trim( $data['state'] ) );
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) && $states && ! isset( $states[ $state ] ) ) {
				return self::error( 'INVALID_INPUT', 'state is not a state of the country.', 400, array( 'field' => 'state' ) );
			}
		}

		$city = '';
		if ( isset( $data['city'] ) && null !== $data['city'] && '' !== $data['city'] ) {
			if ( ! is_string( $data['city'] ) || strlen( $data['city'] ) > 100 ) {
				return self::error( 'INVALID_INPUT', 'city must be text of at most 100 characters.', 400, array( 'field' => 'city' ) );
			}
			$city = sanitize_text_field( $data['city'] );
		}

		$items = isset( $data['items'] ) ? $data['items'] : null;
		if ( ! is_array( $items ) || ! $items || array_values( $items ) !== $items || count( $items ) > self::MAX_ITEMS ) {
			return self::error( 'INVALID_INPUT', 'items must be a list of 1 to 20 items.', 400, array( 'field' => 'items' ) );
		}
		$merged = array();
		foreach ( $items as $i => $item ) {
			$ok = is_array( $item )
				&& isset( $item['product_id'], $item['qty'] )
				&& self::is_int_between( $item['product_id'], 1, PHP_INT_MAX )
				&& self::is_int_between( isset( $item['variation_id'] ) ? $item['variation_id'] : 0, 0, PHP_INT_MAX )
				&& self::is_int_between( $item['qty'], 1, self::MAX_QTY );
			if ( ! $ok ) {
				return self::error( 'INVALID_INPUT', 'Each item needs integer product_id (> 0), variation_id (>= 0) and qty (1-99).', 400, array( 'field' => 'items.' . $i ) );
			}
			$variation_id = isset( $item['variation_id'] ) ? $item['variation_id'] : 0;
			$key          = $item['product_id'] . ':' . $variation_id;
			if ( isset( $merged[ $key ] ) ) {
				$merged[ $key ]['qty'] += $item['qty'];
				if ( $merged[ $key ]['qty'] > self::MAX_QTY ) {
					return self::error( 'INVALID_INPUT', 'The same item adds up to more than 99 units.', 400, array( 'field' => 'items.' . $i ) );
				}
				continue;
			}
			$merged[ $key ] = array(
				'product_id'   => $item['product_id'],
				'variation_id' => $variation_id,
				'qty'          => $item['qty'],
			);
		}

		return array(
			'postcode' => $postcode,
			'country'  => $country,
			'state'    => $state,
			'city'     => $city,
			'items'    => array_values( $merged ),
		);
	}

	/**
	 * The product to quote for one item, or why it cannot be bought (the same checks as adding it to the cart).
	 *
	 * @since 3.29
	 * @param array $item {product_id, variation_id, qty}.
	 * @return array {ok: true, product, product_id, variation_id, variation, qty} or {ok: false, reason}.
	 */
	public static function resolve_item( $item ) {
		$product_id   = (int) $item['product_id'];
		$variation_id = (int) $item['variation_id'];
		$product      = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );

		if ( ! $product ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}
		if ( $variation_id > 0 ) {
			if ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) {
				return array( 'ok' => false, 'reason' => 'not_found' );
			}
		} elseif ( $product->is_type( 'variation' ) ) {
			// A variation id sent as the product: quote that variation.
			$variation_id = $product_id;
			$product_id   = (int) $product->get_parent_id();
		} elseif ( $product->is_type( 'variable' ) ) {
			return array( 'ok' => false, 'reason' => 'choose_variation' );
		} elseif ( $product->is_type( array( 'grouped', 'external' ) ) ) {
			return array( 'ok' => false, 'reason' => 'not_purchasable' );
		}

		if ( 'publish' !== $product->get_status() ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}
		if ( $variation_id > 0 ) {
			$parent = wc_get_product( $product_id );
			if ( ! $parent || 'publish' !== $parent->get_status() ) {
				return array( 'ok' => false, 'reason' => 'not_found' );
			}
		}
		if ( ! $product->is_purchasable() ) {
			return array( 'ok' => false, 'reason' => 'not_purchasable' );
		}
		if ( ! $product->is_in_stock() ) {
			return array( 'ok' => false, 'reason' => 'out_of_stock' );
		}

		$qty = (int) $item['qty'];
		if ( $product->is_sold_individually() ) {
			$qty = 1;
		}
		if ( ! $product->has_enough_stock( $qty ) ) {
			return array( 'ok' => false, 'reason' => 'insufficient_stock' );
		}

		return array(
			'ok'           => true,
			'product'      => $product,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => $variation_id > 0 && is_callable( array( $product, 'get_variation_attributes' ) ) ? (array) $product->get_variation_attributes() : array(),
			'qty'          => $qty,
		);
	}

	// ------------------------------------------------------------------------------------------------ request

	/**
	 * REST callback.
	 *
	 * @since 3.29
	 * @param WP_REST_Request $request Request (already signed: see check_permission).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( $request ) {
		$input = self::parse_input( $request->get_body() );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$result = self::quote( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = new WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * The quote for a validated input.
	 *
	 * @since 3.29
	 * @param array $input See parse_input().
	 * @return array|WP_Error
	 */
	public static function quote( $input ) {
		if ( ! function_exists( 'wc_shipping_enabled' ) || ! wc_shipping_enabled() ) {
			return self::error( 'NO_RATES', 'Shipping is disabled in this store.', 422, array( 'reason' => 'shipping_disabled' ) );
		}
		$shipping_countries = WC()->countries->get_shipping_countries();
		if ( is_array( $shipping_countries ) && ! isset( $shipping_countries[ $input['country'] ] ) ) {
			return self::error( 'NO_RATES', 'The store does not ship to this country.', 422, array( 'reason' => 'country_not_shipped' ) );
		}

		$resolved = array();
		$added    = array();
		$skipped  = array();
		foreach ( $input['items'] as $item ) {
			$r = self::resolve_item( $item );
			if ( $r['ok'] ) {
				$r['sent']  = $item;
				$resolved[] = $r;
				$added[]    = array(
					'product_id'   => $item['product_id'],
					'variation_id' => $item['variation_id'],
					'qty'          => $r['qty'],
				);
			} else {
				$skipped[] = array(
					'product_id'   => $item['product_id'],
					'variation_id' => $item['variation_id'],
					'reason'       => $r['reason'],
				);
			}
		}
		if ( ! $resolved ) {
			return self::error( 'NO_ITEMS', 'None of the items can be bought now.', 422, array( 'skipped' => $skipped ) );
		}

		try {
			$calculated = self::with_sandbox(
				$input,
				function () use ( $resolved ) {
					return self::calculate( $resolved );
				}
			);
		} catch ( Throwable $e ) {
			// A shipping method failed: the quote is not available (MandrakeCRM falls back to the checkout's own quote).
			return self::error( 'QUOTE_FAILED', 'The shipping calculation failed.', 500 );
		}
		if ( is_wp_error( $calculated ) ) {
			return $calculated;
		}

		$calculated['skipped'] = $skipped;
		$calculated['added']   = $added;
		return array_merge(
			array(
				'ok'          => true,
				'v'           => 1,
				'destination' => array(
					'country' => $input['country'],
					'state'   => $input['state'],
				),
			),
			$calculated
		);
	}

	/**
	 * Runs $callback with an in-memory WooCommerce session, customer (at the destination) and cart, and always puts
	 * the previous ones back. Nothing of the sandbox is saved, sent as a cookie or announced to other plugins.
	 *
	 * @since 3.29
	 * @param array    $input    Validated input (destination).
	 * @param callable $callback Calculation.
	 * @return mixed What the callback returns.
	 */
	public static function with_sandbox( $input, $callback ) {
		global $wp_actions;

		if ( defined( 'WC_ABSPATH' ) ) {
			if ( ! function_exists( 'wc_get_cart_item_data_hash' ) ) {
				include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
			}
			if ( ! function_exists( 'wc_add_notice' ) ) {
				include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
			}
		}
		if ( ! class_exists( 'MandrakeCRM_Agent_Shipping_Session' ) ) {
			require_once __DIR__ . '/class-mandrakecrm-agent-shipping-session.php';
		}

		$wc    = WC();
		$saved = array( $wc->session, $wc->customer, $wc->cart );

		// get_cart() loads the cart from the session once per request (and that load announces a cart update to
		// every plugin). Mark the load as done while the sandbox lives, so the sandbox cart keeps its own items.
		$load_hook   = 'woocommerce_load_cart_from_session';
		$load_before = isset( $wp_actions[ $load_hook ] ) ? $wp_actions[ $load_hook ] : null;

		try {
			$wc->session = new MandrakeCRM_Agent_Shipping_Session();

			$customer = new WC_Customer( 0, true );
			foreach ( array( 'shipping', 'billing' ) as $type ) {
				$customer->{"set_{$type}_country"}( $input['country'] );
				$customer->{"set_{$type}_state"}( $input['state'] );
				$customer->{"set_{$type}_postcode"}( $input['postcode'] );
				$customer->{"set_{$type}_city"}( $input['city'] );
				$customer->{"set_{$type}_address_1"}( '' );
				$customer->{"set_{$type}_address_2"}( '' );
			}
			$customer->set_calculated_shipping( true );
			$wc->customer = $customer;

			// The sandbox cart registers no session hooks (no cookies, no session writes, no persistent cart).
			add_filter( 'woocommerce_cart_session_initialize', '__return_false', PHP_INT_MAX );
			try {
				$wc->cart = new WC_Cart();
			} finally {
				remove_filter( 'woocommerce_cart_session_initialize', '__return_false', PHP_INT_MAX );
			}

			if ( ! is_array( $wp_actions ) ) {
				$wp_actions = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			$wp_actions[ $load_hook ] = max( 1, (int) $load_before ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			return call_user_func( $callback );
		} finally {
			if ( $wc->cart instanceof WC_Cart && $wc->cart !== $saved[2] ) {
				$wc->cart->set_cart_contents( array() );
			}
			if ( null === $load_before ) {
				unset( $wp_actions[ $load_hook ] );
			} else {
				$wp_actions[ $load_hook ] = $load_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			list( $wc->session, $wc->customer, $wc->cart ) = $saved;
		}
	}

	/**
	 * Inside the sandbox: fills the cart (no cart action fires), sets its subtotals, builds WooCommerce's shipping
	 * package and calculates its rates; reads the zone of the package and its free-shipping rules.
	 *
	 * @since 3.29
	 * @param array $resolved Items from resolve_item().
	 * @return array|WP_Error
	 */
	public static function calculate( $resolved ) {
		$cart     = WC()->cart;
		$decimals = wc_get_price_decimals();
		$contents = array();
		$subtotal = 0.0;
		$tax      = 0.0;

		foreach ( $resolved as $r ) {
			$product = $r['product'];
			$qty     = $r['qty'];
			if ( wc_tax_enabled() && $product->is_taxable() ) {
				$excl = (float) wc_get_price_excluding_tax( $product, array( 'qty' => $qty ) );
				$incl = (float) wc_get_price_including_tax( $product, array( 'qty' => $qty ) );
			} else {
				$excl = (float) $product->get_price() * $qty;
				$incl = $excl;
			}
			$line_tax  = max( 0.0, $incl - $excl );
			$key       = $cart->generate_cart_id( $r['product_id'], $r['variation_id'], $r['variation'] );
			$contents[ $key ] = array(
				'key'               => $key,
				'product_id'        => $r['product_id'],
				'variation_id'      => $r['variation_id'],
				'variation'         => $r['variation'],
				'quantity'          => $qty,
				'data'              => $product,
				'data_hash'         => function_exists( 'wc_get_cart_item_data_hash' ) ? wc_get_cart_item_data_hash( $product ) : '',
				'line_tax_data'     => array(
					'subtotal' => array(),
					'total'    => array(),
				),
				'line_subtotal'     => $excl,
				'line_subtotal_tax' => $line_tax,
				'line_total'        => $excl,
				'line_tax'          => $line_tax,
			);
			$subtotal += $excl;
			$tax      += $line_tax;
		}

		$cart->set_cart_contents( $contents );
		$cart->set_subtotal( $subtotal );
		$cart->set_subtotal_tax( $tax );
		$cart->set_cart_contents_total( $subtotal );
		$cart->set_cart_contents_tax( $tax );
		$cart->set_discount_total( 0 );
		$cart->set_discount_tax( 0 );

		$summary = array(
			'currency'           => get_woocommerce_currency(),
			'decimals'           => $decimals,
			'items_subtotal'     => round( $subtotal, $decimals ),
			'items_tax'          => round( $tax, $decimals ),
			'items_total'        => round( $subtotal + $tax, $decimals ),
			'displayed_subtotal' => round( (float) $cart->get_displayed_subtotal(), $decimals ),
		);

		if ( ! $cart->needs_shipping() ) {
			return self::error( 'NO_RATES', 'These items do not need shipping.', 422, array( 'reason' => 'not_needed' ) );
		}

		$packages = $cart->get_shipping_packages();
		if ( 1 !== count( $packages ) ) {
			return self::error( 'NO_RATES', 'The items ship in several packages.', 422, array( 'reason' => 'multiple_packages' ) );
		}
		$package_key = array_keys( $packages )[0];
		$package     = WC()->shipping()->calculate_shipping_for_package( $packages[ $package_key ], $package_key );
		$rates       = is_array( $package ) && isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();

		$options = array();
		$free    = false;
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}
			$option = self::read_rate( $rate, $decimals );
			if ( null === $option ) {
				continue;
			}
			if ( 'free_shipping' === $option['method_id'] ) {
				$free = true;
			}
			$options[] = $option;
			if ( count( $options ) >= self::MAX_OPTIONS ) {
				break;
			}
		}
		if ( ! $options ) {
			return self::error( 'NO_RATES', 'No shipping option for this destination and these items.', 422, array( 'reason' => 'no_rates' ) );
		}

		$zone = self::read_zone( is_array( $package ) ? $package : $packages[ $package_key ] );

		return array_merge(
			$summary,
			array(
				'zone'          => $zone['zone'],
				'options'       => $options,
				'free_shipping' => array(
					'available' => $free,
					'rules'     => $zone['rules'],
				),
			)
		);
	}

	/**
	 * One option of the quote.
	 *
	 * @since 3.29
	 * @param WC_Shipping_Rate $rate     Rate.
	 * @param int              $decimals Price decimals.
	 * @return array|null
	 */
	public static function read_rate( $rate, $decimals ) {
		$label = trim( wp_strip_all_tags( (string) $rate->get_label() ) );
		if ( '' === $label || ! is_numeric( $rate->get_cost() ) ) {
			return null;
		}
		$meta = array();
		foreach ( (array) $rate->get_meta_data() as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( self::DELIVERY_META_PATTERN, $key ) || ! is_scalar( $value ) || is_bool( $value ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( (string) $value ) );
			if ( '' !== $text && count( $meta ) < 5 ) {
				$meta[ substr( $key, 0, 60 ) ] = substr( $text, 0, 80 );
			}
		}
		$delivery = '';
		if ( is_callable( array( $rate, 'get_delivery_time' ) ) ) {
			$delivery = trim( wp_strip_all_tags( (string) $rate->get_delivery_time() ) );
		}
		if ( '' === $delivery && $meta ) {
			$delivery = (string) reset( $meta );
		}

		return array(
			'id'            => substr( (string) $rate->get_id(), 0, 100 ),
			'method_id'     => substr( (string) $rate->get_method_id(), 0, 60 ),
			'instance_id'   => (int) $rate->get_instance_id(),
			'label'         => substr( $label, 0, 120 ),
			'cost'          => round( (float) $rate->get_cost(), $decimals ),
			'taxes'         => round( (float) $rate->get_shipping_tax(), $decimals ),
			'delivery_time' => '' === $delivery ? null : substr( $delivery, 0, 80 ),
			'meta_data'     => (object) $meta,
		);
	}

	/**
	 * The zone WooCommerce matched for the package, its enabled methods and its free-shipping rules.
	 *
	 * @since 3.29
	 * @param array $package Shipping package (with destination).
	 * @return array {zone: {id, name, is_rest_of_world, method_ids, instance_ids} | null, rules: [...]}
	 */
	public static function read_zone( $package ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array(
				'zone'  => null,
				'rules' => array(),
			);
		}
		$zone         = WC_Shipping_Zones::get_zone_matching_package( $package );
		$method_ids   = array();
		$instance_ids = array();
		$rules        = array();
		foreach ( (array) $zone->get_shipping_methods( true ) as $method ) {
			$method_ids[]   = substr( (string) $method->id, 0, 60 );
			$instance_ids[] = (int) $method->get_instance_id();
			if ( 'free_shipping' !== $method->id ) {
				continue;
			}
			$requires = (string) $method->get_option( 'requires', '' );
			$min      = (float) wc_format_decimal( $method->get_option( 'min_amount', 0 ) );
			$rules[]  = array(
				'instance_id'      => (int) $method->get_instance_id(),
				'requires'         => in_array( $requires, array( '', 'coupon', 'min_amount', 'either', 'both' ), true ) ? $requires : '',
				'min_amount'       => $min > 0 ? $min : null,
				'ignore_discounts' => 'yes' === $method->get_option( 'ignore_discounts', 'no' ),
			);
		}
		$id = (int) $zone->get_id();
		return array(
			'zone'  => array(
				'id'               => $id,
				'name'             => substr( trim( wp_strip_all_tags( (string) $zone->get_zone_name() ) ), 0, 120 ),
				'is_rest_of_world' => 0 === $id,
				'method_ids'       => array_values( array_unique( $method_ids ) ),
				'instance_ids'     => $instance_ids,
			),
			'rules' => $rules,
		);
	}
}
