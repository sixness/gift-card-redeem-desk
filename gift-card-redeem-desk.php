<?php
/**
 * Plugin Name:       Gift Card Redeem Desk
 * Description:       In-store redemption desk for WooCommerce gift cards. Staff look up a card by code or QR, redeem part or all of the balance, recharge, and void, with a full audit log. Brandable per project (logo, colours, fonts, outlets).
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            WP Pro Design
 * Text Domain:       gc-redeem-desk
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GCRD_VERSION', '1.0.0' );
define( 'GCRD_DB_VERSION', '1' );
define( 'GCRD_FILE', __FILE__ );
define( 'GCRD_DIR', plugin_dir_path( __FILE__ ) );
define( 'GCRD_URL', plugin_dir_url( __FILE__ ) );

require_once GCRD_DIR . 'includes/class-gcrd-qr.php';
require_once GCRD_DIR . 'includes/class-gcrd-settings.php';
require_once GCRD_DIR . 'includes/class-gcrd-install.php';
require_once GCRD_DIR . 'includes/class-gcrd-cards.php';
require_once GCRD_DIR . 'includes/class-gcrd-ledger.php';
require_once GCRD_DIR . 'includes/class-gcrd-rest.php';
require_once GCRD_DIR . 'includes/class-gcrd-desk.php';
require_once GCRD_DIR . 'includes/class-gcrd-qr-endpoint.php';
require_once GCRD_DIR . 'includes/class-gcrd-admin.php';
require_once GCRD_DIR . 'includes/integrations/class-gcrd-wpswings.php';

register_activation_hook( __FILE__, array( 'GCRD_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GCRD_Install', 'deactivate' ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'gc-redeem-desk', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Gift Card Redeem Desk needs WooCommerce to be active.', 'gc-redeem-desk' ) . '</p></div>';
		} );
		return;
	}

	GCRD_Install::maybe_upgrade();
	GCRD_REST::init();
	GCRD_Desk::init();
	GCRD_QR_Endpoint::init();
	GCRD_Admin::init();
	GCRD_WPSwings::init();
} );

/**
 * Public helper: URL that a QR code for this card should point to.
 *
 * @param string $code Gift card code.
 * @return string
 */
function gcrd_desk_url_for_code( $code ) {
	return add_query_arg( 'code', rawurlencode( (string) $code ), GCRD_Desk::url() );
}

/**
 * Public helper: image URL of the QR code for a gift card (signed).
 *
 * @param string $code Gift card code.
 * @return string
 */
function gcrd_qr_image_url( $code ) {
	return GCRD_QR_Endpoint::image_url( $code );
}
