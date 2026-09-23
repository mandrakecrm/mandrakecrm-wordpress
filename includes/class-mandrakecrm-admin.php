<?php
/**
 * MandrakeCRM Admin
 *
 * Handles the admin settings page and configuration.
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
 * Admin class.
 *
 * @since 2.0.0
 */
class MandrakeCRM_Admin {

	/**
	 * Initialize admin hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), PHP_INT_MAX );
		add_action( 'admin_menu', array( __CLASS__, 'force_sidebar_menu_last' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'force_admin_bar_last' ), PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
		add_action( 'wp_ajax_mandrakecrm_verify_token', array( __CLASS__, 'ajax_verify_token' ) );
		add_action( 'wp_ajax_mandrakecrm_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_mandrakecrm_disconnect_integration', array( __CLASS__, 'ajax_disconnect_integration' ) );
		add_action( 'admin_init', array( __CLASS__, 'remove_third_party_notices' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'display_api_failure_notice' ) );
	}

	/**
	 * Remove third-party admin notices from MandrakeCRM page.
	 *
	 * Executes early in admin_init to remove notices before they're rendered.
	 *
	 * @since 2.1.1
	 */
	public static function remove_third_party_notices() {
		// Only on MandrakeCRM admin page
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && 'mandrakecrm' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			// Remove all third-party admin notices - WooCommerce-style approach
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	/**
	 * Add menu page in WordPress sidebar.
	 *
	 * @since 2.0.0
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'woocommerce',                                   // Parent: WooCommerce menu
			__('MandrakeCRM', 'mandrakecrm' ),              // Page title
			__('MandrakeCRM', 'mandrakecrm' ),              // Menu title
			'manage_woocommerce',                            // Capability
			'mandrakecrm',                                   // Menu slug
			array( __CLASS__, 'render_settings_page' ),      // Callback
			99999.9                                          // Position: always last in menu
		);
	}

	/**
	 * Force MandrakeCRM to appear last in admin bar.
	 *
	 * Uses wp_before_admin_bar_render hook with PHP_INT_MAX priority.
	 * This ensures execution AFTER all other plugins, including UpdraftPlus (priority 999),
	 * guaranteeing our item appears last in the admin toolbar.
	 *
	 * @since 2.1.2
	 */
	public static function force_admin_bar_last() {
		global $wp_admin_bar;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Remove nodes if they already exist
		$wp_admin_bar->remove_node( 'mandrakecrm-menu' );
		$wp_admin_bar->remove_node( 'mandrakecrm-dashboard' );
		$wp_admin_bar->remove_node( 'mandrakecrm-settings' );

		// Re-create nodes (this appends them to the end of the admin bar)
		$svg_path = MANDRAKECRM_PLUGIN_DIR . 'assets/images/logo-sidebar.svg';
		$logo_svg = '';

		if ( file_exists( $svg_path ) ) {
			$logo_svg = file_get_contents( $svg_path );
			$logo_svg = str_replace(
				'<svg',
				'<svg style="width:20px;height:20px;display:inline-block;vertical-align:middle;position:relative;top:-1px;"',
				$logo_svg
			);
		}

		// Parent node
		$wp_admin_bar->add_node(
			array(
				'id'     => 'mandrakecrm-menu',
				'title'  => '<span class="ab-icon" aria-hidden="true">' . $logo_svg . '</span>' .
							'<span class="ab-label" aria-hidden="true">' . __('MandrakeCRM', 'mandrakecrm' ) . '</span>' .
							'<span class="screen-reader-text">' . __('MandrakeCRM Dashboard & Settings', 'mandrakecrm' ) . '</span>',
				'href'   => false,
				'meta'   => array(
					'class' => 'mandrakecrm-admin-bar-link',
				),
			)
		);

		// Child node - Dashboard
		$wp_admin_bar->add_node(
			array(
				'parent' => 'mandrakecrm-menu',
				'id'     => 'mandrakecrm-dashboard',
				'title'  => __('Dashboard', 'mandrakecrm' ),
				'href'   => 'https://app.mandrakecrm.io',
				'meta'   => array(
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
					'title'  => __('Open MandrakeCRM Dashboard', 'mandrakecrm' ),
				),
			)
		);

		// Child node - Settings
		$wp_admin_bar->add_node(
			array(
				'parent' => 'mandrakecrm-menu',
				'id'     => 'mandrakecrm-settings',
				'title'  => __('Settings', 'mandrakecrm' ),
				'href'   => admin_url( 'admin.php?page=mandrakecrm' ),
				'meta'   => array(
					'title' => __('Plugin Settings', 'mandrakecrm' ),
				),
			)
		);
	}

	/**
	 * Force MandrakeCRM to appear last in WooCommerce sidebar menu.
	 *
	 * Manipulates global $submenu array to move MandrakeCRM to the end,
	 * ensuring it appears last even if other plugins register after us.
	 *
	 * @since 2.1.2
	 */
	public static function force_sidebar_menu_last() {
		global $submenu;

		// Verify WooCommerce submenu exists
		if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return;
		}

		$mandrakecrm_item = null;
		$mandrakecrm_key = null;

		// Find MandrakeCRM in the array
		foreach ( $submenu['woocommerce'] as $key => $item ) {
			if ( isset( $item[2] ) && $item[2] === 'mandrakecrm' ) {
				$mandrakecrm_item = $item;
				$mandrakecrm_key = $key;
				break;
			}
		}

		// If found, remove and re-append to ensure last position
		if ( $mandrakecrm_item !== null ) {
			unset( $submenu['woocommerce'][$mandrakecrm_key] );
			$submenu['woocommerce'][] = $mandrakecrm_item;
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 2.0.0
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		// Load admin bar styles on all admin pages
		wp_enqueue_style(
			'mandrakecrm-admin',
			MANDRAKECRM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MANDRAKECRM_VERSION
		);

		// Load page-specific scripts only on settings page
		if ( 'woocommerce_page_mandrakecrm' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'mandrakecrm-admin',
			MANDRAKECRM_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MANDRAKECRM_VERSION,
			true
		);

		wp_localize_script(
			'mandrakecrm-admin',
			'mandrakecrm',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mandrakecrm_admin' ),
				'i18n'     => array(
					'connecting'               => __('Connecting...', 'mandrakecrm' ),
					'saving'                   => __('Saving...', 'mandrakecrm' ),
					'connected'                => __('Connected', 'mandrakecrm' ),
					'disconnected'             => __('Disconnected', 'mandrakecrm' ),
					'error'                    => __('Error', 'mandrakecrm' ),
					'saved'                    => __('Settings saved', 'mandrakecrm' ),
					'disconnecting'            => __('Disconnecting...', 'mandrakecrm' ),
					'disconnect_success'       => __('Integration disconnected successfully. Redirecting...', 'mandrakecrm' ),
					'connect_button'           => __('Connect', 'mandrakecrm' ),
					/* translators: %s: store name */
					'connected_to'             => __('Connected to %s', 'mandrakecrm' ),
					'save_changes_button'      => __('Save Changes', 'mandrakecrm' ),
					'yes_disconnect_button'    => __('Yes, Disconnect', 'mandrakecrm' ),
					'disconnect_error'         => __('Error disconnecting integration', 'mandrakecrm' ),
				),
			)
		);
	}

