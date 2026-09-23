<?php
/**
 * MandrakeCRM Cashback — Auto-apply redemption coupons.
 *
 * Detecta el query parameter `?apply_coupon=CB-XXXXXXXX` en cualquier pageview
 * y aplica el cupón automáticamente al carrito del cliente. Útil para que el
 * deep-link del widget de cashback ("Aplicar agora") aterrice al cliente
 * directamente en `/carrinho/` con el cupón ya activo, sin tener que pegar el
 * código manualmente.
 *
 * Seguridad:
 *  - Solo aplica códigos que matchean `^CB-[A-Z0-9]{8}$` (formato de
 *    MandrakeCRM cashback). Otros cupones se ignoran silenciosamente.
 *  - El cupón en sí tiene `email_restrictions` enforced por WC nativo, así
 *    que aunque alguien comparta el código, solo el cliente correcto puede
 *    usarlo en checkout.
 *  - Idempotente: si el cupón ya está aplicado al cart, no lo duplica.
 *
 * @package MandrakeCRM
 * @since   3.17.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MandrakeCRM_Cashback
 */
class MandrakeCRM_Cashback {

	/**
	 * Regex of accepted cashback coupon codes (MandrakeCRM redemption format).
	 */
	const COUPON_PATTERN = '/^CB-[A-Z0-9]{8}$/';

	/**
	 * Query parameter listened to. Falls back to `coupon_code` for backwards
	 * compatibility with merchants who used that name.
	 */
	const QUERY_PARAMS = array( 'apply_coupon', 'coupon_code', 'cb_coupon' );

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Hook at template_redirect (after WC has loaded the cart session).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_apply_coupon_from_url' ), 20 );
	}

	/**
	 * Detect the coupon code in the URL and apply it to the cart.
	 *
	 * Triggers on any page load. WC will display its own notice (success or
	 * error) via `wc_print_notices()` so the customer sees feedback.
	 */
	public static function maybe_apply_coupon_from_url() {
		// Skip on admin / AJAX / REST contexts.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// WC must be loaded and the cart must be available.
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}

		$code = self::pick_coupon_code_from_query();
		if ( null === $code ) {
			return;
		}

		// Only act on codes that look like MandrakeCRM cashback coupons.
		if ( ! preg_match( self::COUPON_PATTERN, strtoupper( $code ) ) ) {
			return;
		}

		// Normalize to lowercase (WC stores codes lowercase).
		$code_normalized = strtolower( $code );

		// Idempotent: if the cart already has this coupon applied, redirect
		// silently to the cart page so the customer sees the state.
		if ( WC()->cart->has_discount( $code_normalized ) ) {
			self::redirect_to_cart();
			return;
		}

		// Apply. WC's apply_coupon() validates email_restrictions, usage_limit,
		// expiration, individual_use, etc. — we just delegate.
		WC()->cart->apply_coupon( $code_normalized );

		// Redirect to cart to drop the query param + show the WC notice.
		self::redirect_to_cart();
	}

	/**
	 * Look at the listed query parameters and return the first non-empty value.
	 *
	 * @return string|null
	 */
	private static function pick_coupon_code_from_query() {
		foreach ( self::QUERY_PARAMS as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query string check.
			if ( isset( $_GET[ $param ] ) && '' !== trim( wp_unslash( $_GET[ $param ] ) ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			}
		}
		return null;
	}

	/**
	 * Redirect to the cart page, stripping the apply_coupon query param so
	 * a refresh doesn't try to re-apply.
	 */
	private static function redirect_to_cart() {
		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
		wp_safe_redirect( $cart_url );
		exit;
	}
}
