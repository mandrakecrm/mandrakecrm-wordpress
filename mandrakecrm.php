<?php
/**
 * Plugin Name: MandrakeCRM – CRM & AI Marketing Automation
 * Plugin URI: https://www.mandrakecrm.com
 * Description: Recover abandoned carts. Email marketing campaigns. Track campaign ROI. Connect your store in minutes. Start free 7-day trial.
 * Version: 3.36
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: MandrakeCRM
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mandrakecrm
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 11.3
 *
 * @package    MandrakeCRM
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 *
 * This plugin connects your WooCommerce store to MandrakeCRM services.
 * For documentation and support, visit: https://www.mandrakecrm.com
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
define( 'MANDRAKECRM_VERSION', '3.36' );
define( 'MANDRAKECRM_PLUGIN_FILE', __FILE__ );
define( 'MANDRAKECRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MANDRAKECRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MANDRAKECRM_API_BASE', 'https://mandrakecrm.supabase.co/functions/v1' );
define( 'MANDRAKECRM_CDN_BASE', 'https://cdn.mandrakecrm.io' );

/*
 * Region where the MandrakeCRM API runs (x-region header). The API's database is in sa-east-1 (São Paulo): without
 * the header the API runs in the region closest to the store's server and every database query crosses the
 * continent. Define it earlier (wp-config.php) to change it; an empty string sends no header.
 * @since 3.31
 */
if ( ! defined( 'MANDRAKECRM_API_REGION' ) ) {
	define( 'MANDRAKECRM_API_REGION', 'sa-east-1' );
}

/**
 * Declare WooCommerce compatibility.
 *
 * @since 2.0.0
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
});

/**
 * Check if WooCommerce is active.
 *
 * @since 2.0.0
 * @return bool True if WooCommerce is active, false otherwise.
 */
function mandrakecrm_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'mandrakecrm_woocommerce_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Display WooCommerce missing notice.
 *
 * Modern dismissible notice following WordPress guidelines.
 *
 * @since 2.0.0
 * @since 3.12 Updated to use notice-warning and is-dismissible per WordPress best practices.
 */
function mandrakecrm_woocommerce_missing_notice() {
	$install_url = wp_nonce_url(
		self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ),
		'install-plugin_woocommerce'
	);
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %1$s: MandrakeCRM, %2$s: WooCommerce, %3$s: Install link */
				esc_html__( '%1$s requires %2$s to be installed and active.', 'mandrakecrm' ),
				'<strong>MandrakeCRM</strong>',
				'<strong>WooCommerce</strong>'
			);
			?>
			<a href="<?php echo esc_url( $install_url ); ?>" class="button button-primary" style="margin-left: 10px;">
				<?php esc_html_e( 'Install WooCommerce', 'mandrakecrm' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Initialize the plugin.
 *
 * @since 2.0.0
 */
function mandrakecrm_init() {
	if ( ! mandrakecrm_check_woocommerce() ) {
		return;
	}

	// WordPress.org automatically loads translations for plugins hosted in the directory.
	// No need to call load_plugin_textdomain() since WordPress 4.6+.

	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-api-client.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-admin.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-emails.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-utm.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-widget.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-checkout.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-abandoned-cart.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-agent-cart.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-agent-quote.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-agent-shipping.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-agent-identity.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-cashback-identity.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-settings-push.php';
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-coupon-link.php';

	MandrakeCRM_Admin::init();
	MandrakeCRM_Emails::init();
	MandrakeCRM_UTM::init();
	MandrakeCRM_Widget::init();
	MandrakeCRM_Checkout::init();
	MandrakeCRM_Abandoned_Cart::init();
	MandrakeCRM_Agent_Cart::init();
	MandrakeCRM_Agent_Quote::init();
	MandrakeCRM_Agent_Shipping::init();
	MandrakeCRM_Agent_Identity::init();
	MandrakeCRM_Cashback_Identity::init();
	MandrakeCRM_Settings_Push::init();
	MandrakeCRM_Coupon_Link::init();

	// Enqueue checkout styles
	add_action( 'wp_enqueue_scripts', 'mandrakecrm_enqueue_checkout_styles' );

	mandrakecrm_maybe_schedule_status_refresh();
	mandrakecrm_maybe_report_version();
}
add_action( 'plugins_loaded', 'mandrakecrm_init' );

/**
 * Suggested text for the store's privacy policy (Settings › Privacy › Policy guide): the cookies and the browser storage of the
 * plugin and of the MandrakeCRM widget it loads (sign-up window, WhatsApp button, chat, product offer at checkout and cashback
 * balance), what each one is for and how long it lasts, and what the widget sends. The texts newer than the language pack come from the translation bundled with the plugin (MandrakeCRM_I18n::with_bundled()).
 *
 * @since 3.32
 * @since 3.33 The widget's cookies and storage (also the personal data it keeps), «of this site (first-party)», and the
 *             translation bundled with the plugin.
 * @since 3.34 The other providers the widget loads code from (Cloudflare Turnstile in the chat, jsDelivr in the previous
 *             WhatsApp button), what the chat also sends (the email left in the sign-up window, the page, the cart and the
 *             signed account of a logged-in customer), the chat window's storage is not first-party, and the 3,600 days of
 *             the previous sign-up window's closing cookie; what the product offer at checkout (order bump) and the
 *             cashback balance (the account signed by the store, D13) send; the note for the owner names every part of
 *             the widget and the order bump's email is also the one already filled in for a logged-in customer (review N7).
 */
function mandrakecrm_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-i18n.php';
	MandrakeCRM_I18n::with_bundled( 'mandrakecrm_privacy_policy_content' );
}