	/**
	 * Enqueue admin bar styles on frontend.
	 *
	 * @since 2.0.1
	 */
	public static function enqueue_frontend_styles() {
		// Only load if admin bar is showing
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		wp_enqueue_style(
			'mandrakecrm-admin-bar',
			MANDRAKECRM_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MANDRAKECRM_VERSION
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @since 2.0.0
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__('You do not have permission to access this page.', 'mandrakecrm' ) );
		}

		$token                = get_option( 'mandrakecrm_token', '' );
		$transactional_emails = get_option( 'mandrakecrm_transactional_emails', '0' );
		$widget_option        = get_option( 'mandrakecrm_widget_option', '0' );

		$connection_status = array(
			'connected'  => false,
			'store_name' => '',
		);

		if ( ! empty( $token ) ) {
			$verify = MandrakeCRM_API_Client::verify_token( $token );
			if ( ! empty( $verify['valid'] ) && true === $verify['valid'] ) {
				$connection_status['connected']  = true;
				$connection_status['store_name'] = isset( $verify['store_name'] ) ? $verify['store_name'] : '';
			}
		}

		?>
		<div class="wrap mandrakecrm-admin-page">
			<div class="mandrakecrm-notices-container"></div>
			<div class="mandrakecrm-wrap">
				<header class="mandrakecrm-header" role="banner" aria-label="<?php esc_attr_e('MandrakeCRM Plugin Header', 'mandrakecrm' ); ?>">
					<div class="mandrakecrm-logo-container">
						<img src="<?php echo esc_url( MANDRAKECRM_PLUGIN_URL . 'assets/images/logo.svg' ); ?>"
							 alt="<?php esc_attr_e('MandrakeCRM Logo', 'mandrakecrm' ); ?>"
							 class="mandrakecrm-logo"
							 width="64"
							 height="57">
					</div>

