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
	'mandrakecrm_popup_option',
	'mandrakecrm_encryption_key',
	'mandrakecrm_abandoned_cart',
);

// Delete all plugin options.
foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'mandrakecrm_daily_sync' );

// Delete any transients.
delete_transient( 'mandrakecrm_token_verified' );
delete_transient( 'mandrakecrm_store_info' );
delete_transient( 'mandrakecrm_email_failures' );
delete_transient( 'mandrakecrm_show_api_failure_notice' );

// Delete abandoned cart table.
global $wpdb;
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
