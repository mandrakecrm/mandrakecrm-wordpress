<?php
/**
 * MandrakeCRM Checkout
 *
 * Handles marketing opt-in functionality at checkout: the email checkbox and,
 * since 3.25, a separate WhatsApp checkbox, both in the classic and the block checkout.
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

require_once __DIR__ . '/class-mandrakecrm-i18n.php';

/**
 * Checkout class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_Checkout {

	/**
	 * Order meta key for the email marketing opt-in ('yes' or 'no'), the key the MandrakeCRM API reads.
	 *
	 * Saved only when the checkbox was shown. Before 3.25 the plugin saved '_mandrakecrm_marketing_optin'
	 * (LEGACY_META_KEY), which the API never read: those orders stay without effect on purpose.
	 *
	 * @since 2.0.0
	 * @since 3.25 '_mandrakecrm_marketing_opt_in'.
	 * @var string
	 */
	const META_KEY = '_mandrakecrm_marketing_opt_in';

	/**
	 * Order meta key used before 3.25 (only read, to show older orders in the admin).
	 *
	 * @since 3.25
	 * @var string
	 */
	const LEGACY_META_KEY = '_mandrakecrm_marketing_optin';

	/**
	 * Order meta key: whether the email checkbox started ticked ('yes' or 'no').
	 *
	 * Lets MandrakeCRM tell a box left ticked (legitimate interest) from a box the customer ticked.
	 *
	 * @since 3.25
	 * @var string
	 */
	const DEFAULT_META_KEY = '_mandrakecrm_marketing_opt_in_default';

	/**
	 * Additional checkout field id of the email opt-in (block checkout).
	 *
	 * @since 3.25
	 * @var string
	 */
	const MARKETING_FIELD_ID = 'mandrakecrm/marketing-optin';

	/**
	 * Order meta key for the WhatsApp opt-in ('yes' or 'no').
	 *
	 * @since 3.25
	 * @var string
	 */
	const WHATSAPP_META_KEY = '_mandrakecrm_whatsapp_opt_in';

	/**
	 * Additional checkout field id of the WhatsApp opt-in (block checkout).
	 *
	 * @since 3.25
	 * @var string
	 */
	const WHATSAPP_FIELD_ID = 'mandrakecrm/whatsapp-optin';

	/**
	 * Option: show the WhatsApp checkbox at checkout ('1' by default).
	 *
	 * @since 3.25
	 * @var string
	 */
	const WHATSAPP_OPTION = 'mandrakecrm_whatsapp_optin';

	/**
	 * Option: the store name in MandrakeCRM, as last reported by the MandrakeCRM API.
	 *
	 * @since 3.25
	 * @var string
	 */
	const STORE_NAME_OPTION = 'mandrakecrm_store_name';

	/**
	 * Option: Unix time of the last answer of the MandrakeCRM API with the store name (status sync, token
	 * verification or signed settings notice).
	 *
	 * @since 3.32
	 * @var string
	 */
	const STORE_NAME_CHECKED_OPTION = 'mandrakecrm_store_name_checked_at';

	/**
	 * Transient that spaces out the background store name refreshes while the API does not answer.
	 *
	 * @since 3.32
	 * @var string
	 */
	const STORE_NAME_REFRESH_WAIT = 'mandrakecrm_store_name_refresh_wait';

	/**
	 * Age of the store name after which a checkout page load schedules a background refresh (1 hour).
	 *
	 * @since 3.32
	 * @var int
	 */
	const STORE_NAME_MAX_AGE = 3600;

	/**
	 * Order meta key: whether the WhatsApp checkbox started ticked ('yes' or 'no').
	 *
	 * @since 3.32
	 * @var string
	 */
	const WHATSAPP_DEFAULT_META_KEY = '_mandrakecrm_whatsapp_opt_in_default';

	/**
	 * Order meta key: exact label of the email checkbox shown to the customer.
	 *
	 * @since 3.32
	 * @var string
	 */
	const MARKETING_LABEL_META_KEY = '_mandrakecrm_marketing_opt_in_label';

	/**
	 * Order meta key: exact label of the WhatsApp checkbox shown to the customer.
	 *
	 * @since 3.32
	 * @var string
	 */
	const WHATSAPP_LABEL_META_KEY = '_mandrakecrm_whatsapp_opt_in_label';

	/**
	 * Order meta key: locale the checkout was shown in, the one of the saved labels (see checkout_locale()).
	 *
	 * @since 3.32
	 * @var string
	 */
	const LOCALE_META_KEY = '_mandrakecrm_opt_in_locale';

	/**
	 * Order meta key: store name used in the labels.
	 *
	 * @since 3.32
	 * @var string
	 */
	const STORE_NAME_META_KEY = '_mandrakecrm_opt_in_store_name';

	/**
	 * Order meta key: version of this plugin when the order was placed.
	 *
	 * @since 3.32
	 * @var string
	 */
	const PLUGIN_VERSION_META_KEY = '_mandrakecrm_opt_in_plugin_version';

	/**
	 * Order meta key: checkout that showed the checkboxes ('classic' or 'blocks').
	 *
	 * @since 3.32
	 * @var string
	 */
	const CHECKOUT_TYPE_META_KEY = '_mandrakecrm_opt_in_checkout';

	/**
	 * Order meta key: who made the last change of an answer on a placed order ('admin': the store owner in the
	 * WooCommerce order screen; 'buyer': the customer, paying or retrying a pending order). Absent while no answer
	 * changed after the order was placed.
	 *
	 * @since 3.32
	 * @var string
	 */
	const EDIT_SOURCE_META_KEY = '_mandrakecrm_opt_in_edit_source';

	/**
	 * Order meta key: when that change was made (ISO 8601, UTC).
	 *
	 * @since 3.32
	 * @var string
	 */
	const EDITED_AT_META_KEY = '_mandrakecrm_opt_in_edited_at';

	/**
	 * Longest label kept in the order meta (characters).
	 *
	 * @since 3.32
	 * @var int
	 */
	const MAX_LABEL_LENGTH = 1000;

	/**
	 * Longest store name kept in the order meta (characters).
	 *
	 * @since 3.32
	 * @var int
	 */
	const MAX_STORE_NAME_LENGTH = 200;

	/**
	 * WooCommerce session key with the customer's last choice on the classic checkboxes ('yes' or 'no' per checkbox name).
	 *
	 * Written in WooCommerce's own checkout requests (the order review refresh and the checkout post), after their nonce.
	 *
	 * @since 3.32
	 * @var string
	 */
	const SESSION_CHOICE_KEY = 'mandrakecrm_optin_choice';

	/**
	 * Cookie that checkout-optin.js writes on each click on the checkboxes («e1w0»: e-mail ticked, WhatsApp unticked; a missing
	 * letter is no choice; path /). Only the customer's own clicks, no identifier: it only decides how the boxes are drawn. Lasts
	 * CHOICE_COOKIE_MAX_AGE and is removed when the cart is emptied (forget_choice()).
	 *
	 * @since 3.32
	 * @var string
	 */
	const CHOICE_COOKIE = 'mandrakecrm_optin';

	/**
	 * Lifetime of CHOICE_COOKIE in seconds (2 hours, as checkout-optin.js writes it).
	 *
	 * @since 3.32
	 * @var int
	 */
	const CHOICE_COOKIE_MAX_AGE = 7200;

	/**
	 * True when the cart was emptied after the page had started (headers already sent): the cookie is removed in the browser
	 * (print_forget_choice_script()).
	 *
	 * @since 3.32
	 * @var bool
	 */
	private static $forget_in_browser = false;

	/**
	 * Script handle of checkout-optin.js.
	 *
	 * @since 3.32
	 * @var string
	 */
	const OPTIN_SCRIPT_HANDLE = 'mandrakecrm-checkout-optin';

	/**
	 * File name of checkout-optin.js, as the optimizers' exclusion lists match it.
	 *
	 * @since 3.32
	 * @var string
	 */
	const OPTIN_SCRIPT_FILE = 'mandrakecrm/assets/js/checkout-optin.js';

	/**
	 * The Store API checkout request being served (see remember_checkout_request()).
	 *
	 * @since 3.32
	 * @var WP_REST_Request|null
	 */
	private static $checkout_request = null;

	/**
	 * Id of the order the classic checkout resumes in this request (WooCommerce's woocommerce_resume_order), 0 when none.
	 *
	 * @since 3.32
	 * @var int
	 */
	private static $resumed_order_id = 0;

	/**
	 * The checkboxes that order already had an answer for ('yes' or 'no') when WooCommerce resumed it, as answer meta key => true.
	 *
	 * @since 3.32 One flag for both checkboxes.
	 * @since 3.33 Per checkbox.
	 * @var array
	 */
	private static $resumed_order_answered = array();

	/**
	 * Email checkbox label with the store name (msgid; also used to find the translation bundled with the plugin).
	 *
	 * @since 3.32
	 * @since 3.33 «I want to receive…», the same form as the WhatsApp one (spec 202, JA-11).
	 * @var string
	 */
	const MARKETING_LABEL_FORMAT = 'I want to receive promotions, news and exclusive offers from %s by email.';

	/**
	 * Email checkbox label without a store name (msgid).
	 *
	 * @since 3.33
	 * @var string
	 */
	const MARKETING_LABEL_PLAIN = 'I want to receive promotions, news and exclusive offers by email.';

	/**
	 * WhatsApp checkbox label with the store name (msgid; the last fallback when a translation is not a valid format).
	 *
	 * @since 3.32
	 * @since 3.33 «I want to receive…» (spec 202, JA-11).
	 * @var string
	 */
	const WHATSAPP_LABEL_FORMAT = 'I want to receive promotions, news and exclusive offers from %s on WhatsApp.';

	/**
	 * WhatsApp checkbox label without a store name (msgid).
	 *
	 * @since 3.33
	 * @var string
	 */
	const WHATSAPP_LABEL_PLAIN = 'I want to receive promotions, news and exclusive offers on WhatsApp.';

	/**
	 * Initialize checkout hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		$token = get_option( 'mandrakecrm_token', '' );

		// Do not load marketing opt-in if no valid token
		if ( empty( $token ) ) {
			return;
		}

		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'add_marketing_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_marketing_optin' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'display_optin_in_admin' ), 5 );

		// WhatsApp opt-in (3.25): classic checkout.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'add_whatsapp_checkbox' ), 11 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_whatsapp_optin' ), 10, 2 );

		// Proof of each answer (3.32): exact labels, locale, store name, plugin version and checkout type.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic_proof' ), 20 );
		// A payment retry on the order already placed (3.32): WooCommerce's own signal, see is_resumed_classic_order().
		add_action( 'woocommerce_resume_order', array( __CLASS__, 'remember_resumed_order' ) );

		// The customer's choice on the checkboxes (3.32): kept in the WooCommerce session (classic) and in a short-lived
		// cookie (classic and blocks), so a full page load (F5, back from the cart, reload_checkout of a gateway) draws them as
		// the customer left them.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_script' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_choice_from_review' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'remember_choice_from_checkout' ) );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'forget_choice' ) );

		// checkout-optin.js on time with optimizers that delay or combine scripts (3.32).
		add_filter( 'script_loader_tag', array( __CLASS__, 'optin_script_tag' ), 10, 2 );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'exclude_optin_script' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'exclude_optin_script' ) );
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'exclude_optin_script' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( __CLASS__, 'exclude_optin_script' ) );
		add_filter( 'litespeed_optimize_js_excludes', array( __CLASS__, 'exclude_optin_script' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( __CLASS__, 'exclude_optin_script_csv' ) );

		// Store name older than an hour (3.32): refresh it in the background when a checkout page loads.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_refresh_store_name_on_checkout' ) );

		// Email and WhatsApp opt-in (3.25): block checkout, through the Additional Checkout Fields API.
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_fields' ) );
		add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'save_block_field' ), 10, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'require_block_answers' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'remember_checkout_request' ), 10, 3 );
		// The store owner's edit of the checkboxes in the order screen (3.32): the screen shows the answer MandrakeCRM keeps.
		add_filter( 'woocommerce_admin_shipping_fields', array( __CLASS__, 'admin_order_field_values' ), 20, 3 );
		add_filter( 'woocommerce_get_default_value_for_' . self::MARKETING_FIELD_ID, array( __CLASS__, 'marketing_block_field_default' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_' . self::WHATSAPP_FIELD_ID, array( __CLASS__, 'whatsapp_block_field_default' ), 10, 3 );
	}

	/**
	 * Whether the WhatsApp checkbox should be shown at checkout.
	 *
	 * Decided only by the plugin option (on by default), whatever the MandrakeCRM plan: a store on any plan
	 * collects the consent, so it is ready when the plan includes WhatsApp campaigns.
	 *
	 * @since 3.25
	 * @return bool
	 */
	public static function is_whatsapp_optin_enabled() {
		if ( '' === (string) get_option( 'mandrakecrm_token', '' ) ) {
			return false;
		}
		$enabled = '1' === (string) get_option( self::WHATSAPP_OPTION, '1' );

		/**
		 * Filters whether the WhatsApp opt-in checkbox is shown at checkout.
		 *
		 * @since 3.25
		 * @param bool $enabled Whether the checkbox is shown.
		 */
		return (bool) apply_filters( 'mandrakecrm_whatsapp_optin_enabled', $enabled );
	}

	/**
	 * Store the store name reported by the MandrakeCRM API (token verification and status sync).
	 *
	 * Ignores answers without a name, so the last known value is kept. Since 3.32 it is also called by the
	 * signed settings notice, and every call records when the API last answered (the hourly fallback reads it).
	 *
	 * @since 3.25
	 * @since 3.32 Records the time of the answer.
	 * @param array|null $body Decoded API response.
	 */
	public static function update_store_name( $body ) {
		update_option( self::STORE_NAME_CHECKED_OPTION, time() );
		if ( ! is_array( $body ) || ! isset( $body['store_name'] ) || ! is_string( $body['store_name'] ) ) {
			return;
		}
		$name = sanitize_text_field( $body['store_name'] );
		if ( '' !== $name && get_option( self::STORE_NAME_OPTION ) !== $name ) {
			update_option( self::STORE_NAME_OPTION, $name );
		}
	}

	/**
	 * Fallback of the signed settings notice: when a checkout page loads and the store name is more than an
	 * hour old, schedule one background status sync (WP-Cron, hook mandrakecrm_status_refresh).
	 *
	 * Never calls the API in the visitor's request: the page that schedules it still shows the current name.
	 * While the API does not answer, it schedules at most one sync every 15 minutes.
	 *
	 * @since 3.32
	 */
	public static function maybe_refresh_store_name() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		if ( '' === (string) get_option( 'mandrakecrm_token', '' ) ) {
			return;
		}
		if ( time() - (int) get_option( self::STORE_NAME_CHECKED_OPTION, 0 ) < self::STORE_NAME_MAX_AGE ) {
			return;
		}
		if ( get_transient( self::STORE_NAME_REFRESH_WAIT ) || wp_next_scheduled( 'mandrakecrm_status_refresh' ) ) {
			return;
		}
		set_transient( self::STORE_NAME_REFRESH_WAIT, 1, 15 * MINUTE_IN_SECONDS );
		wp_schedule_single_event( time(), 'mandrakecrm_status_refresh' );
	}

	/**
	 * Run the hourly fallback on the checkout page (classic and block checkout; not on the order received page).
	 *
	 * @since 3.32
	 */
	public static function maybe_refresh_store_name_on_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		self::maybe_refresh_store_name();
	}

	/**
	 * The store name shown to customers: the name in MandrakeCRM or, until the API reports it, the site name.
	 *
	 * @since 3.25
	 * @return string Plain text (escape on output).
	 */
	public static function get_store_name() {
		$name = trim( (string) get_option( self::STORE_NAME_OPTION, '' ) );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $name;
	}

	/**
	 * Label of the WhatsApp checkbox, with the store name.
	 *
	 * Same rules as the email one (get_marketing_label()): the translation bundled with the plugin while the language pack does
	 * not have the text, the text without the name when there is no store name, and a translation that is not a valid format
	 * never breaks the page (format_store_label()).
	 *
	 * @since 3.25
	 * @since 3.32 Safe against broken translations.
	 * @since 3.33 «I want to receive…» (spec 202, JA-11); the text without the name when there is no store name.
	 * @return string Plain text (escape on output).
	 */
	public static function get_whatsapp_label() {
		$store_name = self::get_store_name();

		if ( '' === trim( $store_name ) ) {
			$label = MandrakeCRM_I18n::translate( __( 'I want to receive promotions, news and exclusive offers on WhatsApp.', 'mandrakecrm' ), self::WHATSAPP_LABEL_PLAIN );
		} else {
			/* translators: %s: store name */
			$format = __( 'I want to receive promotions, news and exclusive offers from %s on WhatsApp.', 'mandrakecrm' );
			$label  = self::format_store_label( $format, self::WHATSAPP_LABEL_FORMAT, $store_name );
		}

		return (string) apply_filters( 'mandrakecrm_whatsapp_checkbox_label', $label );
	}

	/**
	 * Fill a label format with the store name, without ever breaking the page.
	 *
	 * The format comes from a translation (language pack or the .mo bundled with the plugin): one with two
	 * placeholders or a stray % makes sprintf() throw in PHP 8 (ArgumentCountError, ValueError), and these
	 * labels are built on every page load. Order: the translation; when it is the original text or not a
	 * valid format, the translation bundled with the plugin; last, the original English text (always valid).
	 *
	 * @since 3.32
	 * @param string $translated Format returned by __().
	 * @param string $original   Original format (msgid), with exactly one %s.
	 * @param string $store_name Store name.
	 * @return string
	 */
	private static function format_store_label( $translated, $original, $store_name ) {
		$label = ( $translated !== $original ) ? self::fill_store_name( $translated, $store_name ) : null;
		if ( null === $label ) {
			$bundled = MandrakeCRM_I18n::bundled( $original );
			$label   = ( $bundled !== $original ) ? self::fill_store_name( $bundled, $store_name ) : null;
		}
		if ( null === $label ) {
			$label = self::fill_store_name( $original, $store_name );
		}
		return null === $label ? $original : $label;
	}

	/**
	 * Run sprintf() on a format that must have exactly one string placeholder (%s or %1$s) and no other
	 * conversion (%% is allowed). Null when the format does not qualify or sprintf() fails.
	 *
	 * @since 3.32
	 * @param mixed  $format     Format.
	 * @param string $store_name Store name.
	 * @return string|null
	 */
	private static function fill_store_name( $format, $store_name ) {
		if ( ! is_string( $format ) || '' === trim( $format ) ) {
			return null;
		}
		$conversions = str_replace( '%%', '', $format );
		if ( 1 !== substr_count( $conversions, '%' ) || 1 !== preg_match( '/%(?:1\$)?s/', $conversions ) ) {
			return null;
		}
		try {
			$text = sprintf( $format, (string) $store_name );
		} catch ( \Throwable $e ) {
			return null;
		}
		return ( is_string( $text ) && '' !== trim( $text ) ) ? $text : null;
	}

	/**
	 * Whether the WhatsApp checkbox starts ticked.
	 *
	 * @since 3.25
	 * @return bool
	 */
	private static function whatsapp_checkbox_default() {
		/**
		 * Filters whether the WhatsApp opt-in checkbox starts ticked.
		 *
		 * @since 3.25
		 * @param bool $checked Default true.
		 */
		return (bool) apply_filters( 'mandrakecrm_whatsapp_checkbox_default', true );
	}

	/**
	 * Add the WhatsApp consent checkbox to the classic checkout.
	 *
	 * @since 3.25
	 * @since 3.32 Keeps the customer's answer when WooCommerce draws the box again (see classic_checkbox_checked()).
	 */
	public static function add_whatsapp_checkbox() {
		if ( ! self::is_whatsapp_optin_enabled() ) {
			return;
		}
		$checked = self::classic_checkbox_checked( 'mandrakecrm_whatsapp_optin', self::whatsapp_checkbox_default() );
		self::enqueue_classic_checkbox_script();
		?>
		<div class="mandrakecrm-marketing-optin mandrakecrm-whatsapp-optin">
			<input type="hidden" name="mandrakecrm_whatsapp_optin_shown" value="1" />
			<label>
				<input
					type="checkbox"
					name="mandrakecrm_whatsapp_optin"
					id="mandrakecrm_whatsapp_optin"
					value="yes"
					<?php checked( $checked ); ?>
				/>
				<span><?php echo esc_html( self::get_whatsapp_label() ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Whether a classic checkout checkbox is drawn ticked.
	 *
	 * The boxes live in the payment section, which WooCommerce draws again on every order review refresh (postcode,
	 * address, shipping method, coupon, a declined payment that asks for a refresh) and puts in the page whenever it
	 * changed; it only keeps the terms box. Order: what the customer's form had (a refresh, or the page drawn again after
	 * a post without JavaScript); else, on a full page load (F5, back from the cart, reload_checkout of a gateway), the
	 * customer's last choice (the 2-hour cookie checkout-optin.js writes on each click, then the one kept
	 * in the WooCommerce session); else, the first time, as the store decided (the default filter). So a box the customer
	 * unticked never comes back ticked. How the box started (the *_opt_in_default metas) is still the default filter.
	 *
	 * @since 3.32
	 * @since 3.32 The choice kept in the session and in the cookie (full page loads).
	 * @param string $name       Checkbox name.
	 * @param bool   $starts_on  Whether the box starts ticked.
	 * @return bool
	 */
	private static function classic_checkbox_checked( $name, $starts_on ) {
		$form = self::posted_checkout_form();
		if ( isset( $form[ $name . '_shown' ] ) ) {
			return isset( $form[ $name ] ) && 'yes' === $form[ $name ];
		}
		$choice = self::cookie_choice();
		if ( ! isset( $choice[ $name ] ) ) {
			$choice = self::session_choice();
		}
		if ( isset( $choice[ $name ] ) ) {
			return 'yes' === $choice[ $name ];
		}
		return (bool) $starts_on;
	}

	/**
	 * The customer's choice kept in the WooCommerce session (see SESSION_CHOICE_KEY).
	 *
	 * @since 3.32
	 * @return array<string, string> Checkbox name => 'yes' or 'no'.
	 */
	private static function session_choice() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		return self::clean_choice( WC()->session->get( self::SESSION_CHOICE_KEY ) );
	}

	/**
	 * The customer's last clicks on the boxes (see CHOICE_COOKIE). Anything else is ignored.
	 *
	 * @since 3.32
	 * @return array<string, string> Checkbox name => 'yes' or 'no'.
	 */
	private static function cookie_choice() {
		if ( ! isset( $_COOKIE[ self::CHOICE_COOKIE ] ) || ! is_string( $_COOKIE[ self::CHOICE_COOKIE ] ) ) {
			return array();
		}
		$value = sanitize_text_field( wp_unslash( $_COOKIE[ self::CHOICE_COOKIE ] ) );
		if ( 1 !== preg_match( '/^(e[01])?(w[01])?$/', $value ) ) {
			return array();
		}
		$out = array();
		if ( preg_match( '/e([01])/', $value, $m ) ) {
			$out['mandrakecrm_marketing_optin'] = '1' === $m[1] ? 'yes' : 'no';
		}
		if ( preg_match( '/w([01])/', $value, $m ) ) {
			$out['mandrakecrm_whatsapp_optin'] = '1' === $m[1] ? 'yes' : 'no';
		}
		return $out;
	}

	/**
	 * Keep only the two checkbox names with 'yes' or 'no'.
	 *
	 * @since 3.32
	 * @param mixed $choice Stored choice.
	 * @return array<string, string>
	 */
	private static function clean_choice( $choice ) {
		$out = array();
		if ( ! is_array( $choice ) ) {
			return $out;
		}
		foreach ( array( 'mandrakecrm_marketing_optin', 'mandrakecrm_whatsapp_optin' ) as $name ) {
			if ( isset( $choice[ $name ] ) && ( 'yes' === $choice[ $name ] || 'no' === $choice[ $name ] ) ) {
				$out[ $name ] = $choice[ $name ];
			}
		}
		return $out;
	}

	/**
	 * Keep in the WooCommerce session the answer of each box the form showed.
	 *
	 * @since 3.32
	 * @param array $form Form fields (name => value).
	 */
	private static function remember_choice( $form ) {
		if ( ! is_array( $form ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$choice = self::session_choice();
		$before = $choice;
		foreach ( array( 'mandrakecrm_marketing_optin', 'mandrakecrm_whatsapp_optin' ) as $name ) {
			if ( isset( $form[ $name . '_shown' ] ) && is_string( $form[ $name . '_shown' ] ) ) {
				$choice[ $name ] = ( isset( $form[ $name ] ) && is_string( $form[ $name ] ) && 'yes' === sanitize_text_field( $form[ $name ] ) ) ? 'yes' : 'no';
			}
		}
		if ( $choice !== $before ) {
			WC()->session->set( self::SESSION_CHOICE_KEY, $choice );
		}
	}

	/**
	 * Order review refresh (wc-ajax=update_order_review, after WooCommerce checks its nonce): keep the boxes of the form.
	 *
	 * @since 3.32
	 * @param string $post_data The checkout form, serialized.
	 */
	public static function remember_choice_from_review( $post_data ) {
		// A real form never has a NUL byte, and PHP 8.6 parse_str() (under wp_parse_str) throws a ValueError on one.
		if ( ! is_string( $post_data ) || '' === $post_data || false !== strpos( $post_data, "\0" ) ) {
			return;
		}
		$form = array();
		wp_parse_str( $post_data, $form );
		self::remember_choice( $form );
	}

	/**
	 * Checkout post (after WooCommerce checks its nonce; also «Update totals» without JavaScript): keep the boxes of the form.
	 *
	 * A declined payment that reloads the page (reload_checkout) then draws them as the customer left them.
	 *
	 * @since 3.32
	 */
	public static function remember_choice_from_checkout() {
		$form = array();
		foreach ( array( 'mandrakecrm_marketing_optin', 'mandrakecrm_whatsapp_optin' ) as $name ) {
			foreach ( array( $name, $name . '_shown' ) as $key ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
				if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing
					$form[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				}
			}
		}
		self::remember_choice( $form );
	}

	/**
	 * A new cart starts as the store decided: forget the choice when the cart is emptied (an order paid, the recovery link of
	 * an abandoned cart, the customer emptying it).
	 *
	 * When the page has already started (headers sent), the cookie is removed by a line of JavaScript at the end of the page
	 * (print_forget_choice_script()); a response that is not a page (AJAX, REST) has not sent its headers yet. Either way the
	 * cookie expires by itself after CHOICE_COOKIE_MAX_AGE.
	 *
	 * @since 3.32
	 * @since 3.32 Removed in the browser when the headers were already sent.
	 */
	public static function forget_choice() {
		if ( function_exists( 'WC' ) && WC()->session && null !== WC()->session->get( self::SESSION_CHOICE_KEY ) ) {
			WC()->session->__unset( self::SESSION_CHOICE_KEY );
		}
		if ( ! isset( $_COOKIE[ self::CHOICE_COOKIE ] ) ) {
			return;
		}
		unset( $_COOKIE[ self::CHOICE_COOKIE ] );
		if ( ! headers_sent() ) {
			setcookie( self::CHOICE_COOKIE, '', time() - YEAR_IN_SECONDS, '/' ); // The path and host checkout-optin.js writes it with.
			return;
		}
		// Also while the footer is being drawn (a later priority of wp_footer still runs).
		if ( ! self::$forget_in_browser && ( doing_action( 'wp_footer' ) || ! did_action( 'wp_footer' ) ) && ! wp_doing_ajax() && ! wp_is_json_request() ) {
			self::$forget_in_browser = true;
			add_action( 'wp_footer', array( __CLASS__, 'print_forget_choice_script' ), 1000 );
		}
	}

	/**
	 * Remove CHOICE_COOKIE in the browser, at the end of a page whose headers were already sent when the cart was emptied.
	 *
	 * @since 3.32
	 */
	public static function print_forget_choice_script() {
		if ( ! self::$forget_in_browser ) {
			return;
		}
		self::$forget_in_browser = false;

		$js = 'document.cookie="' . self::CHOICE_COOKIE . '=; max-age=0; path=/; SameSite=Lax";';
		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $js, array( 'id' => 'mandrakecrm-optin-forget' ) );
		} else {
			echo '<script id="mandrakecrm-optin-forget">' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a constant script.
		}
	}

	/**
	 * The two MandrakeCRM checkboxes (and their «shown» fields) of the classic checkout form the browser sent, if any.
	 *
	 * The order review refresh (wc-ajax=update_order_review) sends the whole form serialized in post_data; a checkout
	 * posted without JavaScript (update totals, or an order with errors) sends the fields themselves and the page is
	 * drawn again. Only decides how a box is drawn, as WooCommerce's own WC_Checkout::get_value() does with the other
	 * fields: the answer saved in the order is read in the request that places it, after WooCommerce checks its nonce.
	 *
	 * @since 3.32
	 * @return array<string, string> Field name => sanitized value.
	 */
	private static function posted_checkout_form() {
		$form = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only decides how the box is drawn (see above).
		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value used is sanitized below.
			$post_data = wp_unslash( $_POST['post_data'] );
			// PHP 8.6: parse_str() (under wp_parse_str) throws a ValueError on a NUL byte, which a real form never has
			// (it only shows up once unslashed: the slashed $_POST carries it as «\0»).
			if ( false === strpos( $post_data, "\0" ) ) {
				wp_parse_str( $post_data, $form );
			}
		} elseif ( isset( $_POST['woocommerce-process-checkout-nonce'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value used is sanitized below.
			$form = wp_unslash( $_POST );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$out = array();
		foreach ( array( 'mandrakecrm_marketing_optin', 'mandrakecrm_whatsapp_optin' ) as $name ) {
			foreach ( array( $name, $name . '_shown' ) as $key ) {
				if ( isset( $form[ $key ] ) && is_string( $form[ $key ] ) ) {
					$out[ $key ] = sanitize_text_field( $form[ $key ] );
				}
			}
		}
		return $out;
	}

	/**
	 * Enqueue the small script that keeps the customer's last click on the classic checkboxes.
	 *
	 * A refresh can start before a click and come back after it (the click on a box is what leaves the postcode field,
	 * or the customer unticks a box while the refresh of the page load is on its way): its answer was drawn with the old
	 * state. After each refresh («updated_checkout») the script puts back what the customer last chose, and each click
	 * goes to a short-lived cookie (CHOICE_COOKIE) so a full page load draws the boxes as the customer left them. No
	 * dependency on jQuery: the script listens to the refresh as soon as jQuery is there.
	 * Optimizers that delay or combine scripts leave it alone (optin_script_tag(), exclude_optin_script()).
	 *
	 * @since 3.32
	 */
	private static function enqueue_classic_checkbox_script() {
		if ( wp_doing_ajax() || ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		wp_enqueue_script(
			self::OPTIN_SCRIPT_HANDLE,
			MANDRAKECRM_PLUGIN_URL . 'assets/js/checkout-optin.js',
			array(), // Uses jQuery only when it is there (see checkout-optin.js): an optimizer may load it late.
			MANDRAKECRM_VERSION,
			true
		);
	}

	/**
	 * Load checkout-optin.js on the checkout page (classic and blocks; not on the order received page). The classic boxes also
	 * load it when they are drawn, for checkouts that are not the WooCommerce checkout page.
	 *
	 * @since 3.32
	 */
	public static function enqueue_checkout_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return;
		}
		self::enqueue_classic_checkbox_script();
	}

	/**
	 * Mark the checkout-optin.js tag so optimizers do not delay, defer or combine it (LiteSpeed Cache: data-no-optimize,
	 * data-no-defer; Cloudflare Rocket Loader: data-cfasync; WP Rocket and Autoptimize: exclude_optin_script()).
	 *
	 * @since 3.32
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function optin_script_tag( $tag, $handle ) {
		if ( self::OPTIN_SCRIPT_HANDLE !== $handle || ! is_string( $tag ) || false !== strpos( $tag, 'data-no-optimize' ) ) {
			return $tag;
		}
		return (string) preg_replace(
			'#<script(?=[^>]*\ssrc=[\'"][^\'"]*checkout-optin\.js)#',
			'<script data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false"',
			$tag,
			1
		);
	}

	/**
	 * Add checkout-optin.js to an optimizer's exclusion list (WP Rocket: rocket_delay_js_exclusions,
	 * rocket_exclude_defer_js, rocket_exclude_js; LiteSpeed Cache: litespeed_optm_js_defer_exc,
	 * litespeed_optimize_js_excludes).
	 *
	 * @since 3.32
	 * @param mixed $excluded Exclusion list (patterns).
	 * @return mixed
	 */
	public static function exclude_optin_script( $excluded ) {
		if ( ! is_array( $excluded ) ) {
			return $excluded;
		}
		if ( ! in_array( self::OPTIN_SCRIPT_FILE, $excluded, true ) ) {
			$excluded[] = self::OPTIN_SCRIPT_FILE;
		}
		return $excluded;
	}

	/**
	 * Autoptimize: add checkout-optin.js to its comma-separated exclusion list (autoptimize_filter_js_exclude).
	 *
	 * @since 3.32
	 * @param mixed $excluded Comma-separated exclusion list.
	 * @return mixed
	 */
	public static function exclude_optin_script_csv( $excluded ) {
		if ( ! is_string( $excluded ) || false !== strpos( $excluded, 'checkout-optin.js' ) ) {
			return $excluded;
		}
		return '' === trim( $excluded ) ? self::OPTIN_SCRIPT_FILE : rtrim( $excluded, ", \t\n" ) . ', ' . self::OPTIN_SCRIPT_FILE;
	}

	/**
	 * Save the WhatsApp opt-in of the classic checkout to the order.
	 *
	 * Only when the checkbox was shown: an order placed without it carries no value. Since 3.32 it also saves
	 * how the box started, calculated here on the server with the same filter that renders the box (as the
	 * block checkout does): nothing about it comes from the browser.
	 *
	 * @since 3.25
	 * @since 3.32 How the box started ('_mandrakecrm_whatsapp_opt_in_default'). A payment retry on the order already placed
	 *             changes only the answer, as the customer's edit (see retry_classic_answer()).
	 * @since 3.33 Decided per checkbox: a retry on an order without a WhatsApp answer (the box was off when it was placed) saves
	 *             it as the first answer, with how the box started.
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 */
	public static function save_whatsapp_optin( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		if ( ! isset( $_POST['mandrakecrm_whatsapp_optin_shown'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$optin = isset( $_POST['mandrakecrm_whatsapp_optin'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['mandrakecrm_whatsapp_optin'] ) ) ? 'yes' : 'no';

		if ( self::is_resumed_classic_order( $order, self::WHATSAPP_META_KEY ) ) {
			self::retry_classic_answer( $order, self::WHATSAPP_META_KEY, $optin );
			return;
		}

		$order->update_meta_data( self::WHATSAPP_META_KEY, $optin );
		$order->update_meta_data( self::WHATSAPP_DEFAULT_META_KEY, self::whatsapp_checkbox_default() ? 'yes' : 'no' );
	}

	/**
	 * Remember the order WooCommerce resumes: the classic checkout reuses the pending or failed order of the session when the
	 * customer retries a declined payment (WC_Checkout::create_order()) and fires woocommerce_resume_order with its id right
	 * before woocommerce_checkout_create_order. Which checkboxes it already had an answer for is read here, before anything is
	 * written.
	 *
	 * @since 3.32
	 * @since 3.33 Per checkbox.
	 * @param int $order_id Id of the order being resumed.
	 */
	public static function remember_resumed_order( $order_id ) {
		self::$resumed_order_id       = absint( $order_id );
		self::$resumed_order_answered = array();
		$order                        = self::$resumed_order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( self::$resumed_order_id ) : null;
		if ( $order instanceof WC_Order ) {
			foreach ( array( self::META_KEY, self::WHATSAPP_META_KEY ) as $meta_key ) {
				$answer = (string) $order->get_meta( $meta_key );
				if ( 'yes' === $answer || 'no' === $answer ) {
					self::$resumed_order_answered[ $meta_key ] = true;
				}
			}
		}
	}

	/**
	 * Whether the classic checkout is placing again an order already placed (a payment retry, see remember_resumed_order()) that
	 * already had an answer: for one checkbox ($meta_key) or, without it, for either.
	 *
	 * Decided by WooCommerce's own signal, never by the order having an id: another plugin may save a new order earlier in
	 * woocommerce_checkout_create_order. A checkbox without an answer in the resumed order ('yes' or 'no': placed while that box
	 * was not shown) is answered as in a new order, with its proof; the other one keeps its answer and proof.
	 *
	 * @since 3.32
	 * @since 3.33 Per checkbox ($meta_key).
	 * @param mixed       $order    Order being created.
	 * @param string|null $meta_key Answer meta key, or null for either checkbox.
	 * @return bool
	 */
	private static function is_resumed_classic_order( $order, $meta_key = null ) {
		if ( ! ( $order instanceof WC_Order )
			|| $order->get_id() <= 0
			|| $order->get_id() !== self::$resumed_order_id
			|| $order->has_status( array( 'checkout-draft', 'auto-draft' ) ) ) {
			return false;
		}
		return null === $meta_key ? ! empty( self::$resumed_order_answered ) : ! empty( self::$resumed_order_answered[ $meta_key ] );
	}

	/**
	 * Apply the customer's answer of a payment retry to the order already placed, as the block checkout does
	 * (save_block_field()): only an answer the order already had ('yes' or 'no') that the customer changed; it records the
	 * change as the customer's own (mark_answer_edit(), 'buyer'). How the box started and the proof never change, and an
	 * unchanged answer leaves the order untouched. The real case: a declined card, the customer changes the box and pays.
	 *
	 * @since 3.32
	 * @param WC_Order $order    Placed order (pending or failed).
	 * @param string   $meta_key Answer meta key.
	 * @param string   $answer   'yes' or 'no'.
	 */
	private static function retry_classic_answer( $order, $meta_key, $answer ) {
		$current = (string) $order->get_meta( $meta_key );
		if ( ( 'yes' === $current || 'no' === $current ) && $current !== $answer ) {
			$order->update_meta_data( $meta_key, $answer );
			self::mark_answer_edit( $order, 'buyer' );
		}
	}

	/**
	 * Save the proof of the classic checkout answers (only for the checkboxes that were shown).
	 *
	 * @since 3.32
	 * @since 3.33 In a payment retry, the proof of a checkbox answered for the first time.
	 * @param WC_Order $order Order object.
	 */
	public static function save_classic_proof( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		$email_shown = isset( $_POST['mandrakecrm_marketing_optin_shown'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$whatsapp_shown = isset( $_POST['mandrakecrm_whatsapp_optin_shown'] );

		if ( self::is_resumed_classic_order( $order ) ) {
			// A payment retry (see retry_classic_answer()): the proof of an answer already given never changes; a checkbox answered
			// for the first time in this retry gets its own (3.33).
			$email_first    = $email_shown && ! self::is_resumed_classic_order( $order, self::META_KEY );
			$whatsapp_first = $whatsapp_shown && ! self::is_resumed_classic_order( $order, self::WHATSAPP_META_KEY );
			if ( $email_first || $whatsapp_first ) {
				self::save_proof_meta( $order, 'classic', $email_first, $whatsapp_first, true );
			}
			return;
		}

		self::save_proof_meta( $order, 'classic', $email_shown, $whatsapp_shown );
	}

	/**
	 * Save (or clear) the proof metas of an order.
	 *
	 * The labels, the locale and the store name are calculated here, on the server, in the same request that
	 * places the order, with the same functions that render the checkboxes: nothing comes from the browser.
	 * The labels are calculated in the locale the checkout page was shown in (checkout_locale()), also when the
	 * request itself runs in another one (a Store API request of a logged-in customer with another profile language).
	 *
	 * @since 3.32
	 * @param WC_Order $order          Order object.
	 * @param string   $checkout_type  'classic' or 'blocks'.
	 * @param bool     $email_shown    Whether the email checkbox was answered.
	 * @param bool     $whatsapp_shown Whether the WhatsApp checkbox was answered.
	 * @param bool     $add_only       Since 3.33: only add the proof of the answered checkboxes to an order that has the proof of
	 *                                 the other one (a payment retry): the other label is kept, and the locale, store name, plugin
	 *                                 version and checkout type are written only when missing.
	 */
	public static function save_proof_meta( $order, $checkout_type, $email_shown, $whatsapp_shown, $add_only = false ) {
		if ( ! $email_shown && ! $whatsapp_shown ) {
			if ( $add_only ) {
				return;
			}
			$keys = array( self::MARKETING_LABEL_META_KEY, self::WHATSAPP_LABEL_META_KEY, self::LOCALE_META_KEY, self::STORE_NAME_META_KEY, self::PLUGIN_VERSION_META_KEY, self::CHECKOUT_TYPE_META_KEY );
			foreach ( $keys as $key ) {
				$order->delete_meta_data( $key );
			}
			return;
		}

		$locale   = self::checkout_locale();
		$current  = function_exists( 'determine_locale' ) ? (string) determine_locale() : $locale;
		$switched = false;
		if ( $current !== $locale && function_exists( 'switch_to_locale' ) ) {
			$switched = (bool) switch_to_locale( $locale );
		}
		if ( ! $switched ) {
			// Same locale, or the checkout locale cannot be loaded: keep the one the labels are really in.
			$locale = $current;
		}
		try {
			$email_label    = $email_shown ? self::get_marketing_label() : '';
			$whatsapp_label = $whatsapp_shown ? self::get_whatsapp_label() : '';
		} finally {
			if ( $switched && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}

		if ( $email_shown ) {
			$order->update_meta_data( self::MARKETING_LABEL_META_KEY, self::limit_text( $email_label, self::MAX_LABEL_LENGTH ) );
		} elseif ( ! $add_only ) {
			$order->delete_meta_data( self::MARKETING_LABEL_META_KEY );
		}
		if ( $whatsapp_shown ) {
			$order->update_meta_data( self::WHATSAPP_LABEL_META_KEY, self::limit_text( $whatsapp_label, self::MAX_LABEL_LENGTH ) );
		} elseif ( ! $add_only ) {
			$order->delete_meta_data( self::WHATSAPP_LABEL_META_KEY );
		}
		if ( $add_only && '' !== (string) $order->get_meta( self::PLUGIN_VERSION_META_KEY ) ) {
			return; // The rest of the proof is the one of the first answer.
		}

		if ( strlen( $locale ) <= 20 && preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale ) ) {
			$order->update_meta_data( self::LOCALE_META_KEY, $locale );
		} else {
			$order->delete_meta_data( self::LOCALE_META_KEY );
		}
		$order->update_meta_data( self::STORE_NAME_META_KEY, self::limit_text( self::get_store_name(), self::MAX_STORE_NAME_LENGTH ) );
		$order->update_meta_data( self::PLUGIN_VERSION_META_KEY, MANDRAKECRM_VERSION );
		$order->update_meta_data( self::CHECKOUT_TYPE_META_KEY, 'blocks' === $checkout_type ? 'blocks' : 'classic' );
	}

	/**
	 * Locale the checkout page is shown in, classic or blocks: the site locale (get_locale(), which multilingual
	 * plugins filter per page).
	 *
	 * Not determine_locale(): a Store API request sent with _locale=user (WordPress's apiFetch adds it to every
	 * request of the block checkout) runs in the logged-in customer's profile language, which the page never showed.
	 *
	 * @since 3.32
	 * @return string
	 */
	private static function checkout_locale() {
		return (string) get_locale();
	}

	/**
	 * Cut a text to a number of characters (multibyte safe).
	 *
	 * @since 3.32
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function limit_text( $text, $max ) {
		$text = (string) $text;
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) : $text;
	}

	/**
	 * Register the email and WhatsApp checkboxes in the block checkout.
	 *
	 * Uses the Additional Checkout Fields API (WooCommerce 8.9+); on older versions
	 * the block checkout simply shows no checkbox.
	 *
	 * @since 3.25
	 */
	public static function register_block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		self::register_block_field( self::MARKETING_FIELD_ID, self::get_marketing_label() );
		if ( self::is_whatsapp_optin_enabled() ) {
			self::register_block_field( self::WHATSAPP_FIELD_ID, self::get_whatsapp_label() );
		}
	}

	/**
	 * Register one optional checkbox of the block checkout.
	 *
	 * @since 3.25
	 * @param string $id    Field id.
	 * @param string $label Label (plain text).
	 */
	private static function register_block_field( $id, $label ) {
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => $id,
					'label'    => $label,
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Throwable $e ) {
			// A registration error must never break the checkout.
			return;
		}
	}

	/**
	 * Whether a block checkbox may start ticked for this object.
	 *
	 * Only while the customer is at the checkout (draft order, customer or session): a placed order
	 * without the field (for example, older than 3.25) must never read as consent.
	 *
	 * @since 3.25
	 * @param mixed $wc_object Object being read.
	 * @return bool
	 */
	private static function is_checkout_object( $wc_object ) {
		return ! ( $wc_object instanceof WC_Order && 'checkout-draft' !== $wc_object->get_status() );
	}

	/**
	 * Start the block email checkbox as the classic one does (ticked by default).
	 *
	 * The block checkout keeps the answer only when the order is placed: a full page load (F5, back from the cart) would draw
	 * the box from this default again. Since 3.32 the customer's last click (CHOICE_COOKIE, written
	 * by checkout-optin.js) comes first. How the box started (the *_opt_in_default metas) is still the default filter.
	 *
	 * @since 3.25
	 * @since 3.32 The customer's last click comes first.
	 * @param mixed  $value     Default value.
	 * @param string $group     Field group.
	 * @param mixed  $wc_object Object being read.
	 * @return mixed
	 */
	public static function marketing_block_field_default( $value, $group, $wc_object ) {
		if ( ! self::is_checkout_object( $wc_object ) ) {
			return $value;
		}
		$choice = self::cookie_choice();
		if ( isset( $choice['mandrakecrm_marketing_optin'] ) ) {
			return 'yes' === $choice['mandrakecrm_marketing_optin'] ? '1' : '0';
		}
		return self::marketing_checkbox_default() ? '1' : $value;
	}

	/**
	 * Start the block checkbox ticked (same default as the classic checkout); the customer's last click first (since 3.32, see
	 * marketing_block_field_default()).
	 *
	 * @since 3.25
	 * @since 3.32 The customer's last click comes first.
	 * @param mixed  $value     Default value.
	 * @param string $group     Field group.
	 * @param mixed  $wc_object Object being read.
	 * @return mixed
	 */
	public static function whatsapp_block_field_default( $value, $group, $wc_object ) {
		if ( ! self::is_checkout_object( $wc_object ) ) {
			return $value;
		}
		$choice = self::cookie_choice();
		if ( isset( $choice['mandrakecrm_whatsapp_optin'] ) ) {
			return 'yes' === $choice['mandrakecrm_whatsapp_optin'] ? '1' : '0';
		}
		return self::whatsapp_checkbox_default() ? '1' : $value;
	}

	/**
	 * Copy a block checkbox to the order meta the MandrakeCRM API reads.
	 *
	 * On a placed order (no longer a checkout draft) only two sources change the answer: the Store API request being
	 * served (WooCommerce also copies the customer's or session's values of the fields into the order, which never
	 * count) and the store owner changing the box in the WooCommerce order screen (owner_edit_answer()), for example
	 * to record an opt-out the customer asked for by phone. The proof of the answer never changes on a placed order.
	 *
	 * @since 3.25
	 * @since 3.32 Placed orders change only with an answer from the request or the owner's edit; a changed answer
	 *             records who changed it and when (mark_answer_edit()).
	 * @param string $key       Field id.
	 * @param mixed  $value     Field value.
	 * @param string $group     Field group.
	 * @param mixed  $wc_object Object being saved.
	 */
	public static function save_block_field( $key, $value, $group, $wc_object ) {
		if ( ! ( $wc_object instanceof WC_Order ) ) {
			return;
		}
		if ( self::MARKETING_FIELD_ID !== $key && self::WHATSAPP_FIELD_ID !== $key ) {
			return;
		}
		$answer = self::block_answer( $value );
		if ( 'checkout-draft' !== $wc_object->get_status() ) {
			if ( self::is_owner_edit( $key, $answer, $wc_object ) ) {
				self::owner_edit_answer( $key, $answer, $wc_object );
				return;
			}
			if ( ! self::request_carries_answer( $key, $answer ) ) {
				// A placed order (failed-payment retry, pay for a pending order): WooCommerce also copies into it the values
				// the customer or the session keep for the fields, which may be the box's default. Only an answer the
				// customer sent in this very request may change the placed order.
				return;
			}
			// The customer changed the answer of the placed order (retrying the payment or paying a pending order).
			$meta_key = self::MARKETING_FIELD_ID === $key ? self::META_KEY : self::WHATSAPP_META_KEY;
			if ( (string) $wc_object->get_meta( $meta_key ) !== $answer ) {
				self::mark_answer_edit( $wc_object, 'buyer' );
			}
		}
		if ( self::MARKETING_FIELD_ID === $key ) {
			$wc_object->update_meta_data( self::META_KEY, $answer );
			$wc_object->update_meta_data( self::DEFAULT_META_KEY, self::marketing_checkbox_default() ? 'yes' : 'no' );
		} elseif ( self::WHATSAPP_FIELD_ID === $key ) {
			$wc_object->update_meta_data( self::WHATSAPP_META_KEY, $answer );
		}
	}

	/**
	 * Whether this value comes from the store owner saving the WooCommerce order screen.
	 *
	 * WooCommerce saves the "Additional fields" of the order screen through woocommerce_process_shop_order_meta
	 * (WC_Meta_Box_Order_Data::save(), the field's update_callback, then woocommerce_set_additional_field_value), after
	 * checking its own nonce: woocommerce_meta_nonce on the posts screen, update-order_{id} on the orders table screen
	 * (HPOS). Both are checked again here, with the capability to edit orders and the value posted by the form.
	 *
	 * @since 3.32
	 * @param string   $key    Field id.
	 * @param string   $answer 'yes' or 'no'.
	 * @param WC_Order $order  Order being saved.
	 * @return bool
	 */
	private static function is_owner_edit( $key, $answer, $order ) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_shop_orders is WooCommerce's capability to edit orders.
		if ( ! is_admin() || ! doing_action( 'woocommerce_process_shop_order_meta' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- the nonces are verified right here.
		$legacy = isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		$hpos   = isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'update-order_' . $order->get_id() );
		if ( ! $legacy && ! $hpos ) {
			return false;
		}
		$field = '_wc_other/' . $key;
		if ( ! isset( $_POST[ $field ] ) ) {
			return false;
		}
		$posted = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		// phpcs:enable WordPress.Security.NonceVerification
		return self::block_answer( $posted ) === $answer;
	}

	/**
	 * Apply the store owner's edit of a checkbox to the answer MandrakeCRM reads (sent in the order.updated webhook).
	 *
	 * The order screen posts every field each time the order is saved, with the answer it showed
	 * (admin_order_field_values()): only a different answer is a change by the owner. Only an answer the customer
	 * gave can change, as MandrakeCRM records it (an order without an answer has no proof): saving the order never
	 * turns an order without an answer into a "yes" or a "no", and the proof of the customer's answer stays.
	 *
	 * @since 3.32
	 * @param string   $key    Field id.
	 * @param string   $answer 'yes' or 'no'.
	 * @param WC_Order $order  Order being saved.
	 */
	private static function owner_edit_answer( $key, $answer, $order ) {
		$meta_key = self::MARKETING_FIELD_ID === $key ? self::META_KEY : self::WHATSAPP_META_KEY;
		$current  = (string) $order->get_meta( $meta_key );
		if ( ( 'yes' === $current || 'no' === $current ) && $current !== $answer ) {
			$order->update_meta_data( $meta_key, $answer );
			self::mark_answer_edit( $order, 'admin' );
		}
	}

	/**
	 * Record who changed an answer of a placed order and when (EDIT_SOURCE_META_KEY, EDITED_AT_META_KEY), so MandrakeCRM
	 * can tell the store owner's edit from the customer's own (both travel in the order.updated webhook).
	 *
	 * @since 3.32
	 * @param WC_Order $order  Placed order.
	 * @param string   $source 'admin' or 'buyer'.
	 */
	private static function mark_answer_edit( $order, $source ) {
		$order->update_meta_data( self::EDIT_SOURCE_META_KEY, 'admin' === $source ? 'admin' : 'buyer' );
		$order->update_meta_data( self::EDITED_AT_META_KEY, gmdate( 'Y-m-d\TH:i:s\Z' ) );
	}

	/**
	 * Show in the WooCommerce order screen the answer MandrakeCRM keeps for each checkbox.
	 *
	 * WooCommerce shows its own copy of the field, which on a placed order may differ from the answer (a failed-payment
	 * retry copies the session's value into it, see save_block_field()). The screen posts back what it shows, so it
	 * must show the answer MandrakeCRM reads: unticked when the order has no answer (in the view, a dash: since 3.32).
	 *
	 * @since 3.32
	 * @param array  $fields  Shipping fields of the order screen.
	 * @param mixed  $order   Order.
	 * @param string $context 'edit' (form) or 'view'.
	 * @return array
	 */
	public static function admin_order_field_values( $fields, $order = null, $context = 'edit' ) {
		if ( ! is_array( $fields ) || ! ( $order instanceof WC_Order ) || 'checkout-draft' === $order->get_status() ) {
			return $fields;
		}
		$meta_keys = array(
			'_wc_other/' . self::MARKETING_FIELD_ID => self::META_KEY,
			'_wc_other/' . self::WHATSAPP_FIELD_ID  => self::WHATSAPP_META_KEY,
		);
		foreach ( $fields as $name => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) || ! isset( $meta_keys[ $field['id'] ] ) ) {
				continue;
			}
			$answer = (string) $order->get_meta( $meta_keys[ $field['id'] ] );
			$on     = 'yes' === $answer;
			if ( 'view' === $context ) {
				// WooCommerce's own labels, as it shows a checkbox field; a dash when the customer gave no answer (WooCommerce's
				// copy of the field may still be there, and would read as «No»).
				if ( 'yes' !== $answer && 'no' !== $answer ) {
					$fields[ $name ]['value'] = '—';
				} else {
					$fields[ $name ]['value'] = $on ? __( 'Yes', 'woocommerce' ) : __( 'No', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}
			} else {
				$fields[ $name ]['value'] = $on;
			}
		}
		return $fields;
	}

	/**
	 * Normalize a block checkbox value to 'yes' or 'no'.
	 *
	 * @since 3.32
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function block_answer( $value ) {
		return ( true === $value || '1' === $value || 1 === $value ) ? 'yes' : 'no';
	}

	/**
	 * Remember the Store API checkout request being served, so save_block_field() can tell an answer the customer
	 * sent from a value WooCommerce copied from the customer or the session.
	 *
	 * @since 3.32
	 * @param mixed           $response Response so far (returned unchanged).
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function remember_checkout_request( $response, $handler, $request ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) && preg_match( '#^/wc/store(/v\d+)?/checkout(/\d+)?/?$#', (string) $request->get_route() ) ) {
			self::$checkout_request = $request;
		}
		return $response;
	}

	/**
	 * Whether the Store API checkout request being served carries this answer for this field.
	 *
	 * @since 3.32
	 * @param string $key    Field id.
	 * @param string $answer 'yes' or 'no'.
	 * @return bool
	 */
	private static function request_carries_answer( $key, $answer ) {
		if ( null === self::$checkout_request ) {
			return false;
		}
		$fields = self::$checkout_request->get_param( 'additional_fields' );
		return is_array( $fields ) && array_key_exists( $key, $fields ) && self::block_answer( $fields[ $key ] ) === $answer;
	}

	/**
	 * Record each block choice only when the checkout request actually carried it.
	 *
	 * A Store API client that never showed the checkboxes (for example, a custom headless
	 * storefront) sends no value, and WooCommerce would fill in the default: that must not
	 * read as an answer, so the order keeps no value for that checkbox then.
	 *
	 * Only while the order is being placed (a checkout draft). WooCommerce fires the same hook when a customer pays
	 * a pending order (POST /checkout/{id}) and when the checkout retries a failed payment on the order of the
	 * session (PATCH or POST /checkout): those requests do not need to carry the fields, so nothing is cleared, and
	 * the proof of the placed order stays as it is (an answer the retry does carry is applied by save_block_field()).
	 *
	 * @since 3.25
	 * @since 3.32 Leaves orders that are not a checkout draft untouched.
	 * @param WC_Order        $order   Order being placed.
	 * @param WP_REST_Request $request Checkout request.
	 */
	public static function require_block_answers( $order, $request ) {
		if ( ! ( $order instanceof WC_Order ) || ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return;
		}
		if ( 'checkout-draft' !== $order->get_status() ) {
			return; // Answers of a placed order change only as save_block_field() allows; its proof never.
		}
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( preg_match( '#/checkout/\d+/?$#', $route ) ) {
			return;
		}
		$fields = $request->get_param( 'additional_fields' );
		$fields = is_array( $fields ) ? $fields : array();
		if ( ! array_key_exists( self::MARKETING_FIELD_ID, $fields ) ) {
			$order->delete_meta_data( self::META_KEY );
			$order->delete_meta_data( self::DEFAULT_META_KEY );
			// WooCommerce's own copy of the field (it would show as "Yes" on the order).
			$order->delete_meta_data( '_wc_other/' . self::MARKETING_FIELD_ID );
		}
		$whatsapp_answered = array_key_exists( self::WHATSAPP_FIELD_ID, $fields ) && self::is_whatsapp_optin_enabled();
		if ( ! array_key_exists( self::WHATSAPP_FIELD_ID, $fields ) ) {
			$order->delete_meta_data( self::WHATSAPP_META_KEY );
			$order->delete_meta_data( '_wc_other/' . self::WHATSAPP_FIELD_ID );
		}

		// Proof (3.32): how the WhatsApp box started (the filter value, as the field default) and the labels.
		if ( $whatsapp_answered ) {
			$order->update_meta_data( self::WHATSAPP_DEFAULT_META_KEY, self::whatsapp_checkbox_default() ? 'yes' : 'no' );
		} else {
			$order->delete_meta_data( self::WHATSAPP_DEFAULT_META_KEY );
		}
		$email_answered = array_key_exists( self::MARKETING_FIELD_ID, $fields ) && function_exists( 'woocommerce_register_additional_checkout_field' );
		self::save_proof_meta( $order, 'blocks', $email_answered, $whatsapp_answered );
	}

	/**
	 * Whether the email checkbox starts ticked.
	 *
	 * @since 3.25
	 * @return bool
	 */
	private static function marketing_checkbox_default() {
		/**
		 * Filters whether the email opt-in checkbox starts ticked.
		 *
		 * @since 2.0.0
		 * @param bool $checked Default true.
		 */
		return (bool) apply_filters( 'mandrakecrm_marketing_checkbox_default', true );
	}

	/**
	 * Label of the email checkbox, with the store name (as the WhatsApp one).
	 *
	 * If the language pack installed from WordPress.org does not have this text yet, the translation bundled in
	 * the plugin's own languages/ folder is used, so the box never falls back to English in a translated store.
	 * Without any store name (no MandrakeCRM name and no site name) the same text without the name, also with the bundled
	 * translation. A translation that is not a valid format falls back as format_store_label() explains.
	 *
	 * @since 3.25
	 * @since 3.32 Names the store; the filter receives the store name as a second argument.
	 * @since 3.33 «I want to receive…», the same form as the WhatsApp one (spec 202, JA-11).
	 * @return string Plain text (escape on output).
	 */
	public static function get_marketing_label() {
		$store_name = self::get_store_name();

		if ( '' === trim( $store_name ) ) {
			$label = MandrakeCRM_I18n::translate( __( 'I want to receive promotions, news and exclusive offers by email.', 'mandrakecrm' ), self::MARKETING_LABEL_PLAIN );
		} else {
			/* translators: %s: store name */
			$format = __( 'I want to receive promotions, news and exclusive offers from %s by email.', 'mandrakecrm' );
			$label  = self::format_store_label( $format, self::MARKETING_LABEL_FORMAT, $store_name );
		}

		/**
		 * Filters the label of the email opt-in checkbox.
		 *
		 * @since 3.25
		 * @since 3.32 Second argument: the store name.
		 * @param string $label      Label (plain text).
		 * @param string $store_name Store name used in the label.
		 */
		return (string) apply_filters( 'mandrakecrm_marketing_checkbox_label', $label, $store_name );
	}

	/**
	 * Add marketing consent checkbox to checkout.
	 *
	 * @since 2.0.0
	 * @since 3.25 A hidden field tells MandrakeCRM that the box was shown.
	 * @since 3.32 How the box started is no longer sent by the browser (see save_marketing_optin()).
	 * @since 3.32 Keeps the customer's answer when WooCommerce draws the box again (see classic_checkbox_checked()).
	 */
	public static function add_marketing_checkbox() {
		$checked = self::classic_checkbox_checked( 'mandrakecrm_marketing_optin', self::marketing_checkbox_default() );
		self::enqueue_classic_checkbox_script();

		// Checkouts that are not the WooCommerce checkout page (for example, FunnelKit) still render this box.
		self::maybe_refresh_store_name();

		?>
		<div class="mandrakecrm-marketing-optin">
			<input type="hidden" name="mandrakecrm_marketing_optin_shown" value="1" />
			<label>
				<input
					type="checkbox"
					name="mandrakecrm_marketing_optin"
					id="mandrakecrm_marketing_optin"
					value="yes"
					<?php checked( $checked ); ?>
				/>
				<span><?php echo esc_html( self::get_marketing_label() ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save marketing opt-in value to order.
	 *
	 * Only when the checkbox was shown: an order placed without it (another checkout, the admin,
	 * an import) carries no value, and MandrakeCRM leaves the contact's consent as it is. How the box started is
	 * calculated here on the server with the same filter that renders the box (as WhatsApp and the block checkout):
	 * nothing about it comes from the browser.
	 *
	 * @since 2.0.0
	 * @since 3.25 Saved as '_mandrakecrm_marketing_opt_in', the key MandrakeCRM reads, with how the box started.
	 * @since 3.32 How the box started is calculated on the server. A payment retry on the order already placed changes only
	 *             the answer, as the customer's edit (see retry_classic_answer()).
	 * @param WC_Order $order Order object.
	 * @param array    $data  Posted data.
	 * @since 3.33 Decided per checkbox in a payment retry (see save_whatsapp_optin()).
	 */
	public static function save_marketing_optin( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		if ( ! isset( $_POST['mandrakecrm_marketing_optin_shown'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$optin = isset( $_POST['mandrakecrm_marketing_optin'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['mandrakecrm_marketing_optin'] ) ) ? 'yes' : 'no';

		if ( self::is_resumed_classic_order( $order, self::META_KEY ) ) {
			self::retry_classic_answer( $order, self::META_KEY, $optin );
			return;
		}

		$order->update_meta_data( self::META_KEY, $optin );
		$order->update_meta_data( self::DEFAULT_META_KEY, self::marketing_checkbox_default() ? 'yes' : 'no' );
	}

	/**
	 * Display opt-in status in admin order page.
	 *
	 * @since 2.0.0
	 * @param WC_Order $order Order object.
	 */
	public static function display_optin_in_admin( $order ) {
		$optin = $order->get_meta( self::META_KEY );
		if ( empty( $optin ) ) {
			$optin = $order->get_meta( self::LEGACY_META_KEY );
		}

		if ( empty( $optin ) ) {
			self::display_whatsapp_optin_in_admin( $order );
			return;
		}

		$is_subscribed = 'yes' === $optin;

		?>
		<div class="mandrakecrm-optin-status" style="margin-top: 15px;">
			<p style="margin: 0;">
				<strong style="color: #1e293b;">
					<?php esc_html_e('Marketing Consent:', 'mandrakecrm' ); ?>
				</strong>
				<?php if ( $is_subscribed ) : ?>
					<span style="color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e('Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php else : ?>
					<span style="color: #991b1b; background: #fee2e2; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e('Not Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php endif; ?>
			</p>
		</div>
		<?php
		self::display_whatsapp_optin_in_admin( $order );
	}

	/**
	 * Display the WhatsApp opt-in of the order in the admin order page.
	 *
	 * @since 3.25
	 * @param WC_Order $order Order object.
	 */
	private static function display_whatsapp_optin_in_admin( $order ) {
		$optin = $order->get_meta( self::WHATSAPP_META_KEY );

		if ( empty( $optin ) ) {
			return;
		}
		?>
		<div class="mandrakecrm-optin-status" style="margin-top: 8px;">
			<p style="margin: 0;">
				<strong style="color: #1e293b;">
					<?php esc_html_e( 'WhatsApp Consent:', 'mandrakecrm' ); ?>
				</strong>
				<?php if ( 'yes' === $optin ) : ?>
					<span style="color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e( 'Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php else : ?>
					<span style="color: #991b1b; background: #fee2e2; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 5px;">
						<?php esc_html_e( 'Not Subscribed', 'mandrakecrm' ); ?>
					</span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get marketing consent for an order.
	 *
	 * @since 2.0.0
	 * @param int|WC_Order $order Order ID or object.
	 * @return bool True if customer opted in, false otherwise.
	 */
	public static function get_marketing_consent( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return false;
		}

		$optin = $order->get_meta( self::META_KEY );
		if ( '' === $optin ) {
			$optin = $order->get_meta( self::LEGACY_META_KEY );
		}
		return 'yes' === $optin;
	}

	/**
	 * Get customer marketing consent from their latest order.
	 *
	 * @since 2.0.0
	 * @param int $customer_id Customer ID.
	 * @return bool|null True if opted in, false if not, null if no orders.
	 */
	public static function get_customer_marketing_consent( $customer_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		return self::get_marketing_consent( $orders[0] );
	}
}
