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

		// Keep Tukify's own screens clean by stripping third-party admin notices
		// there — and only there. Priority 0 so this runs before other notice
		// callbacks (which use the default priority 10), letting remove_all_actions
		// clear them before they print. The callback bails on every other screen,
		// so nothing is removed globally. See suppress_foreign_notices().
		add_action( 'admin_notices', array( $this, 'suppress_foreign_notices' ), 0 );
		add_action( 'all_admin_notices', array( $this, 'suppress_foreign_notices' ), 0 );
		add_action( 'wp_ajax_tuki_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_tuki_start_reindex', array( $this, 'ajax_start_reindex' ) );
		add_action( 'wp_ajax_tuki_reindex_status', array( $this, 'ajax_reindex_status' ) );
		add_action( 'wp_ajax_tuki_cancel_reindex', array( $this, 'ajax_cancel_reindex' ) );
		add_action( 'wp_ajax_tuki_test_search', array( $this, 'ajax_test_search' ) );
		add_action( 'wp_ajax_tuki_kb_reindex', array( $this, 'ajax_kb_reindex' ) );
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
			'dashicons-format-chat',
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
	 * On Tukify's own screens only, remove every admin notice other plugins or
	 * core queued for the current hook, then re-render Tukify's own notice(s) so
	 * they are preserved. Bails immediately on every other admin screen, so the
	 * rest of wp-admin is never touched.
	 *
	 * @return void
	 */
	public function suppress_foreign_notices() {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		$hook = current_action(); // 'admin_notices' or 'all_admin_notices'.

		// Clear everyone else's notices queued on this hook.
		remove_all_actions( $hook );

		// Re-render Tukify's own notice(s) directly — remove_all_actions() also
		// dropped ours. Calling it here (rather than re-adding to the now-emptied
		// hook) guarantees ours still shows regardless of hook-iteration state.
		if ( 'admin_notices' === $hook && function_exists( 'tuki_wc_missing_notice' ) ) {
			tuki_wc_missing_notice();
		}
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

			wp_enqueue_script(
				'tuki-admin-controls',
				TUKI_PLUGIN_URL . 'admin/admin-controls.js',
				array(),
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
			)
		);
	}

	/**
	 * AJAX: runs a live "Test connection" against the selected provider.
	 */
	public function ajax_test_connection() {
		$this->verify_admin_request();

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'gemini';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( 'gemini' !== $provider ) {
			wp_send_json_error(
				array(
					'message' => __( 'Only Gemini can be tested in this version. OpenAI and Anthropic support arrives in a later phase.', 'tukify' ),
				)
			);
		}

		$provider_obj = Tuki_Settings::make_provider(
			array(
				'provider' => $provider,
				'api_key'  => $api_key,
			)
		);

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

	/* ---------------------------------------------------------------------
	 * Shared view helpers (used by the dashboard template)
	 * ------------------------------------------------------------------- */

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
		$key = (string) Tuki_Settings::get( 'gemini_api_key' );

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
			'label' => __( 'Gemini connected', 'tukify' ),
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