/**
 * Build and register the suggested privacy text (see mandrakecrm_add_privacy_policy_content(), which runs it with the
 * translation bundled with the plugin).
 *
 * @since 3.33
 */
function mandrakecrm_privacy_policy_content() {
	// The plugin's own cookies, on every store.
	$plugin = array(
		array(
			array( 'mandrakecrm_optin' ),
			__( 'Remembers whether you ticked or unticked the boxes to receive marketing emails and WhatsApp messages at checkout, so they stay as you left them if the page reloads. It only holds your own clicks on those boxes, with no name, email or other identifier. It is a functional cookie: it lasts 2 hours and is removed when the cart is emptied.', 'mandrakecrm' ),
		),
		array(
			array( 'mandrakecrm_utm' ),
			__( 'Keeps the campaign parameters (utm_source, utm_medium, utm_campaign, utm_term and utm_content) of the last link that brought you to the store, to know which campaign led to your order. It lasts 30 days.', 'mandrakecrm' ),
		),
	);

	// The widget's (packages/widgets and the legacy templates of the widget bundle): the same names in every version.
	$widget = array(
		array(
			array( 'mcrm_popup_closed', 'mcrm_popup_closed_until' ),
			__( 'A cookie and a copy of its expiry date in local storage (some browsers delete cookies written by scripts after 7 days). They remember that you closed the sign-up window, so it does not open again by itself for the period the store chose. With the previous version of the sign-up window, the cookie lasts 3,600 days (about 10 years) and has no copy in local storage.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_user_registered', 'mcrm_user_registered_until' ),
			__( 'A cookie and a copy of its expiry date in local storage. They remember that you already signed up in the sign-up window or with the WhatsApp button, so you are not asked again. They last about 10 years.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_customer_email', 'mcrm_customer_name', 'mcrm_wa_phone' ),
			__( 'Local storage with the email, name and WhatsApp number you entered in the sign-up window or with the WhatsApp button, to fill them in for you next time. They stay in your browser until you delete this site\'s data.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_capture_receipt', 'mcrm_portal_url' ),
			__( 'Local storage with the signed receipt MandrakeCRM returns when you sign up and the link to your preferences page: only with that receipt does MandrakeCRM tell this browser whether your email is already subscribed. They stay until you delete this site\'s data.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_teaser_dock', 'mcrm_merlin_state' ),
			__( 'Local storage without personal data: where you left the tab of the sign-up window and when the chat messages were shown to you, so they are not shown too often. They stay until you delete this site\'s data.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_merlin_sound' ),
			__( 'A cookie set only if you turn the chat sounds off or on: it remembers that choice for 1 year.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_agent_ref' ),
			__( 'Set only when you talk to the store\'s assistant in the chat on this site: it holds the reference of that conversation, to know whether it led to your order. It lasts as many days as the store\'s attribution period.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_merlin_session', 'mcrm_wa_greeted', 'mcrm_merlin_preview' ),
			__( 'Session storage, deleted when you close the tab: the state of the chat and of the WhatsApp greeting in that tab (with a short code derived from your account if you are logged in) and, only for the store\'s team, the chat preview link.', 'mandrakecrm' ),
		),
		array(
			array( 'mcrm_merlin_chat', 'mcrm_merlin_view', 'mcrm_merlin_draft' ),
			__( 'Storage of the chat window, which runs on chat.mandrakecrm.io inside this site\'s pages: the conversation and a code that recognizes this browser in the chat for 30 days (local storage), and how the chat looks and the message you have not sent yet (session storage, deleted with the tab). A new conversation starts after 24 hours without messages; ending the conversation in the chat deletes them.', 'mandrakecrm' ),
		),
	);

	$list = function ( array $items ) {
		$html = '';
		foreach ( $items as $item ) {
			$names = array();
			foreach ( $item[0] as $name ) {
				$names[] = '<code>' . esc_html( $name ) . '</code>';
			}
			$html .= '<li>' . implode( ', ', $names ) . ': ' . esc_html( $item[1] ) . '</li>';
		}
		return '<ul>' . $html . '</ul>';
	};

	$content = '<p class="privacy-policy-tutorial">' . esc_html( __( 'MandrakeCRM sends the store\'s marketing emails, WhatsApp messages and abandoned cart reminders. Suggested text for the cookies and the browser storage of its plugin, and for what the MandrakeCRM widget (sign-up window, WhatsApp button, chat, product offer at checkout and cashback balance) sends and keeps in the browser:', 'mandrakecrm' ) ) . '</p>'
		. '<p>' . esc_html( __( 'This store uses MandrakeCRM for its marketing emails, WhatsApp messages and abandoned cart reminders. These cookies of this site (first-party) may be set in your browser:', 'mandrakecrm' ) ) . '</p>'
		. $list( $plugin )
		. '<p class="privacy-policy-tutorial">' . esc_html( __( 'Keep the following only if the store shows on its pages the MandrakeCRM sign-up window, WhatsApp button, chat, product offer at checkout or cashback balance.', 'mandrakecrm' ) ) . '</p>'
		. '<p>' . esc_html( __( 'The sign-up window, the WhatsApp button and the chat on this site are a MandrakeCRM widget: their code is loaded from MandrakeCRM\'s servers (cdn.mandrakecrm.io, and the chat window from chat.mandrakecrm.io), and what you enter in them (name, email, WhatsApp number, chat messages) is sent to MandrakeCRM, which processes it for this store. When you write in the chat, it also sends the email you left in the sign-up window, the page you are viewing and the products in your cart and, if you are logged in to your account on this store, your customer number and the email of that account, so that you are recognized.', 'mandrakecrm' ) ) . '</p>'
		. '<p>' . esc_html( __( 'On the checkout, the product offer of the MandrakeCRM widget (order bump) sends MandrakeCRM the products in your cart, to choose what to offer, together with the email of the checkout form (the one you type or, if you are logged in, the one already filled in), every time the checkout updates and even if you do not use the offer; if you add the offered product, it also sends that product, its quantity and its value with that email, so the store knows whether the offer led to your order.', 'mandrakecrm' ) ) . '</p>'
		. '<p>' . esc_html( __( 'When you are logged in, the MandrakeCRM cashback balance in "My account" sends MandrakeCRM your customer number and the email of your account, signed by this store, to show your balance and movements and, if you ask for it, to turn your balance into a coupon.', 'mandrakecrm' ) ) . '</p>'
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- privacy text that names these providers; nothing is loaded from here.
		. '<p>' . esc_html( __( 'Some parts load code from other providers, which receive your IP address and your browser\'s details when they deliver it: the chat loads Cloudflare Turnstile (challenges.cloudflare.com), a check against abuse, when you send your first message; and the previous version of the WhatsApp button loads its phone number field and its QR code from jsDelivr (cdn.jsdelivr.net) when you open it.', 'mandrakecrm' ) ) . '</p>'
		. '<p>' . esc_html( __( 'The widget keeps these items in your browser. They are data of this site (first-party), except those of the chat window, which are kept by chat.mandrakecrm.io, MandrakeCRM\'s chat domain:', 'mandrakecrm' ) ) . '</p>'
		. $list( $widget );
	wp_add_privacy_policy_content( 'MandrakeCRM', wp_kses_post( $content ) );
}
add_action( 'admin_init', 'mandrakecrm_add_privacy_policy_content' );

