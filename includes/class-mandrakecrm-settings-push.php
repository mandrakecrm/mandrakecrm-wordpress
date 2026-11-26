<?php
/**
 * MandrakeCRM Settings Push
 *
 * Signed settings notice from MandrakeCRM, server to server, so a change made in MandrakeCRM reaches the store
 * within minutes instead of waiting for the daily sync:
 *
 *   POST /wp-json/mandrakecrm/v1/settings            (or /?rest_route=/mandrakecrm/v1/settings)
 *   X-Mandrake-Settings-Signature: v1.<timestamp>.<signature>
 *   Content-Type: application/json
 *   {"store_name":"<store name in MandrakeCRM>"}
 *
 * Today it carries only the store name, shown in the checkout consent checkboxes. Other keys are ignored.
 * The answer is {"ok":true,"store_name":"<name the plugin keeps>","plugin_version":"<version>"}: an empty name
 * keeps the last known one, so the answer tells MandrakeCRM what the store really shows.
 *
 * Signature (nothing is read or changed without it; same scheme as the signed shipping quote of 3.29):
 *
 * - timestamp   = Unix time in seconds, in decimal, no sign and no leading zeros; valid 5 minutes either side.
 * - body hash   = SHA-256 of the raw request body, in lower-case hex (64 characters).
 * - signature   = base64url (no padding) of HMAC-SHA256( "v1.<timestamp>.<body hash>", derived key ): 43 characters.
 * - derived key = HMAC-SHA256( key = store token, message = "mandrake-settings-v1" ), raw 32 bytes.
 *                 In PHP: hash_hmac( 'sha256', 'mandrake-settings-v1', $token, true ). The token is never used raw,
 *                 and this key differs from the ones of the cart link, the Store API quote, the shipping quote and
 *                 the customer identity.
 * - A signature is accepted once: the same header value sent again (a replay) gets 401 too. The signature is
 *   reserved atomically (a single INSERT IGNORE of option mandrakecrm_sp_<hash>), so two copies sent at the same
 *   time cannot both pass; reservations older than twice the window are deleted on the next accepted notice.
 *
 * Errors (WordPress REST format, {"code","message","data":{"status",...}}): BODY_TOO_LARGE (413, body over 2 KB,
 * measured before the signature is checked, so a large body is never hashed or applied), INVALID_SIGNATURE (401:
 * missing, malformed, wrong, expired or already used; data.reason), INVALID_INPUT (400, data.field).
 * The 2 KB limit is on what this route processes, not on what the server reads: WordPress has already read the
 * body and decoded a JSON one before the permission check runs, so the real limit on that is the web server's
 * and PHP's (post_max_size).
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      3.32
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings Push class.
 *
 * @since 3.32
 */
class MandrakeCRM_Settings_Push {

	/**
	 * REST namespace.
	 *
	 * @since 3.32
	 * @var string
	 */
	const REST_NAMESPACE = 'mandrakecrm/v1';

	/**
	 * REST route.
	 *
	 * @since 3.32
	 * @var string
	 */
	const REST_ROUTE = '/settings';

	/**
	 * Request header with the signature.
	 *
	 * @since 3.32
	 * @var string
	 */
	const HEADER = 'X-Mandrake-Settings-Signature';

	/**
	 * Label used to derive the signing key from the store token.
	 *
	 * @since 3.32
	 * @var string
	 */
	const KEY_LABEL = 'mandrake-settings-v1';

	/**
	 * Signature format version (first part of the header).
	 *
	 * @since 3.32
	 * @var string
	 */
	const FORMAT_VERSION = 'v1';

	/**
	 * Accepted difference between the timestamp and the store's clock, either side (5 minutes).
	 *
	 * @since 3.32
	 * @var int
	 */
	const WINDOW_SECONDS = 300;

	/**
	 * Maximum length of the header value.
	 *
	 * @since 3.32
	 * @var int
	 */
	const MAX_HEADER_LENGTH = 64;

	/**
	 * Maximum size of the request body, in bytes.
	 *
	 * @since 3.32
	 * @var int
	 */
	const MAX_BODY_BYTES = 2048;

	/**
	 * Option prefix of the signatures already used (kept while they could still be valid). The option value is the
	 * Unix time when the reservation expires.
	 *
	 * @since 3.32
	 * @var string
	 */
	const USED_PREFIX = 'mandrakecrm_sp_';

	/**
	 * Most expired reservations deleted on each accepted notice.
	 *
	 * @since 3.32
	 * @var int
	 */
	const CLEANUP_BATCH = 50;

