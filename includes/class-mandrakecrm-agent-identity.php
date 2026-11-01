<?php
/**
 * MandrakeCRM Agent Identity
 *
 * Signed identity of the logged-in customer for MandrakeCRM's sales assistant on the store's site.
 * The assistant (a script of the store's own pages) asks this route who is logged in, and MandrakeCRM
 * verifies the answer with the store token; the identity is never printed in the HTML (a cached page
 * would hand one customer's identity to another).
 *
 *   GET /wp-json/mandrakecrm/v1/agent/identity
 *
 * - Session: the WordPress "logged_in" cookie, checked with wp_validate_auth_cookie( '', 'logged_in' ).
 *   The REST API treats a cookie without a REST nonce as anonymous, so the route reads the cookie itself.
 * - Same origin only: the request must carry "Sec-Fetch-Site: same-origin" or, when the browser does not
 *   send that header, a Referer of the store's own host. Otherwise: 403 FORBIDDEN_ORIGIN.
 * - No session: 204 (no body).
 * - Session: 200 {"v":1,"p":"<payload>","s":"<signature>"}
 *     payload     = base64url (no padding) of the JSON
 *                   {"v":1,"c":<WooCommerce customer id>,"e":"<account e-mail>","x":<expiry, unix>,"n":"<nonce>"}
 *                   x = now + 900 (15 minutes); n = 16 random bytes in base64url (no padding).
 *     signature   = base64url (no padding) of HMAC-SHA256( payload as sent, derived key ): 43 characters.
 *     derived key = HMAC-SHA256( key = store token, message = "mandrake-agent-identity-v1" ), raw 32 bytes.
 *                   In PHP: hash_hmac( 'sha256', 'mandrake-agent-identity-v1', $token, true ). The token is never
 *                   used raw, and this key differs from the signed cart links and the quote ones.
 * - Every answer carries "Cache-Control: private, no-store" and "Vary: Cookie".
 *
 * MandrakeCRM checks the signature, the expiry, that the nonce is used once and that the customer exists in
 * the store with that id and e-mail. The route is registered only when the store is connected.
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      3.30
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agent Identity class.
 *
 * @since 3.30
 */
class MandrakeCRM_Agent_Identity {

	/**
	 * REST namespace.
	 *
	 * @since 3.30
	 * @var string
	 */
	const REST_NAMESPACE = 'mandrakecrm/v1';

	/**
	 * REST route.
	 *
	 * @since 3.30
	 * @var string
	 */
	const REST_ROUTE = '/agent/identity';

	/**
	 * Label used to derive the signing key from the store token.
	 *
	 * @since 3.30
	 * @var string
	 */
	const KEY_LABEL = 'mandrake-agent-identity-v1';

	/**
	 * Payload format version.
	 *
	 * @since 3.30
	 * @var int
	 */
	const FORMAT_VERSION = 1;

	/**
	 * Lifetime of a signed identity (15 minutes).
	 *
	 * @since 3.30
	 * @var int
	 */
	const LIFETIME_SECONDS = 900;

	/**
	 * Random bytes of the nonce.
	 *
	 * @since 3.30
	 * @var int
	 */
	const NONCE_BYTES = 16;

	/**
	 * Initialize hooks (only when the store is connected).
	 *
	 * @since 3.30
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
	 * @since 3.30
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_request' ),
				// Public on purpose: the callback checks the origin and the session cookie itself.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Store token (the shared secret with MandrakeCRM).
	 *
	 * @since 3.30
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
	 * @since 3.30
	 * @param string $token Store token.
	 * @return string Raw 32-byte key.
	 */
	public static function derive_key( $token ) {
		return hash_hmac( 'sha256', self::KEY_LABEL, (string) $token, true );
	}

	/**
	 * Base64url without padding.
	 *
	 * @since 3.30
	 * @param string $data Binary data.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Signature of a payload: base64url (no padding) of HMAC-SHA256( payload, derived key ).
	 *
	 * @since 3.30
	 * @param string $payload Payload exactly as sent (base64url).
	 * @param string $token   Store token.
	 * @return string
	 */
	public static function sign( $payload, $token ) {
		return self::base64url_encode( hash_hmac( 'sha256', (string) $payload, self::derive_key( $token ), true ) );
	}

