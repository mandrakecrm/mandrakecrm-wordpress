<?php
/**
 * MandrakeCRM Agent Quote
 *
 * Shipping quotes made by MandrakeCRM's sales assistant. To quote shipping for a postcode, MandrakeCRM
 * uses the WooCommerce Store API from its servers (a cart with a Cart-Token, the items and the postcode;
 * WooCommerce returns the same rates as the checkout). Those carts belong to nobody, so this plugin
 * must not record them as abandoned carts.
 *
 * Each of those Store API requests carries a signed header:
 *
 *   X-Mandrake-Agent: v1.<timestamp>.<signature>
 *
 * - timestamp   = Unix time in seconds, in decimal, no sign and no leading zeros.
 * - signature   = base64url (no padding) of HMAC-SHA256( "v1.<timestamp>", derived key ): the signed
 *                 message is the header text before the second dot, exactly as sent (43 characters).
 * - derived key = HMAC-SHA256( key = store token, message = "mandrake-agent-quote-v1" ), raw 32 bytes.
 *                 In PHP: hash_hmac( 'sha256', 'mandrake-agent-quote-v1', $token, true ). The token is never
 *                 used raw, and this key differs from the signed cart links one (MandrakeCRM_Agent_Cart).
 * - The header is valid for 5 minutes around the store's clock (either side).
 *
 * A browser cannot build the header without the store token, so a real cart can never be hidden this
 * way. With a valid header the WooCommerce session is marked as a quote: from then on (also on later
 * requests of the same session) no abandoned cart row is written or updated and nothing is sent to
 * MandrakeCRM. The session of a marked request is also kept short (one hour instead of 48 hours).
 * Without the header (or with an invalid one) nothing changes.
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
 * Agent Quote class.
 *
 * @since 3.29
 */
class MandrakeCRM_Agent_Quote {

	/**
	 * Request header that marks a quote.
	 *
	 * @since 3.29
	 * @var string
	 */
	const HEADER = 'X-Mandrake-Agent';

	/**
	 * The same header as PHP exposes it in $_SERVER.
	 *
	 * @since 3.29
	 * @var string
	 */
	const SERVER_KEY = 'HTTP_X_MANDRAKE_AGENT';

	/**
	 * Label used to derive the signing key from the store token.
	 *
	 * @since 3.29
	 * @var string
	 */
	const KEY_LABEL = 'mandrake-agent-quote-v1';

	/**
	 * Header format version (first part of the header).
	 *
	 * @since 3.29
	 * @var string
	 */
	const FORMAT_VERSION = 'v1';

	/**
	 * Accepted difference between the header timestamp and the store's clock, either side (5 minutes).
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
	 * Lifetime of the WooCommerce session of a quote (1 hour instead of WooCommerce's 48 hours).
	 *
	 * @since 3.29
	 * @var int
	 */
	const SESSION_SECONDS = 3600;

	/**
	 * Session key that marks the session as a quote.
	 *
	 * @since 3.29
	 * @var string
	 */
	const SESSION_FLAG = 'mandrakecrm_agent_quote';

	/**
	 * Initialize hooks (only for a request with a valid header).
	 *
	 * @since 3.29
	 */
	public static function init() {
		if ( ! self::is_marked_request() ) {
			return;
		}

		// Short session: the Cart-Token and the session row of the quote expire in one hour.
		add_filter( 'wc_session_expiration', array( __CLASS__, 'session_expiration' ), 99 );
		add_filter( 'wc_session_expiring', array( __CLASS__, 'session_expiring' ), 99 );
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
	 * Signature of a timestamp: base64url (no padding) of HMAC-SHA256( "v1.<timestamp>", derived key ).
	 *
	 * @since 3.29
	 * @param string|int $timestamp Unix time in seconds, as written in the header.
	 * @param string     $token     Store token.
	 * @return string
	 */
	public static function sign( $timestamp, $token ) {
		$mac = hash_hmac( 'sha256', self::FORMAT_VERSION . '.' . $timestamp, self::derive_key( $token ), true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Full header value for a timestamp ("v1.<timestamp>.<signature>").
	 *
	 * @since 3.29
	 * @param int    $timestamp Unix time in seconds.
	 * @param string $token     Store token.
	 * @return string
	 */
	public static function header_value( $timestamp, $token ) {
		$timestamp = (string) (int) $timestamp;
		return self::FORMAT_VERSION . '.' . $timestamp . '.' . self::sign( $timestamp, $token );
	}

	/**
	 * Whether a header value is valid for this store now.
	 *
	 * @since 3.29
	 * @param mixed  $value Header value.
	 * @param string $token Store token.
	 * @param int    $now   Current Unix time.
	 * @return bool
	 */
	public static function verify( $value, $token, $now ) {
		if ( ! is_string( $value ) || ! is_string( $token ) || '' === $token || strlen( $value ) > self::MAX_HEADER_LENGTH ) {
			return false;
		}
		if ( ! preg_match( '/^v1\.([1-9][0-9]{0,11})\.([A-Za-z0-9_-]{43})$/', $value, $m ) ) {
			return false;
		}
		if ( abs( (int) $now - (int) $m[1] ) > self::WINDOW_SECONDS ) {
			return false;
		}
		return hash_equals( self::sign( $m[1], $token ), $m[2] );
	}

	/**
	 * Whether the current request carries a valid header.
	 *
	 * @since 3.29
	 * @return bool
	 */
	public static function is_marked_request() {
		if ( ! isset( $_SERVER[ self::SERVER_KEY ] ) || ! is_string( $_SERVER[ self::SERVER_KEY ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a strict pattern and the signature in verify().
		$value = trim( wp_unslash( $_SERVER[ self::SERVER_KEY ] ) );
		return self::verify( $value, self::get_token(), time() );
	}

	/**
	 * Whether the current WooCommerce session is a quote: this request carries a valid header (the
	 * session is marked then), or an earlier request of the same session did.
	 *
	 * @since 3.29
	 * @return bool
	 */
	public static function is_quote_session() {
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;

		if ( self::is_marked_request() ) {
			if ( $session && 1 !== (int) $session->get( self::SESSION_FLAG ) ) {
				$session->set( self::SESSION_FLAG, 1 );
			}
			return true;
		}

		return $session && 1 === (int) $session->get( self::SESSION_FLAG );
	}

	/**
	 * Lifetime of the session of a quote (filter `wc_session_expiration`).
	 *
	 * @since 3.29
	 * @param int $seconds Lifetime set by WooCommerce or other plugins.
	 * @return int
	 */
	public static function session_expiration( $seconds ) {
		return min( (int) $seconds, self::SESSION_SECONDS );
	}

	/**
	 * When WooCommerce renews the session of a quote (filter `wc_session_expiring`), before it expires.
	 *
	 * @since 3.29
	 * @param int $seconds Seconds set by WooCommerce or other plugins.
	 * @return int
	 */
	public static function session_expiring( $seconds ) {
		return min( (int) $seconds, self::SESSION_SECONDS - self::WINDOW_SECONDS );
	}
}
