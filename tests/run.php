<?php
require __DIR__ . '/stubs.php';
$P = dirname( __DIR__ ) . '/includes/';
require $P . 'class-gcrd-settings.php';
require $P . 'class-gcrd-install.php';
require $P . 'class-gcrd-cards.php';
require $P . 'class-gcrd-ledger.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ✓ $label\n"; } else { $fail++; echo "  ✗ $label\n"; } }
function err( $r ) { return is_wp_error( $r ) ? $r->code : 'ok'; }

// Fixtures: 101 active WPS card 20,000 (order 500); 102 expired; 103 not a gift card; 104 trashed; 105 zero balance.
$GLOBALS['_posts'] = array(
	101 => array( 'code' => 'imiu0', 'status' => 'publish', 'expires' => time() + 86400 * 30 ),
	102 => array( 'code' => 'old11', 'status' => 'publish', 'expires' => time() - 86400 ),
	103 => array( 'code' => 'promo', 'status' => 'publish', 'expires' => null ),
	104 => array( 'code' => 'trash', 'status' => 'trash', 'expires' => null ),
	105 => array( 'code' => 'zero0', 'status' => 'publish', 'expires' => null ),
);
$GLOBALS['_meta'] = array(
	101 => array( 'coupon_amount' => '20000', 'wps_wgm_giftcard_coupon' => '500', 'wps_wgm_coupon_amount' => '20000' ),
	102 => array( 'coupon_amount' => '5000', 'mwb_wgm_giftcard_coupon' => '400' ),
	103 => array( 'coupon_amount' => '100' ),
	104 => array( 'coupon_amount' => '900', 'wps_wgm_giftcard_coupon' => '1' ),
	105 => array( 'coupon_amount' => '0', 'wps_wgm_giftcard_coupon' => '2' ),
);
$ctx = array( 'outlet' => 'Spa', 'reference' => 'F-1001', 'note' => 'massage' );

echo "Lookup\n";
ok( GCRD_Cards::find( 'IMIU0' ) !== null, 'finds card case-insensitive' );
ok( GCRD_Cards::find( ' https://x.com/redeem-desk/?code=imiu0 ' ) !== null, 'finds card from scanned desk URL' );
ok( GCRD_Cards::find( 'PROMO' ) === null, 'plain coupon is not a gift card (default source)' );
ok( GCRD_Cards::find( 'nope' ) === null, 'unknown code returns null' );
ok( GCRD_Cards::status( new WC_Coupon( 102 ) ) === 'expired', 'expired status' );
ok( GCRD_Cards::status( new WC_Coupon( 104 ) ) === 'disabled', 'trashed = disabled' );
ok( GCRD_Cards::status( new WC_Coupon( 105 ) ) === 'empty', 'zero balance = empty' );
$arr = GCRD_Cards::to_array( new WC_Coupon( 101 ) );
ok( $arr['code'] === 'IMIU0' && $arr['balance'] == 20000 && $arr['original'] == 20000 && $arr['buyer'] === 'Jane Doe', 'card data shape' );

echo "Validation\n";
ok( err( GCRD_Ledger::redeem( 'imiu0', 0, $ctx ) ) === 'gcrd_amount', 'zero amount rejected' );
ok( err( GCRD_Ledger::redeem( 'imiu0', '-5', $ctx ) ) === 'gcrd_amount', 'negative amount rejected' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 'abc', $ctx ) ) === 'gcrd_amount', 'non-numeric rejected' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 100, array( 'outlet' => 'Pool Bar', 'reference' => 'x' ) ) ) === 'gcrd_outlet', 'unknown outlet rejected' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 100, array( 'outlet' => 'Spa', 'reference' => '' ) ) ) === 'gcrd_reference', 'missing reference rejected' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 20000.01, $ctx ) ) === 'gcrd_insufficient', 'over balance rejected' );
ok( err( GCRD_Ledger::redeem( 'old11', 10, $ctx ) ) === 'gcrd_expired', 'expired card rejected' );
ok( err( GCRD_Ledger::redeem( 'trash', 10, $ctx ) ) === 'gcrd_not_found' || err( GCRD_Ledger::redeem( 'trash', 10, $ctx ) ) === 'gcrd_disabled', 'trashed card rejected' );
ok( err( GCRD_Ledger::redeem( 'promo', 10, $ctx ) ) === 'gcrd_not_found', 'non gift card coupon rejected' );
ok( $GLOBALS['_meta'][101]['coupon_amount'] === '20000', 'balance unchanged after rejects' );