	/**
	 * The signed answer for a customer.
	 *
	 * @since 3.30
	 * @param int    $customer_id WooCommerce customer id (the WordPress user id).
	 * @param string $email       Account e-mail.
	 * @param string $token       Store token.
	 * @param int    $now         Current Unix time.
	 * @param string $nonce_raw   Random bytes of the nonce (NONCE_BYTES long).
	 * @return array {v, p, s}
	 */
	public static function build( $customer_id, $email, $token, $now, $nonce_raw ) {
		$doc = array(
			'v' => self::FORMAT_VERSION,
			'c' => (int) $customer_id,
			'e' => (string) $email,
			'x' => (int) $now + self::LIFETIME_SECONDS,
			'n' => self::base64url_encode( $nonce_raw ),
		);

		// Compact JSON; the signature covers the base64url text, not the JSON.
		$json    = wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload = self::base64url_encode( (string) $json );

		return array(
			'v' => self::FORMAT_VERSION,
			'p' => $payload,
			's' => self::sign( $payload, $token ),
		);
	}

	// ------------------------------------------------------------------------------------------------ origin

	/**
	 * Host and port of a URL, normalized ("example.com:443"), or '' when it has no host.
	 *
	 * @since 3.30
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_key( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return strtolower( $parts['host'] ) . ':' . $port;
	}

	/**
	 * Whether the request comes from the store's own pages.
	 *
	 * "Sec-Fetch-Site: same-origin" is required when the browser sends that header; browsers that do not
	 * send it must send a Referer of the store's host. An Origin of another host is always refused.
	 *
	 * @since 3.30
	 * @param string|null $sec_fetch_site Sec-Fetch-Site header.
	 * @param string|null $referer        Referer header.
	 * @param string|null $origin         Origin header.
	 * @param string      $home_url       The store's home URL.
	 * @return bool
	 */
	public static function is_same_origin( $sec_fetch_site, $referer, $origin, $home_url ) {
		$home = self::host_key( $home_url );
		if ( '' === $home ) {
			return false;
		}

		if ( is_string( $origin ) && '' !== trim( $origin ) && 'null' !== trim( $origin ) && self::host_key( trim( $origin ) ) !== $home ) {
			return false;
		}

		if ( is_string( $sec_fetch_site ) && '' !== trim( $sec_fetch_site ) ) {
			return 'same-origin' === strtolower( trim( $sec_fetch_site ) );
		}

		return is_string( $referer ) && '' !== trim( $referer ) && self::host_key( trim( $referer ) ) === $home;
	}

	// ------------------------------------------------------------------------------------------------ request

	/**
	 * Answer with the no-cache headers of this route.
	 *
	 * @since 3.30
	 * @param mixed $data   Body (null for none).
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function respond( $data, $status ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * REST callback.
	 *
	 * @since 3.30
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$same_origin = self::is_same_origin(
			$request->get_header( 'sec_fetch_site' ),
			$request->get_header( 'referer' ),
			$request->get_header( 'origin' ),
			home_url( '/' )
		);

		// JSONP would let another site read the answer through a script tag.
		if ( ! $same_origin || null !== $request->get_param( '_jsonp' ) ) {
			return self::respond(
				array(
					'code'    => 'FORBIDDEN_ORIGIN',
					'message' => 'Only the store pages can ask for the identity.',
					'data'    => array( 'status' => 403 ),
				),
				403
			);
		}

		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		$user    = $user_id ? get_userdata( (int) $user_id ) : false;
		$email   = $user ? trim( (string) $user->user_email ) : '';
		if ( ! $user || '' === $email ) {
			return self::respond( null, 204 );
		}

		return self::respond( self::build( (int) $user->ID, $email, self::get_token(), time(), random_bytes( self::NONCE_BYTES ) ), 200 );
	}
}
