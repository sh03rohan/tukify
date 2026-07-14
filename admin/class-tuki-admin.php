<?php
/**
 * Admin menus, assets, and AJAX handlers.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the WP admin surface for Tukify.
 */
class Tuki_Admin {

	/**
	 * Option persisting the last "Test connection" outcome ('1' or '0').
	 */
	const CONNECTION_OPTION = 'tuki_connection_ok';

	/**
	 * Dashboard screen hook suffix.
	 *
	 * @var string
	 */
	private $hook_dashboard = '';

	/**
	 * Settings screen hook suffix.
	 *
	 * @var string
	 */
	private $hook_settings = '';

	/**
	 * Registers admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Tag <body> on Tukify's own screens so the shell stylesheet can paint the
		// WordPress content chrome dark (scoped to this class only).
		add_filter( 'admin_body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_ajax_tuki_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_tuki_start_reindex', array( $this, 'ajax_start_reindex' ) );
		add_action( 'wp_ajax_tuki_reindex_status', array( $this, 'ajax_reindex_status' ) );
		add_action( 'wp_ajax_tuki_cancel_reindex', array( $this, 'ajax_cancel_reindex' ) );
		add_action( 'wp_ajax_tuki_test_search', array( $this, 'ajax_test_search' ) );
		add_action( 'wp_ajax_tuki_kb_reindex', array( $this, 'ajax_kb_reindex' ) );
		add_action( 'wp_ajax_tuki_clear_cache', array( $this, 'ajax_clear_cache' ) );
	}

	/**
	 * Builds the admin-menu icon from the shipped Tukify logo SVG, encoded as a
	 * base64 data-URI so it stays crisp at the ~20px menu size.
	 *
	 * The logo is a full-colour mark (not a monochrome glyph), so it does not
	 * recolour with the admin colour scheme — it renders as the brand logo.
	 * Falls back to a Dashicon if the file cannot be read.
	 *
	 * @return string data:image/svg+xml;base64 URI, or a dashicon slug.
	 */
	private function menu_icon() {
		$path = TUKI_PLUGIN_DIR . 'images/logo.svg';

		if ( ! is_readable( $path ) ) {
			return 'dashicons-cart';
		}

		$svg = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset, not a remote URL.

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a bundled SVG as a data-URI menu icon, not obfuscation.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Registers the Tukify menu: Dashboard (top level) + Settings.
	 */
	public function register_menu() {
		$this->hook_dashboard = add_menu_page(
			__( 'Tukify', 'tukify' ),
			__( 'Tukify', 'tukify' ),
			'manage_options',
			'tukify',
			array( $this, 'render_dashboard_page' ),
			$this->menu_icon(),
			58
		);

		add_submenu_page(
			'tukify',
			__( 'Dashboard', 'tukify' ),
			__( 'Dashboard', 'tukify' ),
			'manage_options',
			'tukify',
			array( $this, 'render_dashboard_page' )
		);

		$this->hook_settings = add_submenu_page(
			'tukify',
			__( 'Settings', 'tukify' ),
			__( 'Settings', 'tukify' ),
			'manage_options',
			'tukify-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'tukify',
			__( 'Back in stock', 'tukify' ),
			__( 'Back in stock', 'tukify' ),
			'manage_options',
			'tukify-stock-notify',
			array( $this, 'render_stock_notify_page' )
		);
	}

	/**
	 * Renders the dashboard screen.
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require TUKI_PLUGIN_DIR . 'admin/dashboard-page.php';
	}

	/**
	 * Renders the settings screen.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require TUKI_PLUGIN_DIR . 'admin/settings-page.php';
	}

	/**
	 * Renders the back-in-stock pending-notifications screen.
	 */
	public function render_stock_notify_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require TUKI_PLUGIN_DIR . 'admin/stock-notify-page.php';
	}

