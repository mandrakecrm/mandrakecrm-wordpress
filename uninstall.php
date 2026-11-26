<?php
/**
 * MandrakeCRM Uninstall
 *
 * Cleans up all plugin data when the plugin is deleted.
 * This file is executed when the plugin is deleted from WordPress.
 *
 * @package    MandrakeCRM
 * @author     MandrakeCRM <hello@mandrakecrm.com>
 * @copyright  2024-2026 MandrakeCRM
 * @license    GPL-2.0-or-later
 * @link       https://www.mandrakecrm.com
 * @since      2.0.0
 */

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Plugin options to delete.
$options = array(
	'mandrakecrm_token',
	'mandrakecrm_transactional_emails',
	'mandrakecrm_widget_option',
	'mandrakecrm_widget_token',
	'mandrakecrm_abandoned_cart',
	'mandrakecrm_whatsapp_optin',
	'mandrakecrm_store_name',
	'mandrakecrm_reported_version',
	'mandrakecrm_store_name_checked_at',
);

// Delete all plugin options.
foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'mandrakecrm_daily_sync' );
wp_clear_scheduled_hook( 'mandrakecrm_status_refresh' );
wp_clear_scheduled_hook( 'mandrakecrm_version_report' );
// 3.32: background jobs that wait (order emails, new account emails and their fallback, resumed cart notices), in WP-Cron
// (every event, whatever its arguments) and in Action Scheduler; with WooCommerce inactive, in Action Scheduler's table (as
// mandrakecrm_unschedule_background_jobs() in mandrakecrm.php, which is not loaded here).
$mandrakecrm_hooks = array( 'mandrakecrm_send_order_email', 'mandrakecrm_send_new_account', 'mandrakecrm_new_account_fallback', 'mandrakecrm_send_cart_notice' );
foreach ( $mandrakecrm_hooks as $mandrakecrm_hook ) {
	wp_unschedule_hook( $mandrakecrm_hook );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $mandrakecrm_hook );
	}
}
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	global $wpdb;
	$mandrakecrm_as_table = $wpdb->prefix . 'actionscheduler_actions';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time cleanup on uninstall; values through prepare().
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mandrakecrm_as_table ) ) ) === $mandrakecrm_as_table ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $mandrakecrm_as_table SET status = %s WHERE status = %s AND hook IN (%s, %s, %s, %s)",
				'canceled',
				'pending',
				$mandrakecrm_hooks[0],
				$mandrakecrm_hooks[1],
				$mandrakecrm_hooks[2],
				$mandrakecrm_hooks[3]
			)
		);
	}
	// phpcs:enable
}

// Delete any transients.
delete_transient( 'mandrakecrm_token_verified' );
delete_transient( 'mandrakecrm_store_info' );
delete_transient( 'mandrakecrm_email_failures' );
delete_transient( 'mandrakecrm_show_api_failure_notice' );
delete_transient( 'mandrakecrm_version_report_wait' );
delete_transient( 'mandrakecrm_store_name_refresh_wait' );

// Signatures of the settings notice already used (3.32): options mandrakecrm_sp_<hash>, and transients with the
// same prefix written by an early build of 3.32.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'mandrakecrm_sp_' ) . '%',
		$wpdb->esc_like( '_transient_mandrakecrm_sp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mandrakecrm_sp_' ) . '%'
	)
);

// Delete the abandoned cart cycle kept on each user (3.26).
delete_metadata( 'user', 0, '_mandrakecrm_cart_cycle', '', true );

// Delete abandoned cart table.
$table_name = $wpdb->prefix . 'mandrakecrm_abandoned_carts';
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}

/**
 * Optional: Delete order meta data.
 *
 * Uncomment the code below if you want to remove all UTM and marketing
 * consent data from orders when uninstalling the plugin.
 *
 * Warning: This will permanently delete tracking data from all orders.
 */
/*
global $wpdb;

// Delete from HPOS meta table if it exists.
$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $hpos_meta_table ) ) === $hpos_meta_table ) {
	$wpdb->query( "DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE '_mandrakecrm_%'" );
}

// Delete from post meta table (classic orders).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_mandrakecrm_%'" );
*/
