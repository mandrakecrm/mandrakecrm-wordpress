<?php
/**
 * MandrakeCRM Coupon Check
 *
 * WooCommerce's own coupon checks that do not depend on the cart's contents (the coupon exists, is not used up, has not
 * expired and, when an email is known, belongs to it). The coupon link uses them to tell a coupon that cannot be used at
 * all (WooCommerce shows its error at once) from one that only waits for a product or for the customer's email.
 * WooCommerce still decides: these are its validators, run on the current cart.
 *
 * Loaded only when WooCommerce's WC_Discounts exists (see MandrakeCRM_Coupon_Link::blocking_error()).
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

/**
 * Coupon Check class.
 *
 * @since 3.34
 */
class MandrakeCRM_Coupon_Check extends WC_Discounts {

	/**
	 * The error of the first check that does not depend on the cart's contents, or null when they all pass.
	 *
	 * @since 3.34
	 * @param WC_Coupon $coupon      Coupon.
	 * @param bool      $email_known Whether an email of the customer is known (the account's or the billing one).
	 * @return string|null
	 */
	public function blocking_error( $coupon, $email_known = false ) {
		$checks = array( 'validate_coupon_exists', 'validate_coupon_usage_limit', 'validate_coupon_user_usage_limit', 'validate_coupon_expiry_date' );
		if ( $email_known ) {
			// Without an email WooCommerce rejects any coupon restricted to one: that case waits instead (see the coupon link).
			$checks[] = 'validate_coupon_allowed_emails';
		}
		try {
			foreach ( $checks as $check ) {
				if ( method_exists( $this, $check ) ) {
					$this->$check( $coupon );
				}
			}
		} catch ( Exception $e ) {
			$message = $e->getMessage();
			return ( is_string( $message ) && '' !== $message ) ? $message : 'invalid';
		}
		return null;
	}
}