/**
 * Tell MandrakeCRM the new version once, in the background, after any update.
 *
 * An update does not run the activation hook, and `upgrader_process_complete` runs with the code of the
 * version being replaced (and not at all for manual or FTP updates). So the plugin remembers the last
 * version it reported and, when the running version differs, schedules one background status sync
 * (WP-Cron, 10 s later). It never calls the API on the visitor's request and never blocks the update.
 * After a failed sync it waits an hour before trying again; the daily sync remains the fallback.
 *
 * @since 3.31
 */
function mandrakecrm_maybe_report_version() {
	if ( MANDRAKECRM_VERSION === get_option( 'mandrakecrm_reported_version', '' ) ) {
		return;
	}
	if ( '' === (string) get_option( 'mandrakecrm_token', '' ) || get_transient( 'mandrakecrm_version_report_wait' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'mandrakecrm_version_report' ) ) {
		wp_schedule_single_event( time() + 10, 'mandrakecrm_version_report' );
	}
}

/**
 * Background status sync after an update (see mandrakecrm_maybe_report_version()).
 *
 * @since 3.31
 */
add_action( 'mandrakecrm_version_report', function() {
	$token = get_option( 'mandrakecrm_token' );
	if ( empty( $token ) ) {
		return;
	}
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-api-client.php';
	if ( MandrakeCRM_API_Client::sync_status() ) {
		update_option( 'mandrakecrm_reported_version', MANDRAKECRM_VERSION );
		delete_transient( 'mandrakecrm_version_report_wait' );
	} else {
		set_transient( 'mandrakecrm_version_report_wait', 1, HOUR_IN_SECONDS );
	}
});

