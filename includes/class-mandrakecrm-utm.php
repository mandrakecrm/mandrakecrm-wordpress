<?php
/**
 * MandrakeCRM UTM Tracking
 *
 * Handles UTM parameter tracking for orders.
 *
 * @package    MandrakeCRM
 * @subpackage MandrakeCRM/includes
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * UTM Tracking class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_UTM {

	/**
	 * UTM parameters to track.
	 *
	 * Only includes parameters processed by backend for conversion tracking.
	 * Excludes ad platform IDs (gclid, fbclid, utm_id) as they are not used.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const UTM_PARAMS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
	);

	/**
	 * Cookie name for UTM data.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const COOKIE_NAME = 'mandrakecrm_utm';

	/**
	 * Cookie expiration in days.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const COOKIE_EXPIRY_DAYS = 30;

	/**
	 * Maximum length accepted for a single UTM value.
	 *
	 * The cookie is written by the browser, so values are untrusted and are capped
	 * on both sides: when writing it in JavaScript and when reading it back in PHP.
	 *
	 * @since 3.20.0
	 * @var int
	 */
	const VALUE_MAX_LENGTH = 200;

	/**
	 * Maximum size of the cookie value, in bytes.
	 *
	 * The cookie travels on every request, so an oversized payload is dropped
	 * instead of being stored.
	 *
	 * @since 3.20.0
	 * @var int
	 */
	const COOKIE_MAX_BYTES = 1500;

	/**
	 * Initialize UTM tracking.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$token = get_option( 'mandrakecrm_token', '' );

		// Do not load UTM tracking if no valid token
		if ( empty( $token ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'capture_utm_params' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_capture_script' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_utm_to_order' ), 10, 2 );

		// Block checkout. `woocommerce_checkout_create_order` lives only in
		// class-wc-checkout.php, so it never fires for orders placed through the
		// Store API: those are built with `new \WC_Order()` in
		// StoreApi/Utilities/OrderController.php and stamped `created_via=store-api`.
		// Without this hook a store on the block checkout records no attribution at
		// all. This action is WooCommerce's documented counterpart for adding meta.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'save_utm_to_block_order' ), 10, 1 );

		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'display_utm_in_admin' ) );
	}

	/**
	 * Capture UTM parameters from URL.
	 *
	 * @since 2.0.0
	 */
	public static function capture_utm_params() {
		if ( is_admin() ) {
			return;
		}

		$utm_data = array();
		$has_utm  = false;

		foreach ( self::UTM_PARAMS as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) && ! empty( $_GET[ $param ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$utm_data[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
				$has_utm            = true;
			}
		}

		if ( $has_utm ) {
			$existing_utm = self::get_utm_from_cookie();
			$merged_utm   = array_merge( $existing_utm, $utm_data );

			$merged_utm['captured_at'] = current_time( 'mysql' );

			$expiry = time() + ( self::COOKIE_EXPIRY_DAYS * DAY_IN_SECONDS );

			setcookie(
				self::COOKIE_NAME,
				wp_json_encode( $merged_utm ),
				array(
					'expires'  => $expiry,
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					// Readable by JavaScript on purpose (was HttpOnly until 3.20).
					// print_capture_script() writes this same cookie in the browser to
					// cover cached page views; with HttpOnly the browser rejects that
					// write, so a visitor who once got a server-set cookie would keep
					// a stale campaign forever. The cookie holds campaign parameters
					// only — no credentials or personal data. Same trade-off
					// WooCommerce makes with its sbjs_* attribution cookies.
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);

			$_COOKIE[ self::COOKIE_NAME ] = wp_json_encode( $merged_utm );
		}
	}

	/**
	 * Print the browser-side UTM capture script.
	 *
	 * Server-side capture runs on `init`, which never fires when a full-page cache
	 * serves a HIT. A visitor landing on an already cached page with `?utm_source=...`
	 * therefore produced no cookie and their order lost its attribution. Measured on a
	 * production store: only 70% of campaign orders were attributed.
	 *
	 * This script performs the same capture in the browser, writing the SAME cookie,
	 * with the same JSON shape and the same merge semantics, so `save_utm_to_order()`
	 * and the `_mandrakecrm_utm_*` order meta are unchanged.
	 *
	 * Server-side capture is deliberately KEPT rather than replaced. Its `Set-Cookie`
	 * header is what stops a page cache from storing a UTM landing page. Without it
	 * that page gets stored, and because cache keys commonly ignore the query string,
	 * its internal links — which WooCommerce builds on the current URL — would hand
	 * every later visitor someone else's campaign parameters.
	 *
	 * @since 3.20.0
	 */
	public static function print_capture_script() {
		if ( is_admin() ) {
			return;
		}

		$config = array(
			'name'   => self::COOKIE_NAME,
			'params' => self::UTM_PARAMS,
			'days'   => self::COOKIE_EXPIRY_DAYS,
			'max'    => self::VALUE_MAX_LENGTH,
			'bytes'  => self::COOKIE_MAX_BYTES,
			// Mirror the setcookie() arguments used by capture_utm_params(), so that
			// JavaScript and PHP write one cookie instead of two.
			'path'   => COOKIEPATH ? COOKIEPATH : '/',
			'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure' => is_ssl(),
			// Reproduces the format of current_time( 'mysql' ) in the browser.
			'offset' => (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ),
		);

		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		if ( false === $json ) {
			return;
		}

		$script = '(function(c){try{' .
			'if(!window.location.search){return;}' .
			'var q=new URLSearchParams(window.location.search),fresh={},found=false,i,k,v;' .
			'for(i=0;i<c.params.length;i++){k=c.params[i];v=q.get(k);' .
			'if(v){fresh[k]=String(v).slice(0,c.max);found=true;}}' .
			'if(!found){return;}' .
			'var data={},m=document.cookie.match(new RegExp("(?:^|; )"+c.name+"=([^;]*)"));' .
			'if(m){try{var p=JSON.parse(decodeURIComponent(m[1]));' .
			'if(p&&typeof p==="object"&&!Array.isArray(p)){data=p;}}catch(e){}}' .
			'for(k in fresh){if(Object.prototype.hasOwnProperty.call(fresh,k)){data[k]=fresh[k];}}' .
			'data.captured_at=new Date(Date.now()+c.offset*1000).toISOString().slice(0,19).replace("T"," ");' .
			'var val=encodeURIComponent(JSON.stringify(data));' .
			'if(val.length>c.bytes){return;}' .
			'var s=c.name+"="+val+"; max-age="+(c.days*86400)+"; path="+(c.path||"/")+"; samesite=Lax";' .
			'if(c.domain){s+="; domain="+c.domain;}' .
			'if(c.secure){s+="; secure";}' .
			'document.cookie=s;' .
			'}catch(e){}})(' . $json . ');';

		wp_print_inline_script_tag( $script, array( 'id' => 'mandrakecrm-utm-js' ) );
	}

	/**
	 * Get UTM data from cookie.
	 *
	 * @since 2.0.0
	 * @since 3.20.0 Keeps only known keys and caps value length: the cookie is now
	 *               also written by the browser, and its raw contents are stored on
	 *               the order as `_mandrakecrm_utm_data`.
	 * @return array UTM data.
	 */
	public static function get_utm_from_cookie() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$data = json_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ), true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$allowed = array_merge( self::UTM_PARAMS, array( 'captured_at' ) );
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}

			$value = substr( (string) $data[ $key ], 0, self::VALUE_MAX_LENGTH );

			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Save UTM data to an order placed through the block checkout.
	 *
	 * The Store API never fires `woocommerce_checkout_create_order`, so without this
	 * bridge a store using the block checkout records no attribution at all. The
	 * order may already exist in the database at this point, so its meta is flushed
	 * explicitly.
	 *
	 * @since 3.21.0
	 * @param WC_Order $order Order object.
	 */
	public static function save_utm_to_block_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		self::save_utm_to_order( $order, array() );

		if ( $order->get_id() ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Save UTM data to order.
	 *
	 * @since 2.0.0
	 * @since 3.21.0 Bails when the order already carries attribution, so that the
	 *               classic and Store API entry points cannot stamp it twice.
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public static function save_utm_to_order( $order, $data ) {
		if ( $order->get_meta( '_mandrakecrm_utm_data' ) ) {
			return;
		}

		$utm_data = self::get_utm_from_cookie();

		if ( empty( $utm_data ) ) {
			return;
		}

		foreach ( self::UTM_PARAMS as $param ) {
			if ( ! empty( $utm_data[ $param ] ) ) {
				$order->update_meta_data( '_mandrakecrm_' . $param, sanitize_text_field( $utm_data[ $param ] ) );
			}
		}

		$order->update_meta_data( '_mandrakecrm_utm_data', wp_json_encode( $utm_data ) );

		if ( ! empty( $utm_data['captured_at'] ) ) {
			$order->update_meta_data( '_mandrakecrm_utm_captured_at', sanitize_text_field( $utm_data['captured_at'] ) );
		}
	}

	/**
	 * Display UTM data in admin order page.
	 *
	 * @since 2.0.0
	 * @param WC_Order $order Order object.
	 */
	public static function display_utm_in_admin( $order ) {
		$utm_json = $order->get_meta( '_mandrakecrm_utm_data' );

		if ( empty( $utm_json ) ) {
			return;
		}

		$utm_data = json_decode( $utm_json, true );

		if ( empty( $utm_data ) ) {
			return;
		}

		?>
		<div class="mandrakecrm-utm-data" style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-left: 4px solid #6366f1;">
			<h4 style="margin: 0 0 10px 0; color: #1e293b;">
				<span class="dashicons dashicons-chart-bar" style="color: #6366f1;"></span>
				<?php esc_html_e('MandrakeCRM UTM Tracking', 'mandrakecrm' ); ?>
			</h4>
			<table style="width: 100%; border-collapse: collapse;">
				<?php foreach ( self::UTM_PARAMS as $param ) : ?>
					<?php if ( ! empty( $utm_data[ $param ] ) ) : ?>
						<tr>
							<td style="padding: 4px 8px 4px 0; font-weight: 500; color: #64748b; width: 120px;">
								<?php echo esc_html( $param ); ?>
							</td>
							<td style="padding: 4px 0; color: #1e293b;">
								<?php echo esc_html( $utm_data[ $param ] ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( ! empty( $utm_data['captured_at'] ) ) : ?>
					<tr>
						<td style="padding: 4px 8px 4px 0; font-weight: 500; color: #64748b; width: 120px;">
							<?php esc_html_e('Captured', 'mandrakecrm' ); ?>
						</td>
						<td style="padding: 4px 0; color: #1e293b;">
							<?php echo esc_html( $utm_data['captured_at'] ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Get UTM data for an order.
	 *
	 * @since 2.0.0
	 * @param int|WC_Order $order Order ID or object.
	 * @return array UTM data.
	 */
	public static function get_order_utm_data( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return array();
		}

		$utm_json = $order->get_meta( '_mandrakecrm_utm_data' );

		if ( empty( $utm_json ) ) {
			return array();
		}

		return json_decode( $utm_json, true ) ?: array();
	}
}
