<?php
/**
 * Plugin Name: MandrakeCRM – CRM & AI Marketing Automation
 * Plugin URI: https://www.mandrakecrm.com
 * Description: Recover abandoned carts. Email marketing campaigns. Track campaign ROI. Connect your store in minutes. Start free 7-day trial.
 * Version: 3.20
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: MandrakeCRM
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mandrakecrm
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 9.5
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
define( 'MANDRAKECRM_VERSION', '3.20' );
define( 'MANDRAKECRM_PLUGIN_FILE', __FILE__ );
define( 'MANDRAKECRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MANDRAKECRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MANDRAKECRM_API_BASE', 'https://mandrakecrm.supabase.co/functions/v1' );
define( 'MANDRAKECRM_CDN_BASE', 'https://cdn.mandrakecrm.io' );

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
	require_once MANDRAKECRM_PLUGIN_DIR . 'includes/class-mandrakecrm-cashback.php';

	MandrakeCRM_Admin::init();
	MandrakeCRM_Emails::init();
	MandrakeCRM_UTM::init();
	MandrakeCRM_Widget::init();
	MandrakeCRM_Checkout::init();
	MandrakeCRM_Abandoned_Cart::init();
	MandrakeCRM_Cashback::init();

	// Enqueue checkout styles
	add_action( 'wp_enqueue_scripts', 'mandrakecrm_enqueue_checkout_styles' );
}
add_action( 'plugins_loaded', 'mandrakecrm_init' );

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

	if ( ! wp_next_scheduled( 'mandrakecrm_daily_sync' ) ) {
		wp_schedule_event( time(), 'daily', 'mandrakecrm_daily_sync' );
	}

	$token = get_option( 'mandrakecrm_token' );
	if ( ! empty( $token ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mandrakecrm-api-client.php';
		MandrakeCRM_API_Client::sync_status();
	}

	// Create abandoned cart table and schedule cron
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mandrakecrm-abandoned-cart.php';
	MandrakeCRM_Abandoned_Cart::activate();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mandrakecrm_activate' );

/**
 * Plugin deactivation.
 *
 * @since 2.0.0
 */
function mandrakecrm_deactivate() {
	wp_clear_scheduled_hook( 'mandrakecrm_daily_sync' );

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