/**
 * After an update finishes in this same request (core screen, auto-updates, WP-CLI), forget the reported
 * version so the next load schedules the background sync above (activation already syncs on its own).
 * Nothing waits for the API.
 *
 * @since 3.31
 */
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
	if ( ! is_array( $options ) || ( $options['type'] ?? '' ) !== 'plugin' ) {
		return;
	}
	$plugins = (array) ( $options['plugins'] ?? array() );
	if ( isset( $options['plugin'] ) ) {
		$plugins[] = $options['plugin'];
	}
	if ( in_array( plugin_basename( MANDRAKECRM_PLUGIN_FILE ), $plugins, true ) ) {
		delete_option( 'mandrakecrm_reported_version' );
		delete_transient( 'mandrakecrm_version_report_wait' );
	}
}, 10, 2 );

/**
 * After an update, ask MandrakeCRM once for the account status (in the background).
 *
 * Since 3.25 the WhatsApp checkbox shows the store name in MandrakeCRM, a value only the API reports.
 * Stores that update the plugin would otherwise wait for the daily sync (until then the label uses the
 * site name); this schedules a single background sync instead. It never calls the API on the visitor's
 * request; if that sync fails, the daily sync (or opening the settings page) updates the value later.
 *
 * @since 3.25
 */
function mandrakecrm_maybe_schedule_status_refresh() {
	if ( false !== get_option( 'mandrakecrm_store_name', false ) ) {
		return;
	}
	// Empty until the API answers; storing it also keeps this check to a single autoloaded read.
	add_option( 'mandrakecrm_store_name', '' );
	if ( '' === (string) get_option( 'mandrakecrm_token', '' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'mandrakecrm_status_refresh' ) ) {
		wp_schedule_single_event( time() + 30, 'mandrakecrm_status_refresh' );
	}
}

/**
 * One-time background status sync (see mandrakecrm_maybe_schedule_status_refresh()).
 *
 * @since 3.25
 */
