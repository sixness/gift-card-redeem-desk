<?php
/**
 * Uninstall: data is kept unless "Delete settings and the transaction log" is ticked.
 * Gift card balances live in WooCommerce coupons and are never touched here.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$gcrd_settings = get_option( 'gcrd_settings', array() );
if ( is_array( $gcrd_settings ) && isset( $gcrd_settings['delete_on_uninstall'] ) && 'yes' === $gcrd_settings['delete_on_uninstall'] ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gcrd_transactions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
	delete_option( 'gcrd_settings' );
	delete_option( 'gcrd_db_version' );
	delete_option( 'gcrd_flush_rewrite' );
	remove_role( 'gcrd_staff' );
	remove_role( 'gcrd_manager' );
	foreach ( array( 'administrator', 'shop_manager' ) as $gcrd_role_name ) {
		$gcrd_role = get_role( $gcrd_role_name );
		if ( $gcrd_role ) {
			foreach ( array( 'gcrd_redeem', 'gcrd_recharge', 'gcrd_void', 'gcrd_reports' ) as $gcrd_cap ) {
				$gcrd_role->remove_cap( $gcrd_cap );
			}
		}
	}
}
