<?php
/**
 * Tukify admin app — one page holding the Dashboard, every settings tab and
 * Back in stock.
 *
 * Rendered by Tuki_Admin::render_app() for all three Tukify menu pages; the
 * page only decides which panel opens first ($tuki_default_tab), and a #hash
 * in the URL wins. Panels are switched in the browser (tuki-admin-ui.js), so
 * moving between sections never reloads.
 *
 * The settings still persist through the Settings API exactly as before: one
 * <form> posting to options.php with settings_fields(), and hidden panels'
 * fields still submit. The Dashboard and Back-in-stock panels contain only
 * links and type="button" controls, so they never submit it.
 *
 * Expects $tuki_default_tab (a key from Tuki_Admin::sections()).
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tuki_app_sections = Tuki_Admin::sections();
$tuki_current      = isset( $tuki_app_sections[ $tuki_default_tab ] ) ? $tuki_default_tab : 'dashboard';
?>
<div class="tuki-admin" data-theme="<?php echo esc_attr( Tuki_Admin::admin_theme() ); ?>" data-tk-tab="<?php echo esc_attr( $tuki_current ); ?>">
	<?php require TUKI_PLUGIN_DIR . 'admin/partials/sidebar.php'; ?>

	<form method="post" action="options.php" class="tk-main tkfy-form" id="tk-settings-form">
		<?php settings_fields( Tuki_Settings::GROUP ); ?>

		<div class="tk-scroll">
			<?php require TUKI_PLUGIN_DIR . 'admin/partials/header.php'; ?>

			<?php
			// Runs while the page is still parsing, before the first paint: picks
			// the panel from the #hash and moves WordPress notices below the header,
			// so neither the panel nor the notices jump once the page has shown.
			wp_print_inline_script_tag( (string) file_get_contents( TUKI_PLUGIN_DIR . 'admin/js/tuki-admin-boot.js' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			?>

			<?php
			require TUKI_PLUGIN_DIR . 'admin/dashboard-page.php';
			require TUKI_PLUGIN_DIR . 'admin/settings-page.php';
			require TUKI_PLUGIN_DIR . 'admin/stock-notify-page.php';
			?>
		</div><!-- .tk-scroll -->

		<?php // Shown only on settings tabs (CSS keys off data-tk-tab). ?>
		<div class="tk-savebar">
			<p class="tk-hint"><?php esc_html_e( 'One form for every settings tab — saving stores all of them together.', 'tukify' ); ?></p>
			<div class="tk-actions">
				<button type="button" class="tk-btn" data-tk-reset><?php esc_html_e( 'Reset', 'tukify' ); ?></button>
				<button type="submit" class="tk-btn is-primary"><?php esc_html_e( 'Save changes', 'tukify' ); ?></button>
			</div>
		</div>
	</form>
</div>
