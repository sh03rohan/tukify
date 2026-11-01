<?php
/**
 * Shared Tukify admin page header: title + description, theme toggle and the
 * connection pill. The wp-header-end marker right after it is where WordPress
 * (common.js) moves admin notices, so they sit below this header.
 *
 * Expects $tuki_current: the active section key from Tuki_Admin::sections().
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tuki_head_sections = Tuki_Admin::sections();
$tuki_head_section  = $tuki_head_sections[ $tuki_current ];
$tuki_head_conn     = Tuki_Admin::connection_state();
$tuki_head_pill     = array(
	'ok'   => '',
	'warn' => ' is-warn',
	'none' => ' is-muted',
);
$tuki_head_light    = 'light' === Tuki_Admin::admin_theme();
?>
<header class="tk-phead">
	<div class="tk-phead-text">
		<h1 class="tk-ptitle" id="tk-ptitle"><?php echo esc_html( $tuki_head_section['label'] ); ?></h1>
		<p class="tk-pdesc" id="tk-pdesc"><?php echo esc_html( $tuki_head_section['desc'] ); ?></p>
	</div>
	<div class="tk-hright">
		<span class="tk-tgl">
			<label for="tk-theme-switch"><?php esc_html_e( 'Light mode', 'tukify' ); ?></label>
			<button type="button" class="tk-sw" id="tk-theme-switch" role="switch" aria-checked="<?php echo $tuki_head_light ? 'true' : 'false'; ?>"></button>
		</span>
		<span class="tk-pill<?php echo esc_attr( isset( $tuki_head_pill[ $tuki_head_conn['state'] ] ) ? $tuki_head_pill[ $tuki_head_conn['state'] ] : ' is-muted' ); ?>">
			<span class="tk-dot" aria-hidden="true"></span><?php echo esc_html( $tuki_head_conn['label'] ); ?>
		</span>
	</div>
</header>
<hr class="wp-header-end" />
