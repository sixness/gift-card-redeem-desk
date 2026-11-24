<?php
/**
 * Balance changes and the transaction log. Every change takes a per-card lock so
 * two counters cannot spend the same balance at once.
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_Ledger {

	/**
	 * Redeem (deduct) an amount.
	 *
	 * @param string $code  Card code.
	 * @param float  $amount Amount.
	 * @param array  $ctx   outlet, reference, note.
	 * @return array|WP_Error Transaction row + card.
	 */
	public static function redeem( $code, $amount, array $ctx ) {
		return self::change( 'redeem', $code, $amount, $ctx );
	}

	/**
	 * Recharge (add) an amount.
	 *
	 * @param string $code   Card code.
	 * @param float  $amount Amount.
	 * @param array  $ctx    outlet, reference, note.
	 * @return array|WP_Error
	 */
	public static function recharge( $code, $amount, array $ctx ) {
		return self::change( 'recharge', $code, $amount, $ctx );
	}

	/**
	 * Core balance change.
	 *
	 * @param string $type   redeem|recharge.
	 * @param string $code   Code.
	 * @param mixed  $amount Amount.
	 * @param array  $ctx    Context.
	 * @return array|WP_Error
	 */
	private static function change( $type, $code, $amount, array $ctx ) {
		$amount = self::clean_amount( $amount );
		if ( $amount <= 0 ) {
			return new WP_Error( 'gcrd_amount', __( 'Enter an amount greater than zero.', 'gc-redeem-desk' ), array( 'status' => 400 ) );
		}
		$ctx = self::clean_ctx( $ctx );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$coupon = GCRD_Cards::find( $code );
		if ( ! $coupon ) {
			return new WP_Error( 'gcrd_not_found', __( 'Gift card not found.', 'gc-redeem-desk' ), array( 'status' => 404 ) );
		}

		$lock = self::lock( $coupon->get_id() );
		if ( ! $lock ) {
			return new WP_Error( 'gcrd_busy', __( 'This card is being updated at another counter. Try again in a moment.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
		}

		try {
			// Re-read inside the lock.
			$coupon = new WC_Coupon( $coupon->get_id() );
			$status = GCRD_Cards::status( $coupon );
			$before = GCRD_Cards::balance( $coupon );

			if ( 'disabled' === $status ) {
				return new WP_Error( 'gcrd_disabled', __( 'This gift card is disabled.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
			}
			if ( 'expired' === $status && ! GCRD_Settings::on( 'allow_expired' ) ) {
				return new WP_Error( 'gcrd_expired', __( 'This gift card has expired.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
			}
			if ( 'redeem' === $type && $amount > $before + 0.00001 ) {
				return new WP_Error(
					'gcrd_insufficient',
					/* translators: %s: balance */
					sprintf( __( 'Amount is more than the balance (%s).', 'gc-redeem-desk' ), wp_strip_all_tags( wc_price( $before ) ) ),
					array( 'status' => 409 )
				);
			}

			$after = 'redeem' === $type ? $before - $amount : $before + $amount;
			$after = (float) wc_format_decimal( $after, wc_get_price_decimals() );

			$coupon->set_amount( $after );
			$coupon->save();

			$tx = self::insert(
				array(
					'coupon_id'      => $coupon->get_id(),
					'code'           => strtoupper( $coupon->get_code() ),
					'type'           => $type,
					'amount'         => $amount,
					'balance_before' => $before,
					'balance_after'  => $after,
					'outlet'         => $ctx['outlet'],
					'reference'      => $ctx['reference'],
					'note'           => $ctx['note'],
				)
			);
			self::order_note( $coupon, $tx );
			do_action( 'gcrd_transaction_recorded', $tx, $coupon );

			return array(
				'transaction' => self::present( $tx ),
				'card'        => GCRD_Cards::to_array( new WC_Coupon( $coupon->get_id() ) ),
			);
		} finally {
			self::unlock( $coupon->get_id() );
		}
	}

	/**
	 * Void (reverse) a redeem or recharge.
	 *
	 * @param int    $tx_id  Transaction ID.
	 * @param string $reason Reason.
	 * @return array|WP_Error
	 */
	public static function void( $tx_id, $reason ) {
		$orig = self::get( $tx_id );
		if ( ! $orig ) {
			return new WP_Error( 'gcrd_tx_not_found', __( 'Transaction not found.', 'gc-redeem-desk' ), array( 'status' => 404 ) );
		}
		if ( 'void' === $orig['type'] ) {
			return new WP_Error( 'gcrd_tx_void', __( 'A void cannot be voided.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $orig['voided_by'] ) ) {
			return new WP_Error( 'gcrd_tx_voided', __( 'This transaction was already voided.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
		}
		$reason = sanitize_text_field( (string) $reason );
		if ( '' === $reason ) {
			return new WP_Error( 'gcrd_reason', __( 'Enter a reason for the void.', 'gc-redeem-desk' ), array( 'status' => 400 ) );
		}

		$lock = self::lock( (int) $orig['coupon_id'] );
		if ( ! $lock ) {
			return new WP_Error( 'gcrd_busy', __( 'This card is being updated at another counter. Try again in a moment.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
		}
		try {
			$orig = self::get( $tx_id ); // Re-check inside lock.
			if ( ! empty( $orig['voided_by'] ) ) {
				return new WP_Error( 'gcrd_tx_voided', __( 'This transaction was already voided.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
			}
			$coupon = new WC_Coupon( (int) $orig['coupon_id'] );
			if ( ! $coupon->get_id() ) {
				return new WP_Error( 'gcrd_not_found', __( 'Gift card not found.', 'gc-redeem-desk' ), array( 'status' => 404 ) );
			}
			$before = GCRD_Cards::balance( $coupon );
			$amount = (float) $orig['amount'];
			$after  = 'redeem' === $orig['type'] ? $before + $amount : $before - $amount;
			if ( $after < -0.00001 ) {
				return new WP_Error( 'gcrd_void_balance', __( 'Cannot void this recharge: part of it has already been spent.', 'gc-redeem-desk' ), array( 'status' => 409 ) );
			}
			$after = (float) wc_format_decimal( max( 0, $after ), wc_get_price_decimals() );

			$coupon->set_amount( $after );
			$coupon->save();

			$tx = self::insert(
				array(
					'coupon_id'      => $coupon->get_id(),
					'code'           => strtoupper( $coupon->get_code() ),
					'type'           => 'void',
					'amount'         => $amount,
					'balance_before' => $before,
					'balance_after'  => $after,
					'outlet'         => $orig['outlet'],
					'reference'      => $orig['reference'],
					'note'           => $reason,
					'related_id'     => (int) $orig['id'],
				)
			);
			global $wpdb;
			$wpdb->update( GCRD_Install::table(), array( 'voided_by' => (int) $tx['id'] ), array( 'id' => (int) $orig['id'] ), array( '%d' ), array( '%d' ) );

			self::order_note( $coupon, $tx );
			do_action( 'gcrd_transaction_recorded', $tx, $coupon );

			return array(
				'transaction' => self::present( $tx ),
				'card'        => GCRD_Cards::to_array( new WC_Coupon( $coupon->get_id() ) ),
			);
		} finally {
			self::unlock( (int) $orig['coupon_id'] );
		}
	}

	/* ---------------- queries ---------------- */

	/**
	 * Get one transaction.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = GCRD_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Query transactions.
	 *
	 * @param array $args coupon_id, code, outlet, type, user_id, from (Y-m-d local), to, per_page, page.
	 * @return array{rows: array, total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$t      = GCRD_Install::table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['coupon_id'] ) ) {
			$where[]  = 'coupon_id = %d';
			$params[] = (int) $args['coupon_id'];
		}
		if ( ! empty( $args['code'] ) ) {
			$where[]  = 'code = %s';
			$params[] = strtoupper( GCRD_Cards::normalise_code( $args['code'] ) );
		}
		if ( ! empty( $args['outlet'] ) ) {
			$where[]  = 'outlet = %s';
			$params[] = (string) $args['outlet'];
		}
		if ( ! empty( $args['type'] ) && in_array( $args['type'], array( 'redeem', 'recharge', 'void' ), true ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = get_gmt_from_date( $args['from'] . ' 00:00:00' );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = get_gmt_from_date( $args['to'] . ' 23:59:59' );
		}
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 5000, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;
		$w        = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$sql       = "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Shape a row for output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function present( array $row ) {
		$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
		$ts   = strtotime( $row['created_at'] . ' UTC' );
		return array(
			'id'                  => (int) $row['id'],
			'code'                => $row['code'],
			'type'                => $row['type'],
			'amount'              => (float) $row['amount'],
			'amount_html'         => wp_strip_all_tags( wc_price( (float) $row['amount'] ) ),
			'balance_before_html' => wp_strip_all_tags( wc_price( (float) $row['balance_before'] ) ),
			'balance_after'       => (float) $row['balance_after'],
			'balance_after_html'  => wp_strip_all_tags( wc_price( (float) $row['balance_after'] ) ),
			'outlet'              => $row['outlet'],
			'reference'           => $row['reference'],
			'note'                => (string) $row['note'],
			'user'                => $user ? $user->display_name : '',
			'related_id'          => $row['related_id'] ? (int) $row['related_id'] : null,
			'voided_by'           => ! empty( $row['voided_by'] ) ? (int) $row['voided_by'] : null,
			'created'             => gmdate( 'c', $ts ),
			'created_html'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ),
		);
	}

	/* ---------------- internals ---------------- */

	private static function insert( array $data ) {
		global $wpdb;
		$data = wp_parse_args(
			$data,
			array(
				'note'       => '',
				'related_id' => null,
			)
		);
		$row  = array(
			'coupon_id'      => (int) $data['coupon_id'],
			'code'           => (string) $data['code'],
			'type'           => (string) $data['type'],
			'amount'         => (float) $data['amount'],
			'balance_before' => (float) $data['balance_before'],
			'balance_after'  => (float) $data['balance_after'],
			'outlet'         => (string) $data['outlet'],
			'reference'      => (string) $data['reference'],
			'note'           => (string) $data['note'],
			'user_id'        => get_current_user_id(),
			'ip'             => self::ip(),
			'related_id'     => $data['related_id'],
			'created_at'     => current_time( 'mysql', true ),
		);
		$wpdb->insert( GCRD_Install::table(), $row, array( '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ) );
		$row['id']        = (int) $wpdb->insert_id;
		$row['voided_by'] = null;
		return $row;
	}

	private static function clean_amount( $amount ) {
		$amount = is_string( $amount ) ? str_replace( array( ',', ' ' ), '', $amount ) : $amount;
		if ( ! is_numeric( $amount ) ) {
			return 0.0;
		}
		return (float) wc_format_decimal( $amount, wc_get_price_decimals() );
	}

	private static function clean_ctx( array $ctx ) {
		$outlet    = sanitize_text_field( isset( $ctx['outlet'] ) ? (string) $ctx['outlet'] : '' );
		$reference = sanitize_text_field( isset( $ctx['reference'] ) ? (string) $ctx['reference'] : '' );
		$note      = sanitize_textarea_field( isset( $ctx['note'] ) ? (string) $ctx['note'] : '' );
		$outlets   = GCRD_Settings::outlets();
		if ( $outlets && ! in_array( $outlet, $outlets, true ) ) {
			return new WP_Error( 'gcrd_outlet', __( 'Choose the outlet where the card is used.', 'gc-redeem-desk' ), array( 'status' => 400 ) );
		}
		if ( GCRD_Settings::on( 'require_reference' ) && '' === $reference ) {
			return new WP_Error( 'gcrd_reference', __( 'Enter a reference (folio, bill or room number).', 'gc-redeem-desk' ), array( 'status' => 400 ) );
		}
		return array(
			'outlet'    => substr( $outlet, 0, 100 ),
			'reference' => substr( $reference, 0, 191 ),
			'note'      => substr( $note, 0, 1000 ),
		);
	}

	private static function lock( $coupon_id ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'gcrd_card_' . (int) $coupon_id ) );
	}

	private static function unlock( $coupon_id ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'gcrd_card_' . (int) $coupon_id ) );
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 45 );
	}

	private static function order_note( WC_Coupon $coupon, array $tx ) {
		if ( ! GCRD_Settings::on( 'order_notes' ) ) {
			return;
		}
		$order_id = (int) get_post_meta( $coupon->get_id(), 'wps_wgm_giftcard_coupon', true );
		if ( ! $order_id ) {
			$order_id = (int) get_post_meta( $coupon->get_id(), 'mwb_wgm_giftcard_coupon', true );
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return;
		}
		$labels = array(
			'redeem'   => __( 'redeemed', 'gc-redeem-desk' ),
			'recharge' => __( 'recharged', 'gc-redeem-desk' ),
			'void'     => __( 'void (reversed)', 'gc-redeem-desk' ),
		);
		$user   = wp_get_current_user();
		$order->add_order_note(
			sprintf(
				/* translators: 1: code 2: action 3: amount 4: outlet 5: reference 6: new balance 7: staff */
				__( 'Gift card %1$s %2$s %3$s at %4$s (ref: %5$s). Balance now %6$s. By %7$s via Redeem Desk.', 'gc-redeem-desk' ),
				$tx['code'],
				isset( $labels[ $tx['type'] ] ) ? $labels[ $tx['type'] ] : $tx['type'],
				wp_strip_all_tags( wc_price( (float) $tx['amount'] ) ),
				$tx['outlet'] ? $tx['outlet'] : '-',
				$tx['reference'] ? $tx['reference'] : '-',
				wp_strip_all_tags( wc_price( (float) $tx['balance_after'] ) ),
				$user && $user->exists() ? $user->display_name : '-'
			)
		);
	}
}
