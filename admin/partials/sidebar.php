<?php
/**
 * Shared Tukify admin sidebar (Dashboard, Settings, Back in stock screens).
 *
 * Expects $tuki_current: the active section key from Tuki_Admin::sections().
 * Every item is a real link (so it works without JS and in a new tab), and
 * tuki-admin-ui.js turns a plain click into an in-page switch — all panels are
 * on every Tukify screen. The tkfy-tab-<key> ids are what each panel's
 * aria-labelledby points at.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tuki_sections = Tuki_Admin::sections();

$tuki_groups = array(
	'overview' => __( 'Overview', 'tukify' ),
	'settings' => __( 'Settings', 'tukify' ),
	'store'    => __( 'Store', 'tukify' ),
);

// Static icon paths (no user data), drawn at 24x24 with a 1.75 stroke.
$tuki_icons = array(
	'dashboard'  => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
	'general'    => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
	'appearance' => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
	'engagement' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
	'products'   => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
	'channels'   => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l2-5.1a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 8.5-8.6 8.4 8.4 0 0 1 8.4 8z"/>',
	'kb'         => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
	'analytics'  => '<path d="M3 3v18h18"/><path d="M8 16v-5M13 16V8M18 16v-3"/>',
	'advanced'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
	'stock'      => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
);
?>
<aside class="tk-side" aria-label="<?php esc_attr_e( 'Tukify', 'tukify' ); ?>">
	<div class="tk-brand">
		<span class="tk-chip"><img src="<?php echo esc_url( Tuki_Settings::logo_url() ); ?>" alt="" /></span>
		<span class="tk-brand-text">
			<span class="tk-brand-title">Tukify</span>
			<span class="tk-brand-sub"><?php esc_html_e( 'AI shopping assistant', 'tukify' ); ?></span>
		</span>
	</div>

	<div class="tk-navs">
		<?php foreach ( $tuki_groups as $tuki_group => $tuki_group_label ) : ?>
			<nav class="tk-group" aria-label="<?php echo esc_attr( $tuki_group_label ); ?>">
				<span class="tk-glabel"><?php echo esc_html( $tuki_group_label ); ?></span>
				<?php
				foreach ( $tuki_sections as $tuki_key => $tuki_section ) :
					if ( $tuki_section['group'] !== $tuki_group ) {
						continue;
					}
					?>
					<a
						class="tk-tab"
						href="<?php echo esc_url( $tuki_section['url'] ); ?>"
						id="tkfy-tab-<?php echo esc_attr( $tuki_key ); ?>"
						data-tab="<?php echo esc_attr( $tuki_key ); ?>"
						data-title="<?php echo esc_attr( $tuki_section['label'] ); ?>"
						data-desc="<?php echo esc_attr( $tuki_section['desc'] ); ?>"
						<?php echo $tuki_key === $tuki_current ? 'aria-current="page"' : ''; ?>
					>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?php echo $tuki_icons[ $tuki_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG literals above. ?></svg>
						<span><?php echo esc_html( $tuki_section['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endforeach; ?>
	</div>
</aside>