					<div class="mandrakecrm-header-content">
						<h1 class="mandrakecrm-title"><?php esc_html_e('MandrakeCRM', 'mandrakecrm' ); ?></h1>
						<p class="mandrakecrm-subtitle"><?php esc_html_e('Sell more in your e-commerce without being a marketing expert', 'mandrakecrm' ); ?></p>

						<div class="mandrakecrm-status-badge <?php echo $connection_status['connected'] ? 'is-connected' : 'is-disconnected'; ?>"
							 role="status"
							 aria-live="polite">
							<span class="status-indicator" aria-hidden="true"></span>
							<span class="status-text">
								<?php
								if ( $connection_status['connected'] ) {
									printf(
										/* translators: %s: store name */
										esc_html__('Connected to %s', 'mandrakecrm' ),
										esc_html( $connection_status['store_name'] )
									);
								} else {
									esc_html_e('Disconnected', 'mandrakecrm' );
								}
								?>
							</span>
						</div>
					</div>
				</header>

			<div class="mandrakecrm-card">
				<h2 class="mandrakecrm-card__title">
					<span class="dashicons dashicons-admin-network"></span>
					<?php esc_html_e('Integration', 'mandrakecrm' ); ?>
				</h2>

				<div class="mandrakecrm-field">
					<label for="mandrakecrm_token"><?php esc_html_e('Token', 'mandrakecrm' ); ?></label>
					<div class="mandrakecrm-field__row">
						<input
							type="password"
							id="mandrakecrm_token"
							value="<?php echo esc_attr( $token ); ?>"
							class="mandrakecrm-input"
							placeholder="<?php esc_attr_e('Enter your MandrakeCRM token', 'mandrakecrm' ); ?>"
							<?php echo $connection_status['connected'] ? 'readonly' : ''; ?>
						/>

						<?php if ( ! $connection_status['connected'] ) : ?>
						<button type="button" id="mandrakecrm-verify-token" class="mandrakecrm-btn mandrakecrm-btn--primary">
							<?php esc_html_e('Connect', 'mandrakecrm' ); ?>
						</button>
						<?php else : ?>
						<button type="button" id="disconnect-integration" class="mandrakecrm-btn mandrakecrm-btn--danger">
							<span class="dashicons dashicons-dismiss"></span>
							<?php esc_html_e('Disconnect', 'mandrakecrm' ); ?>
						</button>
						<?php endif; ?>
					</div>
					<p class="mandrakecrm-field__help">
						<?php
						printf(
							/* translators: %s: link to MandrakeCRM website */
							esc_html__( "Don't have an account yet? %s for a 7-day free trial and start selling more!", 'mandrakecrm' ),
							'<a href="https://www.mandrakecrm.com" target="_blank" rel="noopener">' . esc_html__('Sign up', 'mandrakecrm' ) . '</a>'
						);
						?>
					</p>
					<p class="mandrakecrm-field__help">
						<?php
						printf(
							/* translators: %s: link to get token */
							esc_html__('Already have an account? Get your token at %s', 'mandrakecrm' ),
							'<a href="https://app.mandrakecrm.io/en/settings/?tab=store" target="_blank" rel="noopener">' . esc_html__('Settings &rarr; Store', 'mandrakecrm' ) . '</a>'
						);
						?>
					</p>
					<div id="mandrakecrm-token-status" class="mandrakecrm-token-status" style="display:none;"></div>
				</div>
			</div>

			<?php if ( $connection_status['connected'] ) : ?>
			<form id="mandrakecrm-settings-form">
				<?php wp_nonce_field( 'mandrakecrm_save_settings', 'mandrakecrm_nonce' ); ?>

				<div class="mandrakecrm-card">
					<h2 class="mandrakecrm-card__title">
						<span class="dashicons dashicons-admin-plugins"></span>
						<?php esc_html_e('Features', 'mandrakecrm' ); ?>
					</h2>

