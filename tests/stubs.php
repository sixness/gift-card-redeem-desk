<?php
// Minimal WordPress/WooCommerce stubs to unit-test the ledger logic against SQLite.
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'GCRD_VERSION', 'test' );
define( 'GCRD_DB_VERSION', '1' );

$GLOBALS['_meta']    = array();
$GLOBALS['_posts']   = array();
$GLOBALS['_options'] = array();
$GLOBALS['_user']    = 7;
$GLOBALS['_notes']   = array();
$GLOBALS['_actions'] = array();

class WP_Error {
	public $code, $message, $data;
	function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 == $n ? $a : $b; }
function esc_html__( $s ) { return $s; }
function apply_filters( $h, $v ) { return $v; }
function do_action( $h, ...$a ) { $GLOBALS['_actions'][] = $h; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $c ) ? $c : null; }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', trim( $s ) ) ); }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : ''; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_bloginfo() { return 'Test Site'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['_options'] ) ? $GLOBALS['_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['_options'][ $k ] = $v; return true; }
function get_current_user_id() { return $GLOBALS['_user']; }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Staff ' . $id ); }
function wp_get_current_user() { return new class { public $display_name = 'Staff 7'; function exists() { return true; } }; }
function current_time( $t, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_gmt_from_date( $d ) { return $d; }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['_meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['_meta'][ $id ][ $k ] = $v; }
function get_post_status( $id ) { return $GLOBALS['_posts'][ $id ]['status'] ?? false; }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $n, $dp = false ) { $n = (float) $n; return false === $dp ? (string) $n : number_format( $n, $dp, '.', '' ); }
function wc_price( $n ) { return '฿' . number_format( (float) $n, 2 ); }
function wc_format_coupon_code( $c ) { return strtolower( trim( $c ) ); }
function wc_get_coupon_id_by_code( $c ) { foreach ( $GLOBALS['_posts'] as $id => $p ) { if ( strtolower( $p['code'] ) === strtolower( $c ) ) { return $id; } } return 0; }
function wc_get_order( $id ) { return $id == 500 ? new FakeOrder() : false; }
function current_user_can( $c ) { return true; }

class FakeOrder {
	function add_order_note( $n ) { $GLOBALS['_notes'][] = $n; }
	function get_items() { return array(); }
	function get_billing_first_name() { return 'Jane'; }
	function get_billing_last_name() { return 'Doe'; }
	function get_edit_order_url() { return 'https://x/order'; }
}
class FakeDate {
	public $ts;
	function __construct( $ts ) { $this->ts = $ts; }
	function getTimestamp() { return $this->ts; }
	function date( $f ) { return gmdate( $f, $this->ts ); }
	function date_i18n( $f ) { return gmdate( $f, $this->ts ); }
}
class WC_Coupon {
	private $id;
	function __construct( $id ) { $this->id = isset( $GLOBALS['_posts'][ $id ] ) ? $id : 0; }
	function get_id() { return $this->id; }
	function get_code() { return $GLOBALS['_posts'][ $this->id ]['code']; }
	function get_amount( $ctx = 'view' ) { return $GLOBALS['_meta'][ $this->id ]['coupon_amount']; }
	function set_amount( $a ) { $this->pending = $a; }
	function save() { if ( isset( $this->pending ) ) { $GLOBALS['_meta'][ $this->id ]['coupon_amount'] = (string) $this->pending; } }
	function get_discount_type() { return 'fixed_cart'; }
	function get_date_expires() { $e = $GLOBALS['_posts'][ $this->id ]['expires'] ?? null; return $e ? new FakeDate( $e ) : null; }
	function get_date_created() { return new FakeDate( time() - 86400 ); }
}

// wpdb on SQLite.
class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	private $pdo;
	private $locks = array();
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_gcrd_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, coupon_id INT, code TEXT, type TEXT, amount REAL, balance_before REAL, balance_after REAL, outlet TEXT, reference TEXT, note TEXT, user_id INT, ip TEXT, related_id INT NULL, voided_by INT NULL, created_at TEXT)' );
	}
	function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : ( '%f' === $m[0] ? (string) (float) $v : $this->pdo->quote( (string) $v ) );
		}, $q );
	}
	function get_var( $q ) {
		if ( preg_match( "/SELECT GET_LOCK\('([^']+)'/", $q, $m ) ) { if ( ! empty( $this->locks[ $m[1] ] ) ) { return '0'; } $this->locks[ $m[1] ] = 1; return '1'; }
		if ( preg_match( "/SELECT RELEASE_LOCK\('([^']+)'/", $q, $m ) ) { unset( $this->locks[ $m[1] ] ); return '1'; }
		return $this->pdo->query( $q )->fetchColumn();
	}
	function hold_lock( $name ) { $this->locks[ $name ] = 1; }
	function get_row( $q ) { $r = $this->pdo->query( $q )->fetch( PDO::FETCH_ASSOC ); return $r ?: null; }
	function get_results( $q ) { return $this->pdo->query( $q )->fetchAll( PDO::FETCH_ASSOC ); }
	function insert( $t, $row ) {
		$cols = array_keys( $row );
		$st   = $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' );
		$st->execute( array_values( $row ) );
		$this->insert_id = (int) $this->pdo->lastInsertId();
	}
	function update( $t, $data, $where ) {
		$set = array(); foreach ( $data as $k => $v ) { $set[] = "$k = " . $this->pdo->quote( $v ); }
		$w = array(); foreach ( $where as $k => $v ) { $w[] = "$k = " . $this->pdo->quote( $v ); }
		$this->pdo->exec( "UPDATE $t SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $w ) );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();
