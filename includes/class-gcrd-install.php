<?php
/**
 * Activation: transaction table, roles and capabilities.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_Install {

	const CAP_REDEEM   = 'gcrd_redeem';
	const CAP_RECHARGE = 'gcrd_recharge';
	const CAP_VOID     = 'gcrd_void';
	const CAP_REPORTS  = 'gcrd_reports';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gcrd_transactions';
	}

	public static function activate() {
		self::create_table();
		self::add_roles();
		update_option( 'gcrd_db_version', GCRD_DB_VERSION );
		update_option( 'gcrd_flush_rewrite', 1 );
		if ( false === get_option( GCRD_Settings::OPTION, false ) ) {
			add_option( GCRD_Settings::OPTION, GCRD_Settings::defaults() );
		}
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'gcrd_db_version' ) !== GCRD_DB_VERSION ) {
			self::activate();
		}
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coupon_id bigint(20) unsigned NOT NULL,
			code varchar(100) NOT NULL,
			type varchar(20) NOT NULL,
			amount decimal(19,4) NOT NULL DEFAULT 0,
			balance_before decimal(19,4) NOT NULL DEFAULT 0,
			balance_after decimal(19,4) NOT NULL DEFAULT 0,
			outlet varchar(100) NOT NULL DEFAULT '',
			reference varchar(191) NOT NULL DEFAULT '',
			note text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			related_id bigint(20) unsigned NULL,
			voided_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY coupon_id (coupon_id),
			KEY code (code),
			KEY created_at (created_at),
			KEY outlet (outlet)
		) {$charset};";
		dbDelta( $sql );
	}

	public static function add_roles() {
		add_role(
			'gcrd_staff',
			__( 'Gift Card Staff', 'gc-redeem-desk' ),
			array(
				'read'           => true,
				self::CAP_REDEEM => true,
			)
		);
		add_role(
			'gcrd_manager',
			__( 'Gift Card Manager', 'gc-redeem-desk' ),
			array(
				'read'             => true,
				self::CAP_REDEEM   => true,
				self::CAP_RECHARGE => true,
				self::CAP_VOID     => true,
				self::CAP_REPORTS  => true,
			)
		);
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( array( self::CAP_REDEEM, self::CAP_RECHARGE, self::CAP_VOID, self::CAP_REPORTS ) as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	}
}