				<!-- Abandoned Cart Recovery -->
				<div class="mandrakecrm-feature">
					<div class="mandrakecrm-feature__info">
						<h3><?php esc_html_e('Abandoned Cart Recovery', 'mandrakecrm' ); ?></h3>
						<p><?php esc_html_e('Automatically recover lost sales with targeted emails to customers who left items in their cart.', 'mandrakecrm' ); ?></p>
					</div>
					<label class="mandrakecrm-toggle">
						<input
							type="checkbox"
							name="mandrakecrm_abandoned_cart"
							value="1"
							<?php checked( get_option( 'mandrakecrm_abandoned_cart', '0' ), '1' ); ?>
						/>
						<span class="mandrakecrm-toggle__slider"></span>
					</label>
				</div>
					<div class="mandrakecrm-feature">
						<div class="mandrakecrm-feature__info">
							<h3><?php esc_html_e('Transactional Emails', 'mandrakecrm' ); ?></h3>
							<p><?php esc_html_e('Replace default WooCommerce emails with beautiful MandrakeCRM templates: New Account, Processing, On Hold, Completed, Customer Note, Refunded, Cancelled, Failed, and Password Reset.', 'mandrakecrm' ); ?></p>
						</div>
						<label class="mandrakecrm-toggle">
							<input
								type="checkbox"
								name="mandrakecrm_transactional_emails"
								value="1"
								<?php checked( $transactional_emails, '1' ); ?>
							/>
							<span class="mandrakecrm-toggle__slider"></span>
						</label>
					</div>

					<div class="mandrakecrm-feature">
						<div class="mandrakecrm-feature__info">
							<h3><?php esc_html_e('Smart Lead Capture Widgets', 'mandrakecrm' ); ?></h3>
							<p><?php esc_html_e('Convert visitors into customers with smart widgets: lead capture popups, WhatsApp buttons, and more.', 'mandrakecrm' ); ?></p>
						</div>
						<label class="mandrakecrm-toggle">
							<input
								type="checkbox"
								name="mandrakecrm_widget_option"
								value="1"
								<?php checked( $widget_option, '1' ); ?>
							/>
							<span class="mandrakecrm-toggle__slider"></span>
						</label>
					</div>

					<div class="mandrakecrm-feature mandrakecrm-feature--readonly">
						<div class="mandrakecrm-feature__info">
							<h3><?php esc_html_e('Checkout Marketing Consent', 'mandrakecrm' ); ?></h3>
							<p><?php esc_html_e('Grow your subscriber list by collecting marketing consent during checkout.', 'mandrakecrm' ); ?></p>
						</div>
						<span class="mandrakecrm-badge mandrakecrm-badge--success"><?php esc_html_e('Always On', 'mandrakecrm' ); ?></span>
					</div>

					<div class="mandrakecrm-feature mandrakecrm-feature--readonly">
						<div class="mandrakecrm-feature__info">
							<h3><?php esc_html_e('Marketing Attribution Tracking', 'mandrakecrm' ); ?></h3>
							<p><?php esc_html_e('Track UTM parameters to measure campaign ROI and attribute sales to your MandrakeCRM campaigns.', 'mandrakecrm' ); ?></p>
						</div>
						<span class="mandrakecrm-badge mandrakecrm-badge--success"><?php esc_html_e('Always On', 'mandrakecrm' ); ?></span>
					</div>

					<div class="mandrakecrm-actions-row" style="margin-top: 24px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: flex-end;">
						<button type="submit" class="mandrakecrm-btn mandrakecrm-btn--primary">
							<?php esc_html_e('Save Changes', 'mandrakecrm' ); ?>
						</button>
						<span id="mandrakecrm-save-status" class="mandrakecrm-save-status"></span>
					</div>
				</div>
			</form>
			<?php endif; ?>

				<div class="mandrakecrm-card">
					<h2 class="mandrakecrm-card__title">
						<span class="dashicons dashicons-editor-help"></span>
						<?php esc_html_e('Support & Resources', 'mandrakecrm' ); ?>
					</h2>

					<div class="mandrakecrm-links">
						<div class="mandrakecrm-link">
							<strong><?php esc_html_e('Dashboard:', 'mandrakecrm' ); ?></strong>
							<a href="https://app.mandrakecrm.io" target="_blank" rel="noopener">app.mandrakecrm.io</a>
						</div>
						<div class="mandrakecrm-link">
							<strong><?php esc_html_e('Website:', 'mandrakecrm' ); ?></strong>
							<a href="https://www.mandrakecrm.com" target="_blank" rel="noopener">www.mandrakecrm.com</a>
						</div>
						<div class="mandrakecrm-link">
							<strong><?php esc_html_e('Support:', 'mandrakecrm' ); ?></strong>
							<a href="mailto:support@mandrakecrm.com">support@mandrakecrm.com</a>
						</div>
					</div>
				</div>

