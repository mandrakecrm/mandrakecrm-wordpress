<?php
/**
 * MandrakeCRM Agent Shipping Session
 *
 * An in-memory WooCommerce session for the signed shipping quote endpoint (MandrakeCRM_Agent_Shipping).
 * WooCommerce's shipping engine and many shipping methods read and write WC()->session (cached rates,
 * chosen methods, notices); a REST request has no session, so the quote lends them this one for the
 * duration of the calculation. Nothing is stored: no database row, no cookie, no expiry.
 *
 * Loaded only by the endpoint, when WooCommerce (and WC_Session) is already loaded.
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
 * In-memory session (never saved).
 *
 * @since 3.29
 */
class MandrakeCRM_Agent_Shipping_Session extends WC_Session {

	/**
	 * Start empty, with a guest id that matches no real session.
	 *
	 * @since 3.29
	 */
	public function __construct() {
		$this->_customer_id = 't_mcrm_quote';
		$this->_data        = array();
		$this->_dirty       = false;
	}

	/**
	 * Same answer as a visitor without a session cookie.
	 *
	 * @since 3.29
	 * @return bool
	 */
	public function has_session() {
		return false;
	}

	/**
	 * No cookie is ever set.
	 *
	 * @since 3.29
	 * @param bool $set Ignored.
	 */
	public function set_customer_session_cookie( $set ) {
		unset( $set );
	}

	/**
	 * No session cookie.
	 *
	 * @since 3.29
	 * @return bool
	 */
	public function get_session_cookie() {
		return false;
	}

	/**
	 * Nothing is saved.
	 *
	 * @since 3.29
	 * @param int $old_session_key Ignored.
	 */
	public function save_data( $old_session_key = 0 ) {
		unset( $old_session_key );
	}

	/**
	 * Forgets the in-memory data.
	 *
	 * @since 3.29
	 */
	public function forget_session() {
		$this->_data = array();
	}

	/**
	 * Forgets the in-memory data.
	 *
	 * @since 3.29
	 */
	public function destroy_session() {
		$this->_data = array();
	}
}
