<?php
/**
 * Gift card lookup. A "card" is a WooCommerce coupon; its balance is the coupon amount.
 *
 * Works with any gift card plugin that issues WooCommerce coupons. Known plugins
 * (WP Swings / MakeWebBetter Ultimate Gift Cards) are detected by their meta keys;
 * other plugins can mark coupons with the `gcrd_is_gift_card` filter.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_Cards {

	/** Meta keys that mark a coupon as a gift card, by known plugin. */
	const MARKER_KEYS = array(
		'wps_wgm_giftcard_coupon',  // WP Swings Ultimate Gift Cards 3.x.
		'mwb_wgm_giftcard_coupon',  // MakeWebBetter (older versions).
		'_gcrd_gift_card',          // Cards flagged by this plugin.
	);

	/**
	 * Normalise a code typed or scanned by staff. Accepts a desk URL with ?code=.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function normalise_code( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '#^https?://#i', $raw ) ) {
			$q = wp_parse_url( $raw, PHP_URL_QUERY );
			if ( $q ) {
				parse_str( $q, $args );
				if ( ! empty( $args['code'] ) ) {
					$raw = (string) $args['code'];
				}
			}
		}
		$raw = wc_format_coupon_code( $raw );
		return substr( $raw, 0, 100 );
	}

	/**
	 * Find a gift card coupon by code.
	 *
	 * @param string $code Code.
	 * @return WC_Coupon|null
	 */
	public static function find( $code ) {
		$code = self::normalise_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return null;
		}
		$coupon = new WC_Coupon( $id );
		if ( ! $coupon->get_id() || ! self::is_gift_card( $coupon ) ) {
			return null;
		}
		return $coupon;
	}

	/**
	 * Is this coupon a gift card the desk may operate on?
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 */
	public static function is_gift_card( WC_Coupon $coupon ) {
		$is = false;
		if ( 'any' === GCRD_Settings::get( 'card_source' ) ) {
			$is = 'fixed_cart' === $coupon->get_discount_type();
		} else {
			foreach ( self::MARKER_KEYS as $key ) {
				if ( '' !== (string) get_post_meta( $coupon->get_id(), $key, true ) ) {
					$is = true;
					break;
				}
			}
		}
		return (bool) apply_filters( 'gcrd_is_gift_card', $is, $coupon );
	}

	/**
	 * Read a meta value from the first key that has one (handles plugin renames).
	 *
	 * @param int      $id   Post ID.
	 * @param string[] $keys Keys.
	 * @return string
	 */
	private static function meta( $id, array $keys ) {
		foreach ( $keys as $k ) {
			$v = get_post_meta( $id, $k, true );
			if ( '' !== (string) $v && ! is_array( $v ) ) {
				return (string) $v;
			}
		}
		return '';
	}

	/**
	 * Current balance.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return float
	 */
	public static function balance( WC_Coupon $coupon ) {
		return (float) wc_format_decimal( $coupon->get_amount( 'edit' ), wc_get_price_decimals() );
	}

	/**
	 * Status: active | expired | empty | disabled.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public static function status( WC_Coupon $coupon ) {
		if ( 'publish' !== get_post_status( $coupon->get_id() ) ) {
			return 'disabled';
		}
		$exp = $coupon->get_date_expires();
		if ( $exp && $exp->getTimestamp() < time() ) {
			return 'expired';
		}
		if ( self::balance( $coupon ) <= 0 ) {
			return 'empty';
		}
		return 'active';
	}

	/**
	 * Data for the desk UI.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array
	 */
	public static function to_array( WC_Coupon $coupon ) {
		$id       = $coupon->get_id();
		$order_id = (int) self::meta( $id, array( 'wps_wgm_giftcard_coupon', 'mwb_wgm_giftcard_coupon' ) );
		$original = self::meta( $id, array( 'wps_wgm_coupon_amount', 'mwb_wgm_coupon_amount', '_gcrd_original_amount' ) );
		$exp      = $coupon->get_date_expires();
		$to_name  = '';
		$from     = '';
		$message  = '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( $order ) {
			$product_id = (int) self::meta( $id, array( 'wps_wgm_giftcard_coupon_product_id', 'mwb_wgm_giftcard_coupon_product_id' ) );
			foreach ( $order->get_items() as $item ) {
				if ( $product_id && (int) $item->get_product_id() !== $product_id ) {
					continue;
				}
				$to_name = (string) $item->get_meta( 'To' );
				$from    = (string) $item->get_meta( 'From' );
				$message = (string) $item->get_meta( 'Message' );
				break;
			}
		}

		$show_recipient = GCRD_Settings::on( 'show_recipient' );
		$data           = array(
			'id'              => $id,
			'code'            => strtoupper( $coupon->get_code() ),
			'status'          => self::status( $coupon ),
			'balance'         => self::balance( $coupon ),
			'balance_html'    => wp_strip_all_tags( wc_price( self::balance( $coupon ) ) ),
			'original'        => '' !== $original ? (float) $original : null,
			'original_html'   => '' !== $original ? wp_strip_all_tags( wc_price( (float) $original ) ) : '',
			'expires'         => $exp ? $exp->date( 'c' ) : null,
			'expires_html'    => $exp ? $exp->date_i18n( get_option( 'date_format' ) ) : __( 'No expiry', 'gc-redeem-desk' ),
			'recipient_email' => $show_recipient ? self::meta( $id, array( 'wps_wgm_giftcard_coupon_mail_to', 'mwb_wgm_giftcard_coupon_mail_to' ) ) : '',
			'to'              => $show_recipient ? $to_name : '',
			'from'            => $show_recipient ? $from : '',
			'message'         => $show_recipient ? $message : '',
			'buyer'           => ( $show_recipient && $order ) ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '',
			'order_id'        => $order_id ? $order_id : null,
			'order_url'       => ( $order && current_user_can( 'edit_shop_orders' ) ) ? $order->get_edit_order_url() : '',
			'issued_html'     => $coupon->get_date_created() ? $coupon->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
		);
		return apply_filters( 'gcrd_card_data', $data, $coupon );
	}
}