			<div class="mandrakecrm-footer">
				<p>
					<?php
					printf(
						/* translators: %1$s: year, %2$s: plugin version */
						esc_html__('%1$s &bull; MandrakeCRM v%2$s', 'mandrakecrm' ),
						esc_html( gmdate( 'Y' ) ),
						esc_html( MANDRAKECRM_VERSION )
					);
					?>
				</p>
			</div>

		<!-- Disconnect Confirmation Modal -->
		<div id="mandrakecrm-disconnect-modal" class="mandrakecrm-modal" style="display:none;">
			<div class="mandrakecrm-modal-overlay"></div>
			<div class="mandrakecrm-modal-container">
				<div class="mandrakecrm-modal-header">
					<h2><?php esc_html_e('Are you sure you want to disconnect?', 'mandrakecrm' ); ?></h2>
					<button type="button" class="mandrakecrm-modal-close" aria-label="<?php esc_attr_e('Close', 'mandrakecrm' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>

				<div class="mandrakecrm-modal-body">
					<div class="mandrakecrm-modal-warning">
						<p class="lead">
							<strong><?php esc_html_e('Warning: Disconnecting will hurt your sales', 'mandrakecrm' ); ?></strong>
						</p>

						<ul class="mandrakecrm-warning-list">
							<li>
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( "You'll lose automated emails that drive repeat purchases", 'mandrakecrm' ); ?>
							</li>
							<li>
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( "You'll stop capturing new leads and potential customers", 'mandrakecrm' ); ?>
							</li>
							<li>
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( "You'll lose visibility into what's actually working to drive sales", 'mandrakecrm' ); ?>
							</li>
						</ul>

						<p class="mandrakecrm-modal-note">
							<strong><?php esc_html_e('Without MandrakeCRM, you could be leaving money on the table every single day.', 'mandrakecrm' ); ?></strong>
						</p>
					</div>
				</div>

				<div class="mandrakecrm-modal-footer">
					<button type="button" class="mandrakecrm-btn mandrakecrm-btn--secondary mandrakecrm-modal-cancel">
						<?php esc_html_e('Cancel', 'mandrakecrm' ); ?>
					</button>
					<button type="button" class="mandrakecrm-btn mandrakecrm-btn--danger" id="confirm-disconnect">
						<span class="dashicons dashicons-dismiss"></span>
						<?php esc_html_e('Disconnect Anyway', 'mandrakecrm' ); ?>
					</button>
				</div>
			</div>
		</div>

			</div><!-- .mandrakecrm-wrap -->
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * AJAX handler for token verification.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_verify_token() {
		check_ajax_referer( 'mandrakecrm_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __('Permission denied', 'mandrakecrm' ) ) );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __('Token is required', 'mandrakecrm' ) ) );
		}

		$result = MandrakeCRM_API_Client::verify_token( $token );

		if ( ! empty( $result['valid'] ) && true === $result['valid'] ) {
			// Token valid - save immediately
			update_option( 'mandrakecrm_token', $token );

			// Save widget_token for CDN popup URL
			if ( ! empty( $result['widget_token'] ) ) {
				update_option( 'mandrakecrm_widget_token', sanitize_text_field( $result['widget_token'] ) );
			}

			// Send heartbeat to update plugin_status with token_verified: true
			MandrakeCRM_API_Client::sync_status();

			wp_send_json_success(
				array(
					'message'    => __('Token verified successfully', 'mandrakecrm' ),
					'store_name' => isset( $result['store_name'] ) ? $result['store_name'] : '',
				)
			);
		} else {
			// Token invalid - DELETE only the token, keep options
			delete_option( 'mandrakecrm_token' );
			delete_option( 'mandrakecrm_widget_token' );

			$error = isset( $result['error'] ) ? $result['error'] : __('Invalid token', 'mandrakecrm' );
			wp_send_json_error( array( 'message' => $error ) );
		}
	}