echo "Redeem / recharge\n";
$r = GCRD_Ledger::redeem( 'IMIU0', '1,500.50', $ctx );
ok( ! is_wp_error( $r ) && (float) $GLOBALS['_meta'][101]['coupon_amount'] === 18499.5, 'partial redeem 1,500.50 → 18,499.50' );
ok( $r['transaction']['type'] === 'redeem' && $r['transaction']['outlet'] === 'Spa' && $r['card']['balance'] == 18499.5, 'response has tx + new card' );
ok( count( $GLOBALS['_notes'] ) === 1 && strpos( $GLOBALS['_notes'][0], 'IMIU0 redeemed' ) !== false, 'order note added' );
$r2 = GCRD_Ledger::redeem( 'imiu0', 18499.50, array( 'outlet' => 'Restaurant', 'reference' => 'B-22' ) );
ok( ! is_wp_error( $r2 ) && (float) $GLOBALS['_meta'][101]['coupon_amount'] == 0, 'redeem full remaining balance → 0' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 1, $ctx ) ) === 'gcrd_insufficient', 'cannot redeem from empty card' );
$r3 = GCRD_Ledger::recharge( 'imiu0', 3000, array( 'outlet' => 'Front Desk', 'reference' => 'R-1' ) );
ok( ! is_wp_error( $r3 ) && (float) $GLOBALS['_meta'][101]['coupon_amount'] == 3000, 'recharge empty card → 3,000' );
ok( $GLOBALS['_meta'][101]['wps_wgm_coupon_amount'] === '20000', 'original value untouched' );

echo "Void\n";
$v = GCRD_Ledger::void( $r['transaction']['id'], 'wrong amount' );
ok( ! is_wp_error( $v ) && (float) $GLOBALS['_meta'][101]['coupon_amount'] == 4500.5, 'void redeem adds 1,500.50 back → 4,500.50' );
ok( err( GCRD_Ledger::void( $r['transaction']['id'], 'again' ) ) === 'gcrd_tx_voided', 'cannot void twice' );
ok( err( GCRD_Ledger::void( $v['transaction']['id'], 'x' ) ) === 'gcrd_tx_void', 'cannot void a void' );
ok( err( GCRD_Ledger::void( $r2['transaction']['id'], '' ) ) === 'gcrd_reason', 'void needs a reason' );
GCRD_Ledger::redeem( 'imiu0', 4000, $ctx ); // balance 500.50
ok( err( GCRD_Ledger::void( $r3['transaction']['id'], 'test' ) ) === 'gcrd_void_balance', 'cannot void recharge already spent' );
ok( err( GCRD_Ledger::void( 99999, 'x' ) ) === 'gcrd_tx_not_found', 'unknown tx' );

echo "Locking\n";
$GLOBALS['wpdb']->hold_lock( 'gcrd_card_101' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 1, $ctx ) ) === 'gcrd_busy', 'concurrent change refused while card locked' );
$GLOBALS['wpdb']->get_var( "SELECT RELEASE_LOCK('gcrd_card_101')" );
ok( err( GCRD_Ledger::redeem( 'imiu0', 0.5, $ctx ) ) === 'ok', 'works again after lock released' );
ok( err( GCRD_Ledger::redeem( 'imiu0', 0.5, $ctx ) ) === 'ok', 'lock released after success (no leak)' );

echo "Query\n";
$q = GCRD_Ledger::query( array( 'coupon_id' => 101 ) );
ok( $q['total'] === 7, 'history count = 7 (' . $q['total'] . ')' );
$q = GCRD_Ledger::query( array( 'outlet' => 'Spa', 'type' => 'redeem' ) );
ok( $q['total'] === 4, 'filter outlet+type (' . $q['total'] . ')' );
$p = GCRD_Ledger::present( $q['rows'][0] );
ok( isset( $p['amount_html'], $p['created_html'], $p['user'] ), 'present() shape' );

echo "Settings\n";
$s = GCRD_Settings::sanitize( array( 'color_primary' => 'red', 'radius' => 999, 'desk_slug' => 'My Desk!', 'outlets' => "A\n\n<b>B</b>\n", 'font_body' => 'Arial;}body{x', 'qr_enabled' => 'yes', 'card_source' => 'bad' ) );
ok( $s['color_primary'] === '#a1967e' && $s['radius'] === 40 && $s['desk_slug'] === 'my-desk-' && $s['outlets'] === "A\nB", 'sanitizes colours, ranges, slug, outlets' );
ok( strpos( $s['font_body'], ';' ) === false && strpos( $s['font_body'], '{' ) === false, 'font stack cannot break out of CSS' );
ok( $s['card_source'] === 'giftcards', 'invalid enum falls back' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