	/**
	 * Whether the current admin screen is one of Tukify's own pages.
	 *
	 * Compares get_current_screen()->id against the exact hook suffixes returned
	 * by add_menu_page()/add_submenu_page() (the same values enqueue_assets()
	 * scopes to). On those pages the screen id equals the hook suffix, so this is
	 * true ONLY on the Tukify Dashboard or Settings screens:
	 *   - Dashboard: "toplevel_page_tukify"
	 *   - Settings:  "tukify_page_tukify-settings"
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( $this->hook_dashboard, $this->hook_settings ), true );
	}

	/**
	 * Adds a unique body class on Tukify's own screens (and nowhere else), so the
	 * shell stylesheet's WordPress-chrome overrides can be scoped to it.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function add_body_class( $classes ) {
		if ( $this->is_plugin_screen() ) {
			$classes .= ' tukify-admin';
		}

		return $classes;
	}

	/**
	 * Enqueues admin CSS/JS only on Tukify's own screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		$is_dashboard = ( $hook === $this->hook_dashboard );
		$is_settings  = ( $hook === $this->hook_settings );

		// Hard scope: never touch any other wp-admin screen.
		if ( ! $is_dashboard && ! $is_settings ) {
			return;
		}

		// Shell: paints the WordPress content chrome dark and removes the top gap
		// on both Tukify screens. Scoped under body.tukify-admin, so loading it
		// here (our screens only) can never affect the rest of wp-admin.
		wp_enqueue_style(
			'tukify-admin-shell',
			TUKI_PLUGIN_URL . 'admin/admin-shell.css',
			array(),
			TUKI_VERSION
		);

		// Shared behaviour (test connection, KB, search test, product reindex).
		// Each init in admin.js guards on element presence, so it is safe on both
		// screens; it carries the localized tukiAdmin strings below.
		wp_enqueue_script(
			'tuki-admin',
			TUKI_PLUGIN_URL . 'admin/admin.js',
			array( 'jquery' ),
			TUKI_VERSION,
			true
		);

		// The dashboard keeps its existing styles; the settings screen uses the
		// redesigned dark theme + tab controller (both fully self-contained and
		// scoped under .tkfy) instead.
		if ( $is_dashboard ) {
			wp_enqueue_style(
				'tuki-admin',
				TUKI_PLUGIN_URL . 'admin/admin.css',
				array(),
				TUKI_VERSION
			);
		}

		if ( $is_settings ) {
			wp_enqueue_style(
				'tuki-admin-dark',
				TUKI_PLUGIN_URL . 'admin/admin-dark.css',
				array(),
				TUKI_VERSION
			);

			wp_enqueue_script(
				'tuki-admin-tabs',
				TUKI_PLUGIN_URL . 'admin/admin-tabs.js',
				array(),
				TUKI_VERSION,
				true
			);

			// WordPress colour picker (Iris) for the Appearance tab bubble/logo colours.
			wp_enqueue_style( 'wp-color-picker' );

			wp_enqueue_script(
				'tuki-admin-controls',
				TUKI_PLUGIN_URL . 'admin/admin-controls.js',
				array( 'jquery', 'wp-color-picker' ),
				TUKI_VERSION,
				true
			);
		}

		wp_localize_script(
			'tuki-admin',
			'tukiAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'tuki_admin' ),
				'testing'          => __( 'Testing connection…', 'tukify' ),
				'connected'        => __( 'Connected', 'tukify' ),
				'failed'           => __( 'Couldn\'t reach Gemini — check the API key', 'tukify' ),
				'reindexConfirm'   => __( 'Reindex all products now? This runs in the background.', 'tukify' ),
				'reindexError'     => __( 'Couldn\'t start reindex — please try again', 'tukify' ),
				/* translators: 1: embedded count, 2: total products. */
				'productsEmbedded' => __( '%1$s / %2$s products embedded', 'tukify' ),
				'searching'        => __( 'Searching…', 'tukify' ),
				'searchEmpty'      => __( 'Enter a search phrase first', 'tukify' ),
				'searchNone'       => __( 'No matching products — index your catalog, then try a broader phrase', 'tukify' ),
				'searchError'      => __( 'Search failed — check your API key and that products are indexed', 'tukify' ),
				'kbReindexing'     => __( 'Reindexing…', 'tukify' ),
				/* translators: %s: number of indexed entries. */
				'kbDone'           => __( 'Indexed %s entries', 'tukify' ),
				/* translators: 1: newly embedded, 2: unchanged (skipped), 3: removed. */
				'kbDetail'         => __( '%1$s embedded · %2$s unchanged · %3$s removed', 'tukify' ),
				'kbError'          => __( 'Reindex failed — check your API key', 'tukify' ),
				'qPlaceholder'     => __( 'Question', 'tukify' ),
				'aPlaceholder'     => __( 'Answer', 'tukify' ),
				'remove'           => __( 'Remove', 'tukify' ),
				'cacheClearing'    => __( 'Clearing…', 'tukify' ),
				'cacheCleared'     => __( 'Cache cleared', 'tukify' ),
				'cacheClearError'  => __( 'Couldn\'t clear the cache — please try again', 'tukify' ),
				'lowContrast'      => __( 'These colours are very similar — the logo may be hard to see.', 'tukify' ),
			)
		);
	}

	/**
	 * AJAX: runs a live "Test connection" against the selected provider.
	 */
	public function ajax_test_connection() {
		$this->verify_admin_request();

		// Nonce + capability are verified in verify_admin_request() above; PHPCS
		// cannot trace that through the helper call.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'gemini';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$registry = Tuki_Settings::providers_registry();

		if ( ! isset( $registry[ $provider ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'tukify' ) ) );
		}

		// Try the just-typed key if one was supplied; otherwise the saved key.
		$provider_obj = Tuki_Settings::instantiate( $provider, $api_key );

		if ( ! $provider_obj ) {
			wp_send_json_error( array( 'message' => __( 'Selected provider is not available.', 'tukify' ) ) );
		}

		$result = $provider_obj->test_connection();

		update_option( self::CONNECTION_OPTION, empty( $result['success'] ) ? '0' : '1' );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * AJAX: starts a full-catalog reindex.
	 */
	public function ajax_start_reindex() {
		$this->verify_admin_request();

		$state = Tuki_Indexer::start_reindex();
		wp_send_json_success( $this->format_state( $state ) );
	}

	/**
	 * AJAX: returns the current reindex progress.
	 */
	public function ajax_reindex_status() {
		$this->verify_admin_request();

		$state = Tuki_Indexer::get_state();
		wp_send_json_success( $this->format_state( $state ) );
	}

	/**
	 * AJAX: cancels an in-progress reindex.
	 */
	public function ajax_cancel_reindex() {
		$this->verify_admin_request();

		$state = Tuki_Indexer::cancel_reindex();
		wp_send_json_success( $this->format_state( $state ) );
	}

	/**
	 * AJAX: runs a diagnostic semantic search and returns matched products.
	 */
	public function ajax_test_search() {
		$this->verify_admin_request();

		// Nonce + capability verified in verify_admin_request() above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

		if ( '' === trim( $query ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a search phrase first', 'tukify' ) ) );
		}

		try {
			$search  = new Tuki_Search();
			$results = $search->query( $query );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		$items = array();

		foreach ( $results as $result ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $result['product_id'] ) : null;

			if ( ! $product ) {
				continue;
			}

			$items[] = array(
				'id'    => (int) $result['product_id'],
				'title' => $product->get_name(),
				'score' => round( (float) $result['score'], 4 ),
				'url'   => get_permalink( $result['product_id'] ),
			);
		}

		wp_send_json_success( array( 'results' => $items ) );
	}

	/**
	 * AJAX: rebuilds the knowledge-base embeddings.
	 */
	public function ajax_kb_reindex() {
		$this->verify_admin_request();

		try {
			$kb     = new Tuki_KB();
			$result = $kb->reindex();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success(
			array(
				'total'    => (int) $result['total'],
				'embedded' => (int) $result['embedded'],
				'skipped'  => (int) $result['skipped'],
				'removed'  => (int) $result['removed'],
			)
		);
	}

	/**
	 * AJAX: clears the response/embedding cache (rotates the cache salt).
	 */
	public function ajax_clear_cache() {
		$this->verify_admin_request();

		Tuki_Cache::clear();

		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'tukify' ) ) );
	}

	/**
	 * Verifies nonce + capability for admin AJAX, or dies with an error.
	 *
	 * @return void
	 */
	private function verify_admin_request() {
		check_ajax_referer( 'tuki_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'tukify' ) ), 403 );
		}
	}

	/**
	 * Shapes a reindex state array for the client.
	 *
	 * @param array $state Raw state from Tuki_Indexer.
	 * @return array
	 */
	private function format_state( $state ) {
		$total     = (int) $state['total'];
		$processed = (int) $state['processed'];
		$percent   = $total > 0 ? min( 100, (int) round( $processed / $total * 100 ) ) : 100;

		return array(
			'status'        => $state['status'],
			'total'         => $total,
			'processed'     => $processed,
			'percent'       => $percent,
			'indexed'       => Tuki_DB::count_embeddings(),
			'totalProducts' => self::total_products(),
			'lastReindex'   => self::last_reindex_human(),
			'lastError'     => isset( $state['last_error'] ) ? $state['last_error'] : '',
		);
	}

	/*
	 * Shared view helpers (used by the dashboard template)
	 */

	/**
	 * Number of published products.
	 *
	 * @return int
	 */
	public static function total_products() {
		if ( ! post_type_exists( 'product' ) ) {
			return 0;
		}

		$counts = wp_count_posts( 'product' );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * State-driven connection status for the header pill.
	 *
	 * @return array{state:string,label:string}
	 */
	public static function connection_state() {
		$registry = Tuki_Settings::providers_registry();
		$provider = Tuki_Settings::chat_provider();
		$label    = isset( $registry[ $provider ] ) ? $registry[ $provider ]['label'] : $provider;
		$key      = Tuki_Settings::provider_key( $provider );

		if ( '' === $key ) {
			return array(
				'state' => 'none',
				'label' => __( 'Not connected', 'tukify' ),
			);
		}

		if ( '0' === get_option( self::CONNECTION_OPTION, '' ) ) {
			return array(
				'state' => 'warn',
				'label' => __( 'Check API key', 'tukify' ),
			);
		}

		return array(
			'state' => 'ok',
			/* translators: %s: provider name. */
			'label' => sprintf( __( '%s connected', 'tukify' ), $label ),
		);
	}

	/**
	 * Human-readable "last reindexed" caption.
	 *
	 * @return string
	 */
	public static function last_reindex_human() {
		$state    = Tuki_Indexer::get_state();
		$finished = isset( $state['finished'] ) ? (int) $state['finished'] : 0;

		if ( $finished > 0 ) {
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			return sprintf( __( 'Last reindexed %s ago', 'tukify' ), human_time_diff( $finished ) );
		}

		return __( 'Not reindexed yet', 'tukify' );
	}
}