	/**
	 * AJAX handler for saving settings.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_save_settings() {
		check_ajax_referer( 'mandrakecrm_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __('Permission denied', 'mandrakecrm' ) ) );
		}

		// Only process options - token is NOT saved here
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$transactional_emails = isset( $_POST['mandrakecrm_transactional_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['mandrakecrm_transactional_emails'] ) ) : '0';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$widget_option        = isset( $_POST['mandrakecrm_widget_option'] ) ? sanitize_text_field( wp_unslash( $_POST['mandrakecrm_widget_option'] ) ) : '0';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$abandoned_cart       = isset( $_POST['mandrakecrm_abandoned_cart'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['mandrakecrm_abandoned_cart'] ) ) ? '1' : '0';

		// Logging for debug
		error_log( 'MandrakeCRM: Saving settings - transactional_emails=' . $transactional_emails . ', widget=' . $widget_option . ', abandoned_cart=' . $abandoned_cart );

		// Save options only (token stays as-is from verification)
		update_option( 'mandrakecrm_transactional_emails', $transactional_emails );
		update_option( 'mandrakecrm_widget_option', $widget_option );
		update_option( 'mandrakecrm_abandoned_cart', $abandoned_cart );

		// Verify that options were saved correctly
		$verify_transactional = get_option( 'mandrakecrm_transactional_emails' );
		$verify_widget        = get_option( 'mandrakecrm_widget_option' );
		$verify_abandoned_cart = get_option( 'mandrakecrm_abandoned_cart' );

		error_log( 'MandrakeCRM: Verified - transactional_emails=' . $verify_transactional . ', widget=' . $verify_widget . ', abandoned_cart=' . $verify_abandoned_cart );

		if ( $verify_transactional !== $transactional_emails || $verify_widget !== $widget_option || $verify_abandoned_cart !== $abandoned_cart ) {
			error_log( 'MandrakeCRM: ERROR - Options not saved correctly!' );
			wp_send_json_error( array( 'message' => __('Failed to save settings', 'mandrakecrm' ) ) );
			return;
		}

		// Always sync status after saving settings if token exists
		$token = get_option( 'mandrakecrm_token', '' );
		if ( ! empty( $token ) ) {
			MandrakeCRM_API_Client::sync_status();
		}

		wp_send_json_success( array( 'message' => __('Settings saved successfully', 'mandrakecrm' ) ) );
	}

	/**
	 * AJAX handler for disconnecting integration.
	 *
	 * @since 2.0.0
	 */
	public static function ajax_disconnect_integration() {
		check_ajax_referer( 'mandrakecrm_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __('Permission denied', 'mandrakecrm' ) )
			);
		}

		// Notify backend BEFORE disconnecting
		$token = get_option( 'mandrakecrm_token', '' );
		if ( ! empty( $token ) ) {
			MandrakeCRM_API_Client::notify_disconnection();
		}

		// Remove tokens only - preserve feature options for reconnection
		delete_option( 'mandrakecrm_token' );
		delete_option( 'mandrakecrm_widget_token' );

		// Logging
		error_log( 'MandrakeCRM: Integration disconnected by user' );

		wp_send_json_success(
			array( 'message' => __('Integration disconnected successfully', 'mandrakecrm' ) )
		);
	}

	/**
	 * Display admin notice for API failure threshold.
	 *
	 * Shows a warning notice when email API failures exceed the threshold.
	 * Only displays on dashboard and MandrakeCRM pages.
	 *
	 * @since 3.11.0
	 */
	public static function display_api_failure_notice() {
		// Only show on MandrakeCRM pages or dashboard
		$screen = get_current_screen();
		if ( ! $screen || ( 'dashboard' !== $screen->id && 'woocommerce_page_mandrakecrm' !== $screen->id ) ) {
			return;
		}

		// Check if notice should be displayed
		if ( ! get_transient( 'mandrakecrm_show_api_failure_notice' ) ) {
			return;
		}

		$failure_count = get_transient( 'mandrakecrm_email_failures' );
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php esc_html_e('MandrakeCRM API Connection Issue', 'mandrakecrm' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: number of failed email attempts */
					esc_html__('We detected %d failed attempts to send transactional emails via MandrakeCRM API. Your customers are receiving emails via WordPress fallback, but this may indicate a connection issue.', 'mandrakecrm' ),
					(int) $failure_count
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: support email address */
					esc_html__('Please verify your internet connection and MandrakeCRM token. If the issue persists, contact support at %s', 'mandrakecrm' ),
					'<a href="mailto:support@mandrakecrm.com">support@mandrakecrm.com</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
