<?php
/**
 * Plugin Name:       Tukify — AI Shopping Assistant for WooCommerce
 * Plugin URI:        https://github.com/sh03rohan/tukify
 * Description:       AI shopping assistant for WooCommerce: semantic product search and grounded conversational recommendations, all data on your own server.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sh Rohan
 * Author URI:        https://github.com/sh03rohan
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tukify
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Core plugin constants.
define( 'TUKI_VERSION', '1.5.0' );
define( 'TUKI_PLUGIN_FILE', __FILE__ );
define( 'TUKI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TUKI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TUKI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Feature flag: the WhatsApp channel.
 *
 * The channel is fully built but ships locked ("Coming soon"). While this is
 * false the settings UI shows a teaser and the feature is inert: the webhook
 * route is not registered, its table is not created, no scheduled tasks run,
 * and the settings cannot be enabled or saved — server-side, not just visually.
 *
 * Define it as true in wp-config.php to try it before launch; flipping the
 * default here is the single switch that ships it.
 */
if ( ! defined( 'TUKI_WHATSAPP_ENABLED' ) ) {
	define( 'TUKI_WHATSAPP_ENABLED', false );
}

/**
 * Runs on plugin activation.
 *
 * Creates the custom database tables required by Tukify.
 *
 * @return void
 */
function tuki_activate() {
	require_once TUKI_PLUGIN_DIR . 'includes/class-tuki-db.php';
	Tuki_DB::create_tables();

	// Record the first activation so the review request can wait until the store
	// has genuinely used the plugin for a while. add_option() never overwrites an
	// existing value, so re-activating does not reset the clock.
	add_option( 'tuki_activated_at', time(), '', false );
}
register_activation_hook( __FILE__, 'tuki_activate' );

/**
 * Runs on plugin deactivation.
 *
 * Intentionally non-destructive: no data is removed on deactivation.
 * Full cleanup happens in uninstall.php.
 *
 * @return void
 */
function tuki_deactivate() {
	// Clear the daily demand-log purge so no orphan cron remains after disable.
	wp_clear_scheduled_hook( 'tuki_purge_demand' );
}
register_deactivation_hook( __FILE__, 'tuki_deactivate' );

/**
 * Declares compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * @return void
 */
function tuki_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TUKI_PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'tuki_declare_wc_compatibility' );

/**
 * Shows a friendly admin notice when WooCommerce is not active.
 *
 * @return void
 */
function tuki_wc_missing_notice() {
	if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Tukify needs WooCommerce to be installed and active. Semantic search, chat, and the widgets stay dormant until then.', 'tukify' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'tuki_wc_missing_notice' );

/**
 * Boots the plugin.
 *
 * @return void
 */
function tuki_run() {
	require_once TUKI_PLUGIN_DIR . 'includes/class-tuki-plugin.php';
	Tuki_Plugin::instance();
}
tuki_run();
