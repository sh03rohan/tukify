<?php
/**
 * Tukify uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes both custom
 * tables and every `tuki_` option so no Tukify data is left behind.
 *
 * @package Tukify
 */

// Bail unless WordPress itself invoked this during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

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

// Cancel any Action Scheduler jobs Tukify may have queued (group "tukify").
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'tukify' );
}
