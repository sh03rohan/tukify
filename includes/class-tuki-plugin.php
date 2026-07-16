<?php
/**
 * Core plugin orchestrator.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton orchestrator that bootstraps Tukify.
 *
 * Loads the includes/ folder and wires up core hooks. Later phases register
 * their subsystems (settings, indexer, search, chat, REST, etc.) from here.
 */
final class Tuki_Plugin {

	/**
	 * Single shared instance.
	 *
	 * @var Tuki_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single shared instance, creating it on first use.
	 *
	 * @return Tuki_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use Tuki_Plugin::instance().
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
		$this->init_components();
	}

	/**
	 * Loads all PHP class files in the includes/ folder.
	 *
	 * Files are named `class-tuki-*.php` and `interface-tuki-*.php`. This file
	 * itself is excluded to avoid re-declaration. Loading is recursive so the
	 * providers/ subfolder is picked up automatically as it is populated in
	 * later phases.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$includes_dir = TUKI_PLUGIN_DIR . 'includes/';

		$patterns = array(
			$includes_dir . 'interface-tuki-*.php',
			$includes_dir . 'class-tuki-*.php',
			$includes_dir . 'providers/interface-tuki-*.php',
			$includes_dir . 'providers/class-tuki-*.php',
		);

		foreach ( $patterns as $pattern ) {
			$files = glob( $pattern );

			if ( empty( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				// Skip this orchestrator to prevent re-declaration.
				if ( __FILE__ === $file ) {
					continue;
				}

				require_once $file;
			}
		}
	}

	/**
	 * Registers core WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Translations are loaded automatically for wordpress.org-hosted plugins
		// (WordPress 4.6+), so no load_plugin_textdomain() call is needed.

		// Version-gated: runs create_tables() once when the plugin version changes,
		// so new tables (e.g. the KB table) appear on existing installs.
		add_action( 'init', array( 'Tuki_DB', 'maybe_upgrade' ) );
	}

	/**
	 * Instantiates the plugin's subsystems.
	 *
	 * Settings registration is always loaded (options.php processing is
	 * admin-only anyway); the admin UI is only wired up in the admin context.
	 *
	 * @return void
	 */
	private function init_components() {
		new Tuki_Settings();

		// Registered on every request so its Action Scheduler callbacks and
		// product-change listeners fire during cron/background runs too.
		new Tuki_Indexer();

		// REST endpoints (chat, search, add-to-cart, event).
		new Tuki_Rest();

		// Analytics: order-attribution hooks (event logging is via static calls).
		new Tuki_Analytics();

		// Back-in-stock notifier: binds stock-change listeners (admin + checkout)
		// and the front-end unsubscribe handler, so it is needed on every request.
		new Tuki_Stock_Notify();

		// Knowledge base: page-change re-embed + async reindex handler.
		new Tuki_KB();

		// WhatsApp channel: the async webhook processor and the tokenized
		// add-to-cart link handler must be registered on every request (the
		// processor runs during Action Scheduler/cron, with no admin context).
		new Tuki_WhatsApp();

		// Purges stale WhatsApp conversations on a daily schedule.
		new Tuki_WA_Sessions();

		// Frontend asset controller is always loaded so its static enqueue helpers
		// are available to Elementor even in the (admin-ajax) editor context; it is
		// only instantiated on the storefront, where it hooks wp_enqueue_scripts.
		require_once TUKI_PLUGIN_DIR . 'public/class-tuki-frontend.php';

		// Elementor widgets (hooks are namespaced, so this is inert without Elementor).
		require_once TUKI_PLUGIN_DIR . 'elementor/class-tuki-elementor.php';
		new Tuki_Elementor();

		if ( is_admin() ) {
			require_once TUKI_PLUGIN_DIR . 'admin/class-tuki-admin.php';
			new Tuki_Admin();
		} else {
			new Tuki_Frontend();
		}
	}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @return void
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is not allowed.', 'tukify' ), '0.1.0' );
	}

	/**
	 * Prevent unserializing of the singleton.
	 *
	 * @return void
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing is not allowed.', 'tukify' ), '0.1.0' );
	}
}
