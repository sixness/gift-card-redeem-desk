<?php
/**
 * The staff desk page: a standalone, full-screen web app at /{desk_slug}/.
 * It does not load the theme, so it looks the same on every project and
 * takes its look only from the plugin's branding settings.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_Desk {

	const QV = 'gcrd_desk';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 0 );
	}

	public static function rewrite() {
		$slug = GCRD_Settings::get( 'desk_slug' );
		add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?' . self::QV . '=1', 'top' );
		if ( get_option( 'gcrd_flush_rewrite' ) ) {
			delete_option( 'gcrd_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/**
	 * Desk URL (works with or without pretty permalinks).
	 *
	 * @return string
	 */
	public static function url() {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . GCRD_Settings::get( 'desk_slug' ) . '/' );
		}
		return add_query_arg( self::QV, '1', home_url( '/' ) );
	}

	public static function render() {
		if ( ! get_query_var( self::QV ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );

		if ( ! is_user_logged_in() ) {
			$current = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
			wp_safe_redirect( wp_login_url( $current ) );
			exit;
		}

		$s       = GCRD_Settings::all();
		$user    = wp_get_current_user();
		$allowed = current_user_can( GCRD_Install::CAP_REDEEM );

		$config = array(
			'rest'        => esc_url_raw( rest_url( GCRD_REST::NS ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'outlets'     => GCRD_Settings::outlets(),
			'requireRef'  => GCRD_Settings::on( 'require_reference' ),
			'refLabel'    => $s['reference_label'],
			'canRecharge' => current_user_can( GCRD_Install::CAP_RECHARGE ),
			'canVoid'     => current_user_can( GCRD_Install::CAP_VOID ),
			'canReports'  => current_user_can( GCRD_Install::CAP_REPORTS ),
			'currency'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals'    => wc_get_price_decimals(),
			'brand'       => $s['brand_name'],
			'user'        => $user->display_name,
			'i18n'        => self::strings(),
		);

		include GCRD_DIR . 'templates/desk.php';
		exit;
	}

	/**
	 * UI strings (translatable).
	 *
	 * @return array
	 */
	public static function strings() {
		return array(
			'lookup'          => __( 'Look up', 'gc-redeem-desk' ),
			'scan'            => __( 'Scan QR', 'gc-redeem-desk' ),
			'scanHint'        => __( 'Point the camera at the QR code on the gift card.', 'gc-redeem-desk' ),
			'scanUnsupported' => __( 'This browser cannot scan in the page. Open your phone camera and scan the QR code instead, it will open this desk with the card filled in.', 'gc-redeem-desk' ),
			'cameraDenied'    => __( 'Camera access was blocked. Allow the camera for this site, or type the code.', 'gc-redeem-desk' ),
			'close'           => __( 'Close', 'gc-redeem-desk' ),
			'codePlaceholder' => __( 'Enter gift card code', 'gc-redeem-desk' ),
			'balance'         => __( 'Balance', 'gc-redeem-desk' ),
			'originalValue'   => __( 'Original value', 'gc-redeem-desk' ),
			'expires'         => __( 'Expires', 'gc-redeem-desk' ),
			'issued'          => __( 'Issued', 'gc-redeem-desk' ),
			'recipient'       => __( 'Recipient', 'gc-redeem-desk' ),
			'from'            => __( 'From', 'gc-redeem-desk' ),
			'buyer'           => __( 'Buyer', 'gc-redeem-desk' ),
			'message'         => __( 'Message', 'gc-redeem-desk' ),
			'order'           => __( 'Order', 'gc-redeem-desk' ),
			'redeem'          => __( 'Redeem', 'gc-redeem-desk' ),
			'recharge'        => __( 'Recharge', 'gc-redeem-desk' ),
			'amount'          => __( 'Amount', 'gc-redeem-desk' ),
			'fullBalance'     => __( 'Full balance', 'gc-redeem-desk' ),
			'outlet'          => __( 'Outlet', 'gc-redeem-desk' ),
			'chooseOutlet'    => __( 'Choose outlet', 'gc-redeem-desk' ),
			'note'            => __( 'Note (optional)', 'gc-redeem-desk' ),
			'confirmRedeem'   => __( 'Confirm redeem', 'gc-redeem-desk' ),
			'confirmRecharge' => __( 'Confirm recharge', 'gc-redeem-desk' ),
			'cancel'          => __( 'Cancel', 'gc-redeem-desk' ),
			'newBalance'      => __( 'New balance', 'gc-redeem-desk' ),
			'done'            => __( 'Done', 'gc-redeem-desk' ),
			'print'           => __( 'Print slip', 'gc-redeem-desk' ),
			'history'         => __( 'Card history', 'gc-redeem-desk' ),
			'noHistory'       => __( 'No desk transactions for this card yet.', 'gc-redeem-desk' ),
			'today'           => __( 'Today', 'gc-redeem-desk' ),
			'todayTotal'      => __( 'Redeemed today', 'gc-redeem-desk' ),
			'nothingToday'    => __( 'No transactions today.', 'gc-redeem-desk' ),
			'void'            => __( 'Void', 'gc-redeem-desk' ),
			'voided'          => __( 'Voided', 'gc-redeem-desk' ),
			'voidReason'      => __( 'Reason for void', 'gc-redeem-desk' ),
			'confirmVoid'     => __( 'Confirm void', 'gc-redeem-desk' ),
			'status_active'   => __( 'Active', 'gc-redeem-desk' ),
			'status_expired'  => __( 'Expired', 'gc-redeem-desk' ),
			'status_empty'    => __( 'Used up', 'gc-redeem-desk' ),
			'status_disabled' => __( 'Disabled', 'gc-redeem-desk' ),
			'type_redeem'     => __( 'Redeem', 'gc-redeem-desk' ),
			'type_recharge'   => __( 'Recharge', 'gc-redeem-desk' ),
			'type_void'       => __( 'Void', 'gc-redeem-desk' ),
			'error'           => __( 'Something went wrong. Please try again.', 'gc-redeem-desk' ),
			'sessionExpired'  => __( 'Your session expired. The page will reload.', 'gc-redeem-desk' ),
			'enterAmount'     => __( 'Enter an amount.', 'gc-redeem-desk' ),
			'overBalance'     => __( 'Amount is more than the balance.', 'gc-redeem-desk' ),
			'needOutlet'      => __( 'Choose the outlet.', 'gc-redeem-desk' ),
			'needRef'         => __( 'Enter a reference.', 'gc-redeem-desk' ),
			'success'         => __( 'Done', 'gc-redeem-desk' ),
			'logout'          => __( 'Log out', 'gc-redeem-desk' ),
			'reports'         => __( 'Reports', 'gc-redeem-desk' ),
			'staff'           => __( 'Staff', 'gc-redeem-desk' ),
			'ref'             => __( 'Ref', 'gc-redeem-desk' ),
			'slipTitle'       => __( 'Gift card transaction', 'gc-redeem-desk' ),
			'cardCode'        => __( 'Card', 'gc-redeem-desk' ),
			'dateTime'        => __( 'Date', 'gc-redeem-desk' ),
			'newCard'         => __( 'Next card', 'gc-redeem-desk' ),
		);
	}
}
