<?php
/**
 * Integration with WP Swings "Ultimate Gift Cards for WooCommerce" (formerly MakeWebBetter).
 *
 * Adds the [REDEEM_QR] placeholder to their gift card email templates.
 * Put [REDEEM_QR] anywhere in a template (Gift Cards > Templates) to show the card's QR code.
 *
 * Other gift card plugins can print the QR with: echo GCRD_QR_Endpoint::img_html( $code );
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_WPSwings {

	const PLACEHOLDER = '[REDEEM_QR]';

	public static function init() {
		add_filter( 'wps_wgm_email_template_html', array( __CLASS__, 'replace_placeholder' ), 20, 2 );
		add_filter( 'mwb_wgm_email_template_html', array( __CLASS__, 'replace_placeholder' ), 20, 2 );
	}

	/**
	 * Replace [REDEEM_QR] with the QR image.
	 *
	 * @param string $html Template HTML.
	 * @param array  $args Template args from the gift card plugin (has 'coupon').
	 * @return string
	 */
	public static function replace_placeholder( $html, $args ) {
		if ( false === strpos( (string) $html, self::PLACEHOLDER ) ) {
			return $html;
		}
		$code = is_array( $args ) && ! empty( $args['coupon'] ) ? wp_strip_all_tags( (string) $args['coupon'] ) : '';
		$code = trim( $code );
		$qr   = ( GCRD_Settings::on( 'qr_enabled' ) && '' !== $code ) ? GCRD_QR_Endpoint::img_html( $code ) : '';
		return str_replace( self::PLACEHOLDER, $qr, $html );
	}
}
