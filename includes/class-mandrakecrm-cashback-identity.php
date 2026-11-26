<?php
/**
 * MandrakeCRM Cashback Identity
 *
 * Signed identity of the logged-in customer for MandrakeCRM's cashback balance on the store's site («My account › Cashback»).
 * The cashback widget asks this route who is logged in and passes the answer to MandrakeCRM, which verifies it with the
 * store token: no secret is ever published in the site, and only the account's own email is trusted. The identity is
 * never printed in the HTML (a cached page would hand one customer's identity to another).
 *
 *   GET /wp-json/mandrakecrm/v1/cashback/identity
 *
 * - Session: the WordPress "logged_in" cookie, checked with wp_validate_auth_cookie( '', 'logged_in' ).
 * - Same origin only (MandrakeCRM_Agent_Identity::is_same_origin()): "Sec-Fetch-Site: same-origin" or, when the browser does
 *   not send it, a Referer of the store's own host; an Origin of another host or _jsonp: 403 FORBIDDEN_ORIGIN.
 * - No session, or an account without email: 204 (no body).
 * - Session: 200 {"v":1,"p":"<payload>","s":"<signature>"}
 *     payload     = base64url (no padding) of the JSON {"v":1,"c":<customer id>,"e":"<account e-mail>","x":<expiry, unix>,"n":"<nonce>"}
 *                   x = now + 300 (5 minutes); n = 16 random bytes in base64url (no padding). The email is the account's (user_email),
 *                   never the billing one, which the customer changes without any check.
 *     signature   = base64url (no padding) of HMAC-SHA256( payload as sent, derived key ).
 *     derived key = HMAC-SHA256( key = store token, message = "mandrake-cashback-identity-v1" ), raw 32 bytes: another key than the
 *                   assistant's identity (mandrake-agent-identity-v1), so an identity of one route never verifies for the other.
 * - Every answer carries "Cache-Control: private, no-store" and "Vary: Cookie", and tells page caches not to store it
 *   (DONOTCACHEPAGE, WordPress' no-cache headers and LiteSpeed Cache's no-cache action: review N3).
 *
 * MandrakeCRM checks the signature and the expiry, and uses the nonce once to redeem. The route is registered only when the
 * store is connected.
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

require_once __DIR__ . '/class-mandrakecrm-agent-identity.php';

/**
 * Cashback Identity class.
 *
 * @since 3.34
 */
class MandrakeCRM_Cashback_Identity {

	/**
	 * REST namespace.
	 *
	 * @since 3.34
	 * @var string
	 */
	const REST_NAMESPACE = 'mandrakecrm/v1';

	/**
	 * REST route.
	 *
	 * @since 3.34
	 * @var string
	 */
	const REST_ROUTE = '/cashback/identity';

	/**
	 * Label used to derive the signing key from the store token.
	 *
	 * @since 3.34
	 * @var string
	 */
	const KEY_LABEL = 'mandrake-cashback-identity-v1';

	/**
	 * Payload format version.
	 *
	 * @since 3.34
	 * @var int
	 */
	const FORMAT_VERSION = 1;

	/**
	 * Lifetime of a signed identity (5 minutes).
	 *
	 * @since 3.34
	 * @var int
	 */
	const LIFETIME_SECONDS = 300;

	/**
	 * Random bytes of the nonce.
	 *
	 * @since 3.34
	 * @var int
	 */
	const NONCE_BYTES = 16;

	/**
	 * Initialize hooks (only when the store is connected).
	 *
	 * @since 3.34
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
	 * @since 3.34
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
	 * @since 3.34
	 * @return string
	 */
	private static function get_token() {
		$token = get_option( 'mandrakecrm_token', '' );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Signing key derived from the token: HMAC-SHA256 with key = token and message = KEY_LABEL.
	 *
	 * @since 3.34
	 * @param string $token Store token.
	 * @return string Raw 32-byte key.
	 */
	public static function derive_key( $token ) {
		return hash_hmac( 'sha256', self::KEY_LABEL, (string) $token, true );
	}

	/**
	 * Signature of a payload: base64url (no padding) of HMAC-SHA256( payload, derived key ).
	 *
	 * @since 3.34
	 * @param string $payload Payload exactly as sent (base64url).
	 * @param string $token   Store token.
	 * @return string
	 */
	public static function sign( $payload, $token ) {
		return MandrakeCRM_Agent_Identity::base64url_encode( hash_hmac( 'sha256', (string) $payload, self::derive_key( $token ), true ) );
	}

	/**
	 * The signed answer for a customer.
	 *
	 * @since 3.34
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
			'n' => MandrakeCRM_Agent_Identity::base64url_encode( $nonce_raw ),
		);

		// Compact JSON; the signature covers the base64url text, not the JSON.
		$json    = wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload = MandrakeCRM_Agent_Identity::base64url_encode( (string) $json );

		return array(
			'v' => self::FORMAT_VERSION,
			'p' => $payload,
			's' => self::sign( $payload, $token ),
		);
	}

	/**
	 * Answer with the no-cache headers of this route (and the signals of page caches: review N3).
	 *
	 * @since 3.34
	 * @param mixed $data   Body (null for none).
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function respond( $data, $status ) {
		MandrakeCRM_Agent_Identity::prevent_caching( 'mandrakecrm cashback identity' );
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * REST callback.
	 *
	 * @since 3.34
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$same_origin = MandrakeCRM_Agent_Identity::is_same_origin(
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
