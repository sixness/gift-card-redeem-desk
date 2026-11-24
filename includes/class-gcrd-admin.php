<?php
/**
 * Admin screens: Transactions (with CSV export) and Settings (branding/CI, desk, QR,
 * import/export so one project's look can be copied to another).
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GCRD_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_gcrd_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_gcrd_export_settings', array( __CLASS__, 'export_settings' ) );
		add_action( 'admin_post_gcrd_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GCRD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=gcrd-settings' ) ) . '">' . esc_html__( 'Settings', 'gc-redeem-desk' ) . '</a>',
			'<a href="' . esc_url( GCRD_Desk::url() ) . '" target="_blank">' . esc_html__( 'Open desk', 'gc-redeem-desk' ) . '</a>'
		);
		return $links;
	}

	public static function menu() {
		add_menu_page(
			__( 'Redeem Desk', 'gc-redeem-desk' ),
			__( 'Redeem Desk', 'gc-redeem-desk' ),
			GCRD_Install::CAP_REPORTS,
			'gcrd-transactions',
			array( __CLASS__, 'transactions_page' ),
			'dashicons-tickets-alt',
			56
		);
		add_submenu_page( 'gcrd-transactions', __( 'Transactions', 'gc-redeem-desk' ), __( 'Transactions', 'gc-redeem-desk' ), GCRD_Install::CAP_REPORTS, 'gcrd-transactions', array( __CLASS__, 'transactions_page' ) );
		add_submenu_page( 'gcrd-transactions', __( 'Desk Settings', 'gc-redeem-desk' ), __( 'Settings', 'gc-redeem-desk' ), 'manage_options', 'gcrd-settings', array( __CLASS__, 'settings_page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'gcrd-settings' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){$(".gcrd-color").wpColorPicker();$(".gcrd-media").on("click",function(e){e.preventDefault();var t=$("#"+$(this).data("target"));var f=wp.media({title:"Logo",multiple:false,library:{type:"image"}});f.on("select",function(){t.val(f.state().get("selection").first().toJSON().url).trigger("change");});f.open();});});'
		);
	}

	/* ---------------- Transactions ---------------- */

	private static function filters_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g = array();
		foreach ( array( 'code', 'outlet', 'type', 'from', 'to' ) as $k ) {
			$g[ $k ] = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		}
		$g['page'] = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		foreach ( array( 'from', 'to' ) as $k ) {
			if ( $g[ $k ] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g[ $k ] ) ) {
				$g[ $k ] = '';
			}
		}
		return $g;
	}

	public static function transactions_page() {
		if ( ! current_user_can( GCRD_Install::CAP_REPORTS ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gc-redeem-desk' ) );
		}
		$f              = self::filters_from_request();
		$args           = $f;
		$args['per_page'] = 50;
		$res            = GCRD_Ledger::query( $args );
		$pages          = (int) ceil( $res['total'] / 50 );
		$types          = array(
			''         => __( 'All types', 'gc-redeem-desk' ),
			'redeem'   => __( 'Redeem', 'gc-redeem-desk' ),
			'recharge' => __( 'Recharge', 'gc-redeem-desk' ),
			'void'     => __( 'Void', 'gc-redeem-desk' ),
		);
		$csv_url        = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'gcrd_export_csv' ), array_filter( array_intersect_key( $f, array_flip( array( 'code', 'outlet', 'type', 'from', 'to' ) ) ) ) ), admin_url( 'admin-post.php' ) ), 'gcrd_export_csv' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Gift card transactions', 'gc-redeem-desk' ); ?></h1>
			<a href="<?php echo esc_url( GCRD_Desk::url() ); ?>" class="page-title-action" target="_blank"><?php esc_html_e( 'Open desk', 'gc-redeem-desk' ); ?></a>
			<a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'gc-redeem-desk' ); ?></a>
			<hr class="wp-header-end">
			<form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="page" value="gcrd-transactions">
				<input type="search" name="code" placeholder="<?php esc_attr_e( 'Card code', 'gc-redeem-desk' ); ?>" value="<?php echo esc_attr( $f['code'] ); ?>">
				<select name="outlet">
					<option value=""><?php esc_html_e( 'All outlets', 'gc-redeem-desk' ); ?></option>
					<?php foreach ( GCRD_Settings::outlets() as $o ) : ?>
						<option value="<?php echo esc_attr( $o ); ?>" <?php selected( $f['outlet'], $o ); ?>><?php echo esc_html( $o ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="type">
					<?php foreach ( $types as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><?php esc_html_e( 'From', 'gc-redeem-desk' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
				<label><?php esc_html_e( 'To', 'gc-redeem-desk' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
				<?php submit_button( __( 'Filter', 'gc-redeem-desk' ), 'secondary', '', false ); ?>
			</form>
			<p><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d transaction', '%d transactions', $res['total'], 'gc-redeem-desk' ), $res['total'] ) ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th>#</th><th><?php esc_html_e( 'Date', 'gc-redeem-desk' ); ?></th><th><?php esc_html_e( 'Card', 'gc-redeem-desk' ); ?></th><th><?php esc_html_e( 'Type', 'gc-redeem-desk' ); ?></th>
					<th style="text-align:right"><?php esc_html_e( 'Amount', 'gc-redeem-desk' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Balance after', 'gc-redeem-desk' ); ?></th>
					<th><?php esc_html_e( 'Outlet', 'gc-redeem-desk' ); ?></th><th><?php esc_html_e( 'Reference', 'gc-redeem-desk' ); ?></th><th><?php esc_html_e( 'Staff', 'gc-redeem-desk' ); ?></th><th><?php esc_html_e( 'Note', 'gc-redeem-desk' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $res['rows'] ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No transactions found.', 'gc-redeem-desk' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $res['rows'] as $row ) :
					$p = GCRD_Ledger::present( $row );
					?>
					<tr<?php echo $p['voided_by'] ? ' style="opacity:.55"' : ''; ?>>
						<td><?php echo (int) $p['id']; ?></td>
						<td><?php echo esc_html( $p['created_html'] ); ?></td>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gcrd-transactions', 'code' => $p['code'] ), admin_url( 'admin.php' ) ) ); ?>"><code><?php echo esc_html( $p['code'] ); ?></code></a></td>
						<td><?php echo esc_html( isset( $types[ $p['type'] ] ) ? $types[ $p['type'] ] : $p['type'] ); ?><?php echo $p['voided_by'] ? ' <em>(' . esc_html__( 'voided', 'gc-redeem-desk' ) . ' #' . (int) $p['voided_by'] . ')</em>' : ''; ?><?php echo $p['related_id'] ? ' <em>→ #' . (int) $p['related_id'] . '</em>' : ''; ?></td>
						<td style="text-align:right"><?php echo esc_html( $p['amount_html'] ); ?></td>
						<td style="text-align:right"><?php echo esc_html( $p['balance_after_html'] ); ?></td>
						<td><?php echo esc_html( $p['outlet'] ); ?></td>
						<td><?php echo esc_html( $p['reference'] ); ?></td>
						<td><?php echo esc_html( $p['user'] ); ?></td>
						<td><?php echo esc_html( $p['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $f['page'],
							'total'   => $pages,
						)
					)
				);
				?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function export_csv() {
		if ( ! current_user_can( GCRD_Install::CAP_REPORTS ) || ! check_admin_referer( 'gcrd_export_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gc-redeem-desk' ) );
		}
		$f             = self::filters_from_request();
		$f['per_page'] = 5000;
		$f['page']     = 1;
		$res           = GCRD_Ledger::query( $f );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=gift-card-transactions-' . wp_date( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		fputcsv( $out, array( 'id', 'date', 'card', 'type', 'amount', 'balance_before', 'balance_after', 'outlet', 'reference', 'staff', 'note', 'voided_by', 'related_id' ) );
		foreach ( $res['rows'] as $row ) {
			$p = GCRD_Ledger::present( $row );
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_safe' ),
					array( $p['id'], wp_date( 'Y-m-d H:i:s', strtotime( $row['created_at'] . ' UTC' ) ), $p['code'], $p['type'], $row['amount'], $row['balance_before'], $row['balance_after'], $p['outlet'], $p['reference'], $p['user'], $p['note'], $p['voided_by'], $p['related_id'] )
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Stop spreadsheet formula injection.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public static function csv_safe( $v ) {
		if ( is_string( $v ) && '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $v ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/* ---------------- Settings ---------------- */

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'gcrd_save_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gc-redeem-desk' ) );
		}
		$msg = 'saved';
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in GCRD_Settings::sanitize().
		if ( ! empty( $_POST['gcrd_reset'] ) ) {
			GCRD_Settings::save( GCRD_Settings::defaults() );
			$msg = 'reset';
		} elseif ( isset( $_POST['gcrd_form'] ) && 'import' === $_POST['gcrd_form'] ) {
			$json = json_decode( wp_unslash( (string) ( isset( $_POST['gcrd_import'] ) ? $_POST['gcrd_import'] : '' ) ), true );
			if ( is_array( $json ) ) {
				$json = isset( $json['settings'] ) && is_array( $json['settings'] ) ? $json['settings'] : $json;
				GCRD_Settings::save( wp_parse_args( $json, GCRD_Settings::all() ) );
				$msg = 'imported';
			} else {
				$msg = 'badimport';
			}
		} else {
			$in = isset( $_POST['gcrd'] ) && is_array( $_POST['gcrd'] ) ? wp_unslash( $_POST['gcrd'] ) : array();
			foreach ( array( 'require_reference', 'allow_expired', 'order_notes', 'show_recipient', 'qr_enabled', 'delete_on_uninstall' ) as $cb ) {
				$in[ $cb ] = isset( $in[ $cb ] ) ? 'yes' : 'no';
			}
			GCRD_Settings::save( $in );
		}
		// phpcs:enable
		wp_safe_redirect( add_query_arg( array( 'page' => 'gcrd-settings', 'gcrd_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function export_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'gcrd_export_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gc-redeem-desk' ) );
		}
		$data = array(
			'plugin'   => 'gift-card-redeem-desk',
			'version'  => GCRD_VERSION,
			'exported' => gmdate( 'c' ),
			'site'     => home_url(),
			'settings' => GCRD_Settings::all(),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=redeem-desk-settings-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function field( $type, $key, $label, $help = '', $extra = array() ) {
		$v    = GCRD_Settings::get( $key );
		$name = 'gcrd[' . $key . ']';
		echo '<tr><th scope="row"><label for="gcrd-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		switch ( $type ) {
			case 'checkbox':
				echo '<label><input type="checkbox" id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="yes" ' . checked( 'yes', $v, false ) . '> ' . esc_html( isset( $extra['cb_label'] ) ? $extra['cb_label'] : '' ) . '</label>';
				break;
			case 'textarea':
				echo '<textarea id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) ( isset( $extra['rows'] ) ? $extra['rows'] : 4 ) . '" class="large-text code">' . esc_textarea( $v ) . '</textarea>';
				break;
			case 'color':
				echo '<input type="text" id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" class="gcrd-color" data-default-color="' . esc_attr( GCRD_Settings::defaults()[ $key ] ) . '">';
				break;
			case 'number':
				echo '<input type="number" id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" class="small-text" min="' . esc_attr( $extra['min'] ) . '" max="' . esc_attr( $extra['max'] ) . '"> ' . esc_html( isset( $extra['unit'] ) ? $extra['unit'] : '' );
				break;
			case 'select':
				echo '<select id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $extra['options'] as $ov => $ol ) {
					echo '<option value="' . esc_attr( $ov ) . '" ' . selected( $v, $ov, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				echo '</select>';
				break;
			case 'media':
				echo '<input type="url" id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" class="regular-text"> <button type="button" class="button gcrd-media" data-target="gcrd-' . esc_attr( $key ) . '">' . esc_html__( 'Choose image', 'gc-redeem-desk' ) . '</button>';
				if ( $v ) {
					echo '<p><img src="' . esc_url( $v ) . '" alt="" style="max-height:60px;background:#f0f0f1;padding:6px;border-radius:4px"></p>';
				}
				break;
			default:
				echo '<input type="text" id="gcrd-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" class="' . esc_attr( isset( $extra['class'] ) ? $extra['class'] : 'regular-text' ) . '">';
		}
		if ( $help ) {
			echo '<p class="description">' . wp_kses( $help, array( 'code' => array(), 'a' => array( 'href' => array(), 'target' => array() ), 'strong' => array() ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gc-redeem-desk' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg      = isset( $_GET['gcrd_msg'] ) ? sanitize_key( $_GET['gcrd_msg'] ) : '';
		$messages = array(
			'saved'     => array( 'success', __( 'Settings saved.', 'gc-redeem-desk' ) ),
			'reset'     => array( 'success', __( 'Settings reset to defaults.', 'gc-redeem-desk' ) ),
			'imported'  => array( 'success', __( 'Settings imported.', 'gc-redeem-desk' ) ),
			'badimport' => array( 'error', __( 'That is not a valid settings file.', 'gc-redeem-desk' ) ),
		);
		$desk     = GCRD_Desk::url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redeem Desk settings', 'gc-redeem-desk' ); ?></h1>
			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $messages[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Desk address for staff:', 'gc-redeem-desk' ); ?>
				<a href="<?php echo esc_url( $desk ); ?>" target="_blank"><code><?php echo esc_html( $desk ); ?></code></a>
				&nbsp;·&nbsp;
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: users screen URL */
						__( 'Give staff the <strong>Gift Card Staff</strong> role (redeem only) or <strong>Gift Card Manager</strong> role (also recharge, void and reports) in <a href="%s">Users</a>.', 'gc-redeem-desk' ),
						esc_url( admin_url( 'users.php' ) )
					),
					array( 'strong' => array(), 'a' => array( 'href' => array() ) )
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gcrd_save_settings">
				<?php wp_nonce_field( 'gcrd_save_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'Brand (CI)', 'gc-redeem-desk' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'text', 'brand_name', __( 'Brand name', 'gc-redeem-desk' ) );
					self::field( 'media', 'logo_url', __( 'Logo', 'gc-redeem-desk' ), __( 'Shown in the desk header and on printed slips. Use a logo that reads well on the header colour.', 'gc-redeem-desk' ) );
					self::field( 'number', 'logo_height', __( 'Logo height', 'gc-redeem-desk' ), '', array( 'min' => 16, 'max' => 160, 'unit' => 'px' ) );
					self::field( 'color', 'color_primary', __( 'Primary colour', 'gc-redeem-desk' ), __( 'Buttons, card panel and accents.', 'gc-redeem-desk' ) );
					self::field( 'color', 'color_on_primary', __( 'Text on primary', 'gc-redeem-desk' ) );
					self::field( 'color', 'color_header', __( 'Header background', 'gc-redeem-desk' ) );
					self::field( 'color', 'color_bg', __( 'Page background', 'gc-redeem-desk' ) );
					self::field( 'color', 'color_surface', __( 'Panel background', 'gc-redeem-desk' ) );
					self::field( 'color', 'color_text', __( 'Text colour', 'gc-redeem-desk' ) );
					self::field( 'color', 'color_muted', __( 'Secondary text colour', 'gc-redeem-desk' ) );
					self::field( 'text', 'font_body', __( 'Body font stack', 'gc-redeem-desk' ), __( 'CSS font-family list, e.g. <code>"Sarabun", sans-serif</code>.', 'gc-redeem-desk' ), array( 'class' => 'large-text code' ) );
					self::field( 'text', 'font_heading', __( 'Heading font stack', 'gc-redeem-desk' ), '', array( 'class' => 'large-text code' ) );
					self::field( 'text', 'font_css_url', __( 'Font stylesheet URL', 'gc-redeem-desk' ), __( 'Optional. A CSS file that loads the brand fonts, e.g. a Google Fonts link, or a stylesheet on this site with @font-face rules.', 'gc-redeem-desk' ), array( 'class' => 'large-text code' ) );
					self::field( 'number', 'radius', __( 'Corner radius', 'gc-redeem-desk' ), '', array( 'min' => 0, 'max' => 40, 'unit' => 'px' ) );
					self::field( 'textarea', 'custom_css', __( 'Custom CSS', 'gc-redeem-desk' ), __( 'Added last on the desk page. Desk elements use classes starting with <code>.gcrd-</code>.', 'gc-redeem-desk' ), array( 'rows' => 6 ) );
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Desk', 'gc-redeem-desk' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'text', 'desk_title', __( 'Desk title', 'gc-redeem-desk' ) );
					self::field( 'text', 'desk_slug', __( 'Desk address', 'gc-redeem-desk' ), sprintf( /* translators: %s: example */ __( 'The page lives at %s', 'gc-redeem-desk' ), '<code>' . esc_html( home_url( '/' ) ) . '<strong>' . esc_html( GCRD_Settings::get( 'desk_slug' ) ) . '</strong>/</code>' ) );
					self::field( 'textarea', 'outlets', __( 'Outlets', 'gc-redeem-desk' ), __( 'One per line. Staff must choose one for each transaction; the desk remembers the last choice on each device.', 'gc-redeem-desk' ), array( 'rows' => 5 ) );
					self::field( 'text', 'reference_label', __( 'Reference field label', 'gc-redeem-desk' ) );
					self::field( 'checkbox', 'require_reference', __( 'Reference', 'gc-redeem-desk' ), '', array( 'cb_label' => __( 'Required for every redeem and recharge', 'gc-redeem-desk' ) ) );
					self::field(
						'select',
						'card_source',
						__( 'Which coupons are gift cards', 'gc-redeem-desk' ),
						__( 'Gift card plugins supported out of the box: WP Swings / MakeWebBetter Ultimate Gift Cards. Other plugins can use the <code>gcrd_is_gift_card</code> filter.', 'gc-redeem-desk' ),
						array(
							'options' => array(
								'giftcards' => __( 'Only coupons created by a gift card plugin', 'gc-redeem-desk' ),
								'any'       => __( 'Any fixed-cart coupon', 'gc-redeem-desk' ),
							),
						)
					);
					self::field( 'checkbox', 'allow_expired', __( 'Expired cards', 'gc-redeem-desk' ), '', array( 'cb_label' => __( 'Allow redeeming and recharging expired cards', 'gc-redeem-desk' ) ) );
					self::field( 'checkbox', 'show_recipient', __( 'Card details', 'gc-redeem-desk' ), '', array( 'cb_label' => __( 'Show recipient, sender, buyer and message to staff (helps check ID)', 'gc-redeem-desk' ) ) );
					self::field( 'checkbox', 'order_notes', __( 'Order notes', 'gc-redeem-desk' ), '', array( 'cb_label' => __( 'Add a note to the original gift card order for every transaction', 'gc-redeem-desk' ) ) );
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'QR code in gift card emails', 'gc-redeem-desk' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'checkbox', 'qr_enabled', __( 'QR code', 'gc-redeem-desk' ), __( 'Put <code>[REDEEM_QR]</code> in the gift card email template where the QR should appear. The QR opens this desk with the card filled in.', 'gc-redeem-desk' ), array( 'cb_label' => __( 'Replace [REDEEM_QR] with the card QR code', 'gc-redeem-desk' ) ) );
					self::field( 'number', 'qr_size', __( 'QR size', 'gc-redeem-desk' ), '', array( 'min' => 80, 'max' => 400, 'unit' => 'px' ) );
					self::field( 'color', 'qr_dark', __( 'QR colour', 'gc-redeem-desk' ), __( 'Keep it dark on a light background so every phone can read it.', 'gc-redeem-desk' ) );
					self::field( 'color', 'qr_light', __( 'QR background', 'gc-redeem-desk' ) );
					self::field( 'text', 'qr_label', __( 'Text under QR', 'gc-redeem-desk' ), __( 'Leave empty for none.', 'gc-redeem-desk' ) );
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Data', 'gc-redeem-desk' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php self::field( 'checkbox', 'delete_on_uninstall', __( 'Uninstall', 'gc-redeem-desk' ), '', array( 'cb_label' => __( 'Delete settings and the transaction log when the plugin is deleted', 'gc-redeem-desk' ) ) ); ?>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Copy settings between projects', 'gc-redeem-desk' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gcrd_export_settings' ), 'gcrd_export_settings' ) ); ?>"><?php esc_html_e( 'Download settings (.json)', 'gc-redeem-desk' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gcrd_save_settings">
				<?php wp_nonce_field( 'gcrd_save_settings' ); ?>
				<input type="hidden" name="gcrd_form" value="import">
				<p><label for="gcrd-import"><?php esc_html_e( 'Paste a settings file to import:', 'gc-redeem-desk' ); ?></label></p>
				<textarea id="gcrd-import" name="gcrd_import" rows="5" class="large-text code"></textarea>
				<p>
					<?php submit_button( __( 'Import settings', 'gc-redeem-desk' ), 'secondary', 'submit', false ); ?>
					<button type="submit" name="gcrd_reset" value="1" class="button-link-delete" style="margin-left:16px" onclick="return confirm('<?php echo esc_js( __( 'Reset all desk settings to defaults?', 'gc-redeem-desk' ) ); ?>');"><?php esc_html_e( 'Reset to defaults', 'gc-redeem-desk' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