add_action( 'mandrakecrm_status_refresh', function() {
	$token = get_option( 'mandrakecrm_token' );
	if ( ! empty( $token ) ) {
		require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-api-client.php';
		MandrakeCRM_API_Client::sync_status();
	}
});

/**
 * Enqueue checkout styles for marketing opt-in checkbox.
 *
 * @since 2.1.5
 */
function mandrakecrm_enqueue_checkout_styles() {
	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		wp_enqueue_style(
			'mandrakecrm-checkout',
			MANDRAKECRM_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			MANDRAKECRM_VERSION
		);
	}
}

/**
 * Plugin activation.
 *
 * @since 2.0.0
 */
function mandrakecrm_activate() {
	add_option( 'mandrakecrm_token', '' );
	add_option( 'mandrakecrm_transactional_emails', '0' );
	add_option( 'mandrakecrm_widget_option', '0' );
	add_option( 'mandrakecrm_whatsapp_optin', '1' );

	if ( ! wp_next_scheduled( 'mandrakecrm_daily_sync' ) ) {
		wp_schedule_event( time(), 'daily', 'mandrakecrm_daily_sync' );
	}

	$token = get_option( 'mandrakecrm_token' );
	if ( ! empty( $token ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mandrakecrm-api-client.php';
		if ( MandrakeCRM_API_Client::sync_status() ) {
			update_option( 'mandrakecrm_reported_version', MANDRAKECRM_VERSION );
		}
	}

	// Create abandoned cart table and schedule cron
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mandrakecrm-abandoned-cart.php';
	MandrakeCRM_Abandoned_Cart::activate();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mandrakecrm_activate' );

/**
 * Remove the plugin's background jobs that wait: in WP-Cron (every event, whatever its arguments) and in Action Scheduler.
 *
 * With WooCommerce inactive, Action Scheduler's functions are not loaded but its table stays: the pending actions of these
 * hooks are canceled there, as as_unschedule_all_actions() does, so none runs when WooCommerce comes back. uninstall.php
 * does the same (it runs without this file).
 *
 * @since 3.32
 */
function mandrakecrm_unschedule_background_jobs() {
	$hooks = array( 'mandrakecrm_send_order_email', 'mandrakecrm_send_new_account', 'mandrakecrm_new_account_fallback', 'mandrakecrm_send_cart_notice' );
	foreach ( $hooks as $hook ) {
		wp_unschedule_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Action Scheduler's own table while its code is not loaded (WooCommerce inactive); values through prepare().
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		return;
	}
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE $table SET status = %s WHERE status = %s AND hook IN (%s, %s, %s, %s)",
			'canceled',
			'pending',
			$hooks[0],
			$hooks[1],
			$hooks[2],
			$hooks[3]
		)
	);
	// phpcs:enable
}

/**
 * Plugin deactivation.
 *
 * @since 2.0.0
 */
function mandrakecrm_deactivate() {
	wp_clear_scheduled_hook( 'mandrakecrm_daily_sync' );
	wp_clear_scheduled_hook( 'mandrakecrm_status_refresh' );
	wp_clear_scheduled_hook( 'mandrakecrm_version_report' );

	// 3.32: the background jobs that wait (order emails, new account emails and their fallback, resumed cart notices), in
	// Action Scheduler and in WP-Cron.
	mandrakecrm_unschedule_background_jobs();

	$token = get_option( 'mandrakecrm_token' );
	if ( ! empty( $token ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mandrakecrm-api-client.php';
		MandrakeCRM_API_Client::notify_deactivation();
	}

	// Clear abandoned cart cron
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mandrakecrm-abandoned-cart.php';
	MandrakeCRM_Abandoned_Cart::deactivate();
}
register_deactivation_hook( __FILE__, 'mandrakecrm_deactivate' );

/**
 * Daily sync cron handler.
 *
 * @since 2.0.0
 */
add_action( 'mandrakecrm_daily_sync', function() {
	$token = get_option( 'mandrakecrm_token' );
	if ( ! empty( $token ) ) {
		require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-api-client.php';
		MandrakeCRM_API_Client::sync_status();
	}
});

/**
 * Add plugin action links.
 *
 * @since 2.0.0
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=mandrakecrm' ) ) . '">' . esc_html__( 'Settings', 'mandrakecrm' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
});
