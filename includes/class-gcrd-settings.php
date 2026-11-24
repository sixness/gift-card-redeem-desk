<?php
/**
 * Settings storage and defaults (one option: gcrd_settings).
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_Settings {

	const OPTION = 'gcrd_settings';

	/** @var array|null */
	private static $cache = null;

	/**
	 * Default values. Every key here is a setting; anything else is ignored.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Desk.
			'desk_slug'          => 'redeem-desk',
			'desk_title'         => __( 'Gift Card Redeem Desk', 'gc-redeem-desk' ),
			'outlets'            => "Front Desk\nRestaurant\nSpa",
			'require_reference'  => 'yes',
			'reference_label'    => __( 'Reference (folio / bill / room no.)', 'gc-redeem-desk' ),
			'card_source'        => 'giftcards', // giftcards = only coupons created by a gift card plugin; any = any fixed-cart coupon.
			'allow_expired'      => 'no',
			'order_notes'        => 'yes',
			'show_recipient'     => 'yes',
			// Branding / CI.
			'brand_name'         => get_bloginfo( 'name' ),
			'logo_url'           => '',
			'logo_height'        => 40,
			'color_primary'      => '#a1967e',
			'color_on_primary'   => '#ffffff',
			'color_bg'           => '#f4f2f0',
			'color_surface'      => '#ffffff',
			'color_text'         => '#2b2b2b',
			'color_muted'        => '#6f6a60',
			'color_header'       => '#ffffff',
			'font_body'          => '-apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif',
			'font_heading'       => 'Optima, Candara, "Segoe UI", "Helvetica Neue", Arial, sans-serif',
			'font_css_url'       => '',
			'radius'             => 10,
			'custom_css'         => '',
			// QR.
			'qr_enabled'         => 'yes',
			'qr_size'            => 160,
			'qr_dark'            => '#000000',
			'qr_light'           => '#ffffff',
			'qr_label'           => __( 'Scan at redemption counter', 'gc-redeem-desk' ),
			// Data.
			'delete_on_uninstall' => 'no',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Is a yes/no setting on?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Outlets as array.
	 *
	 * @return string[]
	 */
	public static function outlets() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'outlets' ) );
		$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
		return apply_filters( 'gcrd_outlets', $lines );
	}

	/**
	 * Sanitize a full settings array (from the form or an import).
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$def = self::defaults();
		$out = array();
		$in  = is_array( $in ) ? $in : array();
		foreach ( $def as $key => $default ) {
			$val = isset( $in[ $key ] ) ? $in[ $key ] : null;
			if ( 0 === strpos( $key, 'color_' ) || in_array( $key, array( 'qr_dark', 'qr_light' ), true ) ) {
				$c           = sanitize_hex_color( (string) $val );
				$out[ $key ] = $c ? $c : $default;
			} elseif ( in_array( $key, array( 'logo_height', 'radius', 'qr_size' ), true ) ) {
				$n           = is_numeric( $val ) ? (int) $val : (int) $default;
				$limits      = array(
					'logo_height' => array( 16, 160 ),
					'radius'      => array( 0, 40 ),
					'qr_size'     => array( 80, 400 ),
				);
				$out[ $key ] = max( $limits[ $key ][0], min( $limits[ $key ][1], $n ) );
			} elseif ( in_array( $key, array( 'require_reference', 'allow_expired', 'order_notes', 'show_recipient', 'qr_enabled', 'delete_on_uninstall' ), true ) ) {
				$out[ $key ] = ( 'yes' === $val || '1' === $val || true === $val ) ? 'yes' : 'no';
			} elseif ( 'card_source' === $key ) {
				$out[ $key ] = in_array( $val, array( 'giftcards', 'any' ), true ) ? $val : $default;
			} elseif ( 'desk_slug' === $key ) {
				$slug        = sanitize_title( (string) $val );
				$out[ $key ] = $slug ? $slug : $default;
			} elseif ( in_array( $key, array( 'logo_url', 'font_css_url' ), true ) ) {
				$out[ $key ] = esc_url_raw( (string) $val );
			} elseif ( 'outlets' === $key ) {
				$lines       = preg_split( '/\r\n|\r|\n/', (string) $val );
				$lines       = array_filter( array_map( 'sanitize_text_field', $lines ), 'strlen' );
				$out[ $key ] = implode( "\n", $lines );
			} elseif ( in_array( $key, array( 'font_body', 'font_heading' ), true ) ) {
				// Font stacks: letters, digits, spaces, quotes, commas, hyphens only.
				$v           = preg_replace( '/[^A-Za-z0-9 ,\'"\-_]/', '', (string) $val );
				$out[ $key ] = '' !== trim( $v ) ? $v : $default;
			} elseif ( 'custom_css' === $key ) {
				$out[ $key ] = wp_strip_all_tags( (string) $val );
			} else {
				$out[ $key ] = null === $val ? $default : sanitize_text_field( (string) $val );
			}
		}
		return $out;
	}

	/**
	 * Save settings.
	 *
	 * @param array $values Values (will be sanitized).
	 */
	public static function save( $values ) {
		$old_slug = self::get( 'desk_slug' );
		$clean    = self::sanitize( $values );
		update_option( self::OPTION, $clean );
		self::$cache = null;
		if ( $old_slug !== $clean['desk_slug'] ) {
			update_option( 'gcrd_flush_rewrite', 1 );
		}
	}

	/**
	 * CSS custom properties for the desk.
	 *
	 * @return string
	 */
	public static function css_vars() {
		$s    = self::all();
		$vars = array(
			'--gcrd-primary'    => $s['color_primary'],
			'--gcrd-on-primary' => $s['color_on_primary'],
			'--gcrd-bg'         => $s['color_bg'],
			'--gcrd-surface'    => $s['color_surface'],
			'--gcrd-text'       => $s['color_text'],
			'--gcrd-muted'      => $s['color_muted'],
			'--gcrd-header'     => $s['color_header'],
			'--gcrd-radius'     => (int) $s['radius'] . 'px',
			'--gcrd-font'       => $s['font_body'],
			'--gcrd-font-head'  => $s['font_heading'],
			'--gcrd-logo-h'     => (int) $s['logo_height'] . 'px',
		);
		$css = ':root{';
		foreach ( $vars as $k => $v ) {
			$css .= $k . ':' . $v . ';';
		}
		return $css . '}';
	}
}