	/**
	 * Initialize hooks (only when the store is connected).
	 *
	 * @since 3.32
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
	 * @since 3.32
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
	 * @since 3.32
	 * @return string
	 */
	private static function get_token() {
		$token = get_option( 'mandrakecrm_token', '' );
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Signing key derived from the token: HMAC-SHA256 with key = token and message = KEY_LABEL.
	 *
	 * @since 3.32
	 * @param string $token Store token.
	 * @return string Raw 32-byte key.
	 */
	public static function derive_key( $token ) {
		return hash_hmac( 'sha256', self::KEY_LABEL, (string) $token, true );
	}

	/**
	 * Signature: base64url (no padding) of HMAC-SHA256( "v1.<timestamp>.<sha256 hex of the body>", derived key ).
	 *
	 * @since 3.32
	 * @param string|int $timestamp Unix time in seconds, as written in the header.
	 * @param string     $body      Raw request body.
	 * @param string     $token     Store token.
	 * @return string
	 */
	public static function sign( $timestamp, $body, $token ) {
		$message = self::FORMAT_VERSION . '.' . $timestamp . '.' . hash( 'sha256', (string) $body );
		$mac     = hash_hmac( 'sha256', $message, self::derive_key( $token ), true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Full header value ("v1.<timestamp>.<signature>").
	 *
	 * @since 3.32
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
	 * Why a header value does not sign this body for this store now ('' when it does).
	 *
	 * @since 3.32
	 * @param mixed  $value Header value.
	 * @param mixed  $body  Raw request body.
	 * @param string $token Store token.
	 * @param int    $now   Current Unix time.
	 * @return string '' | 'missing' | 'malformed' | 'expired' | 'mismatch'
	 */
	public static function verify( $value, $body, $token, $now ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 'missing';
		}
		if ( ! is_string( $body ) || ! is_string( $token ) || '' === $token ) {
			return 'mismatch';
		}
		$value = trim( $value );
		if ( strlen( $value ) > self::MAX_HEADER_LENGTH || ! preg_match( '/^v1\.([1-9][0-9]{0,11})\.([A-Za-z0-9_-]{43})$/', $value, $m ) ) {
			return 'malformed';
		}
		if ( abs( (int) $now - (int) $m[1] ) > self::WINDOW_SECONDS ) {
			return 'expired';
		}
		return hash_equals( self::sign( $m[1], $body, $token ), $m[2] ) ? '' : 'mismatch';
	}

	/**
	 * Option name that marks a signature as used.
	 *
	 * @since 3.32
	 * @param string $value Header value.
	 * @return string
	 */
	private static function used_key( $value ) {
		return self::USED_PREFIX . substr( hash( 'sha256', trim( (string) $value ) ), 0, 40 );
	}

	/**
	 * Permission callback: body size, then the signature, before anything else is read.
	 *
	 * @since 3.32
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		$body = $request->get_body();
		if ( strlen( (string) $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'BODY_TOO_LARGE', 'Body must be at most 2 KB.', array( 'status' => 413 ) );
		}
		$value  = $request->get_header( self::HEADER );
		$now    = time();
		$reason = self::verify( $value, $body, self::get_token(), $now );
		if ( '' === $reason && ! self::reserve( self::used_key( $value ), $now ) ) {
			$reason = 'replayed';
		}
		if ( '' !== $reason ) {
			return new WP_Error(
				'INVALID_SIGNATURE',
				'Missing, invalid, expired or already used signature.',
				array(
					'status' => 401,
					'reason' => $reason,
				)
			);
		}
		self::delete_expired( $now );
		return true;
	}

	/**
	 * Reserve a signature, atomically: true only for the first request that uses it.
	 *
	 * A single INSERT IGNORE on the unique option_name: of two requests with the same signature at the same time,
	 * exactly one inserts the row. get_transient() + set_transient() would let both through, and add_option()
	 * cannot tell (it writes with ON DUPLICATE KEY UPDATE). The row is never autoloaded and lives twice the window,
	 * which covers both sides of the timestamp.
	 *
	 * @since 3.32
	 * @param string $key Option name (used_key()).
	 * @param int    $now Current Unix time.
	 * @return bool
	 */
	private static function reserve( $key, $now ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic insert-if-absent; the row is never read through the options API.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$key,
				(string) ( (int) $now + 2 * self::WINDOW_SECONDS )
			)
		);
		return 1 === (int) $inserted;
	}

	/**
	 * Delete the reservations that expired (at most CLEANUP_BATCH per accepted notice), so they never pile up.
	 *
	 * @since 3.32
	 * @param int $now Current Unix time.
	 */
	private static function delete_expired( $now ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- rows written by reserve(), never cached.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
				$wpdb->esc_like( self::USED_PREFIX ) . '%',
				(int) $now,
				self::CLEANUP_BATCH
			)
		);
	}

	/**
	 * INVALID_INPUT error (400) naming the field.
	 *
	 * @since 3.32
	 * @param string $message Message (English, for logs).
	 * @param string $field   Field.
	 * @return WP_Error
	 */
	private static function invalid_input( $message, $field ) {
		return new WP_Error(
			'INVALID_INPUT',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * Apply the notice: today, the store name shown at checkout.
	 *
	 * @since 3.32
	 * @param WP_REST_Request $request Request (already verified).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( $request ) {
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) || array_values( $data ) === $data ) {
			return self::invalid_input( 'Body must be a JSON object.', 'body' );
		}
		if ( ! isset( $data['store_name'] ) || ! is_string( $data['store_name'] ) ) {
			return self::invalid_input( 'store_name must be a string.', 'store_name' );
		}

		// Same path as the token verification and the status sync: an empty name keeps the last known one.
		MandrakeCRM_Checkout::update_store_name( array( 'store_name' => $data['store_name'] ) );

		$response = new WP_REST_Response(
			array(
				'ok'             => true,
				'store_name'     => (string) get_option( MandrakeCRM_Checkout::STORE_NAME_OPTION, '' ),
				'plugin_version' => MANDRAKECRM_VERSION,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
