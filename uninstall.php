<?php
/**
 * Tukify uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress admin. By default it removes
 * both the custom tables and every `tuki_` option so no Tukify data is left
 * behind. If the store owner enabled "Keep Tukify's data when the plugin is
 * deleted" (the preserve_data_on_uninstall setting), the data is left intact for
 * a later reinstall — but orphaned scheduled jobs are still cleared either way.
 *
 * @package Tukify
 */

// Bail unless WordPress itself invoked this during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Respect the owner's "preserve data" choice (read before anything is deleted).
$tuki_settings      = get_option( 'tuki_settings' );
$tuki_preserve_data = is_array( $tuki_settings ) && ! empty( $tuki_settings['preserve_data_on_uninstall'] );

if ( ! $tuki_preserve_data ) {
	// Drop the custom tables via the shared helper.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tuki-db.php';
	Tuki_DB::drop_tables();

	// Delete all options whose key begins with the `tuki_` prefix.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$tuki_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'tuki_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if ( ! empty( $tuki_option_names ) ) {
		foreach ( $tuki_option_names as $tuki_option_name ) {
			delete_option( $tuki_option_name );
		}
	}

	// Also clear any Tukify transients that may have been cached.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_tuki_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tuki_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Always cancel any Action Scheduler jobs Tukify queued (group "tukify"): the
// callbacks are gone with the plugin, so leaving them scheduled would error.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'tukify' );
}
