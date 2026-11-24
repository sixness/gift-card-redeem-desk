<?php
/**
 * Desk page template. Variables: $s (settings), $config (JS config), $allowed (bool), $user (WP_User).
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$gcrd_title = $s['desk_title'];
$gcrd_ver   = GCRD_VERSION;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo esc_attr( $s['color_primary'] ); ?>">
<title><?php echo esc_html( $gcrd_title . ' · ' . $s['brand_name'] ); ?></title>
<?php if ( $s['font_css_url'] ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $s['font_css_url'] ); ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url( GCRD_URL . 'assets/desk.css?ver=' . $gcrd_ver ); ?>">
<style><?php echo GCRD_Settings::css_vars(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values sanitized on save. ?></style>
<?php if ( '' !== trim( $s['custom_css'] ) ) : ?>
<style><?php echo wp_strip_all_tags( $s['custom_css'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
<?php endif; ?>
</head>
<body class="gcrd">
<header class="gcrd-header">
	<div class="gcrd-brand">
		<?php if ( $s['logo_url'] ) : ?>
			<img class="gcrd-logo" src="<?php echo esc_url( $s['logo_url'] ); ?>" alt="<?php echo esc_attr( $s['brand_name'] ); ?>">
		<?php else : ?>
			<span class="gcrd-brand-name"><?php echo esc_html( $s['brand_name'] ); ?></span>
		<?php endif; ?>
		<span class="gcrd-desk-title"><?php echo esc_html( $gcrd_title ); ?></span>
	</div>
	<?php if ( $allowed ) : ?>
	<div class="gcrd-header-tools">
		<label class="gcrd-outlet">
			<span class="gcrd-sr"><?php esc_html_e( 'Outlet', 'gc-redeem-desk' ); ?></span>
			<select id="gcrd-outlet"></select>
		</label>
		<span class="gcrd-user"><?php echo esc_html( $user->display_name ); ?></span>
		<?php if ( $config['canReports'] ) : ?>
			<a class="gcrd-link" href="<?php echo esc_url( admin_url( 'admin.php?page=gcrd-transactions' ) ); ?>"><?php esc_html_e( 'Reports', 'gc-redeem-desk' ); ?></a>
		<?php endif; ?>
		<a class="gcrd-link" href="<?php echo esc_url( wp_logout_url( GCRD_Desk::url() ) ); ?>"><?php esc_html_e( 'Log out', 'gc-redeem-desk' ); ?></a>
	</div>
	<?php endif; ?>
</header>

<?php if ( ! $allowed ) : ?>
<main class="gcrd-main gcrd-main--single">
	<section class="gcrd-panel gcrd-denied">
		<h1><?php esc_html_e( 'No access', 'gc-redeem-desk' ); ?></h1>
		<p><?php esc_html_e( 'Your account cannot use the redeem desk. Ask an administrator to give you the Gift Card Staff or Gift Card Manager role.', 'gc-redeem-desk' ); ?></p>
		<p><a class="gcrd-btn gcrd-btn--ghost" href="<?php echo esc_url( wp_logout_url( GCRD_Desk::url() ) ); ?>"><?php esc_html_e( 'Log in as someone else', 'gc-redeem-desk' ); ?></a></p>
	</section>
</main>
<?php else : ?>
<main class="gcrd-main">
	<div class="gcrd-col">
		<section class="gcrd-panel gcrd-search">
			<form id="gcrd-search-form" autocomplete="off">
				<label for="gcrd-code" class="gcrd-label"><?php esc_html_e( 'Gift card code', 'gc-redeem-desk' ); ?></label>
				<div class="gcrd-search-row">
					<input id="gcrd-code" class="gcrd-input gcrd-input--code" type="text" inputmode="text" autocapitalize="characters" spellcheck="false" maxlength="100" placeholder="">
					<button type="submit" class="gcrd-btn gcrd-btn--primary" id="gcrd-lookup"></button>
					<button type="button" class="gcrd-btn gcrd-btn--ghost gcrd-btn--icon" id="gcrd-scan" aria-label="">
						<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3 3h6v2H5v4H3V3zm12 0h6v6h-2V5h-4V3zM3 15h2v4h4v2H3v-6zm16 0h2v6h-6v-2h4v-4zM7 11h10v2H7z"/></svg>
						<span class="gcrd-btn-text"></span>
					</button>
				</div>
				<p class="gcrd-msg" id="gcrd-search-msg" role="alert" hidden></p>
			</form>
		</section>

		<section class="gcrd-panel gcrd-card" id="gcrd-card" hidden aria-live="polite"></section>
	</div>

	<aside class="gcrd-col gcrd-col--side">
		<section class="gcrd-panel">
			<div class="gcrd-side-head">
				<h2 id="gcrd-today-title"></h2>
				<span class="gcrd-today-total" id="gcrd-today-total"></span>
			</div>
			<ul class="gcrd-tx-list" id="gcrd-today"></ul>
		</section>
	</aside>
</main>

<div class="gcrd-modal" id="gcrd-modal" hidden>
	<div class="gcrd-modal-box" role="dialog" aria-modal="true" aria-labelledby="gcrd-modal-title">
		<div id="gcrd-modal-body"></div>
	</div>
</div>

<div class="gcrd-slip" id="gcrd-slip" aria-hidden="true"></div>

<script>window.GCRD = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( GCRD_URL . 'assets/desk.js?ver=' . $gcrd_ver ); ?>"></script>
<?php endif; ?>
</body>
</html>
