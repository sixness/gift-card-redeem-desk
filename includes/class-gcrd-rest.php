<?php
/**
 * REST API for the desk (namespace gcrd/v1). Cookie auth + wp_rest nonce.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_REST {

	const NS = 'gcrd/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/card',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'lookup' ),
				'permission_callback' => array( __CLASS__, 'can_redeem' ),
				'args'                => array( 'code' => array( 'required' => true ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/card/redeem',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'redeem' ),
				'permission_callback' => array( __CLASS__, 'can_redeem' ),
			)
		);
		register_rest_route(
			self::NS,
			'/card/recharge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'recharge' ),
				'permission_callback' => function () {
					return current_user_can( GCRD_Install::CAP_RECHARGE );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/transactions/(?P<id>\d+)/void',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'void' ),
				'permission_callback' => function () {
					return current_user_can( GCRD_Install::CAP_VOID );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/transactions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'transactions' ),
				'permission_callback' => array( __CLASS__, 'can_redeem' ),
			)
		);
	}

	public static function can_redeem() {
		return current_user_can( GCRD_Install::CAP_REDEEM );
	}

	/**
	 * Simple per-user throttle on lookups, to stop code guessing from a staff login.
	 *
	 * @return true|WP_Error
	 */
	private static function throttle() {
		$key   = 'gcrd_lk_' . get_current_user_id();
		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'gcrd_lookup_limit_per_10min', 150 );
		if ( $count >= $limit ) {
			return new WP_Error( 'gcrd_throttle', __( 'Too many lookups. Wait a few minutes and try again.', 'gc-redeem-desk' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function lookup( WP_REST_Request $req ) {
		$t = self::throttle();
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$coupon = GCRD_Cards::find( (string) $req->get_param( 'code' ) );
		if ( ! $coupon ) {
			return new WP_Error( 'gcrd_not_found', __( 'Gift card not found. Check the code and try again.', 'gc-redeem-desk' ), array( 'status' => 404 ) );
		}
		$hist = GCRD_Ledger::query(
			array(
				'coupon_id' => $coupon->get_id(),
				'per_page'  => 50,
			)
		);
		return rest_ensure_response(
			array(
				'card'    => GCRD_Cards::to_array( $coupon ),
				'history' => array_map( array( 'GCRD_Ledger', 'present' ), $hist['rows'] ),
			)
		);
	}

	private static function ctx( WP_REST_Request $req ) {
		return array(
			'outlet'    => (string) $req->get_param( 'outlet' ),
			'reference' => (string) $req->get_param( 'reference' ),
			'note'      => (string) $req->get_param( 'note' ),
		);
	}

	public static function redeem( WP_REST_Request $req ) {
		$res = GCRD_Ledger::redeem( (string) $req->get_param( 'code' ), $req->get_param( 'amount' ), self::ctx( $req ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function recharge( WP_REST_Request $req ) {
		$res = GCRD_Ledger::recharge( (string) $req->get_param( 'code' ), $req->get_param( 'amount' ), self::ctx( $req ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function void( WP_REST_Request $req ) {
		$res = GCRD_Ledger::void( (int) $req['id'], (string) $req->get_param( 'reason' ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/**
	 * Today's transactions for the desk sidebar (own, or all for managers).
	 */
	public static function transactions( WP_REST_Request $req ) {
		$args = array(
			'from'     => wp_date( 'Y-m-d' ),
			'to'       => wp_date( 'Y-m-d' ),
			'per_page' => 100,
		);
		if ( ! current_user_can( GCRD_Install::CAP_REPORTS ) || 'mine' === $req->get_param( 'scope' ) ) {
			$args['user_id'] = get_current_user_id();
		}
		if ( $req->get_param( 'outlet' ) ) {
			$args['outlet'] = sanitize_text_field( (string) $req->get_param( 'outlet' ) );
		}
		$res   = GCRD_Ledger::query( $args );
		$rows  = array_map( array( 'GCRD_Ledger', 'present' ), $res['rows'] );
		$total = 0.0;
		foreach ( $res['rows'] as $r ) {
			if ( 'redeem' === $r['type'] && empty( $r['voided_by'] ) ) {
				$total += (float) $r['amount'];
			}
		}
		return rest_ensure_response(
			array(
				'rows'               => $rows,
				'redeemed_total'     => $total,
				'redeemed_total_html'=> wp_strip_all_tags( wc_price( $total ) ),
			)
		);
	}
}
