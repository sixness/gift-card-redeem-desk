<?php
/**
 * Serves signed QR images: /?gcrd_qr=CODE&s=SIGNATURE
 *
 * The QR encodes the desk URL with the card code, so staff can scan it with any
 * phone camera (opens the desk, which asks staff to log in) or with the desk's
 * built-in scanner. The signature stops the endpoint being used as a free QR service.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_QR_Endpoint {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	/**
	 * Signature for a code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function sign( $code ) {
		return substr( hash_hmac( 'sha256', strtoupper( (string) $code ), wp_salt( 'auth' ) . 'gcrd_qr' ), 0, 20 );
	}

	/**
	 * Public image URL for a code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function image_url( $code ) {
		$code = strtoupper( (string) $code );
		return add_query_arg(
			array(
				'gcrd_qr' => rawurlencode( $code ),
				's'       => self::sign( $code ),
			),
			home_url( '/' )
		);
	}

	/**
	 * HTML <img> for emails.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function img_html( $code ) {
		$size  = (int) GCRD_Settings::get( 'qr_size' );
		$label = (string) GCRD_Settings::get( 'qr_label' );
		$html  = '<img src="' . esc_url( self::image_url( $code ) ) . '" width="' . $size . '" height="' . $size . '" alt="' . esc_attr( 'QR ' . $code ) . '" style="display:block;width:' . $size . 'px;height:' . $size . 'px;border:0;margin:0 auto;background:#ffffff;border-radius:6px;">';
		if ( '' !== $label ) {
			$html .= '<p style="margin:6px 0 0 0;font-size:10px;line-height:1.3;text-align:center;color:inherit;">' . esc_html( $label ) . '</p>';
		}
		return $html;
	}

	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['gcrd_qr'] ) ) {
			return;
		}
		$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['gcrd_qr'] ) ) );
		$sig  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		if ( ! hash_equals( self::sign( $code ), $sig ) ) {
			status_header( 403 );
			exit;
		}
		$payload = gcrd_desk_url_for_code( $code );
		$dark    = (string) GCRD_Settings::get( 'qr_dark' );
		$light   = (string) GCRD_Settings::get( 'qr_light' );
		$size    = (int) GCRD_Settings::get( 'qr_size' );
		$modules = count( GCRD_QR::matrix( $payload ) ) + 8;
		$scale   = max( 2, (int) ceil( ( $size * 2 ) / $modules ) ); // 2x for retina screens.

		header( 'Cache-Control: public, max-age=604800' );
		header( 'X-Robots-Tag: noindex' );
		$png = GCRD_QR::png( $payload, $scale, 4, $dark, $light );
		if ( null !== $png ) {
			header( 'Content-Type: image/png' );
			echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			header( 'Content-Type: image/svg+xml' );
			echo GCRD_QR::svg( $payload, $size, 4, $dark, $light ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		exit;
	}
}
