<?php
/**
 * Analytics: event logging + dashboard aggregates.
 *
 * Logs shopper interactions (query, product_click, add_to_cart) and attributes
 * completed WooCommerce orders back to Tukify via a session cookie. All writes
 * go to the {prefix}_tuki_events table and are gated by the analytics setting.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records events and computes dashboard metrics.
 */
class Tuki_Analytics {

	/**
	 * Cookie that carries the anonymous session id.
	 */
	const COOKIE = 'tuki_sid';

	/**
	 * Order meta key linking an order to a Tukify session.
	 */
	const ORDER_META = '_tuki_sid';

	/**
	 * Minimum cosine score for a retrieved product to count as a real hit.
	 * Queries whose best hit falls below this are flagged zero-result.
	 */
	const ZERO_RESULT_THRESHOLD = 0.35;

	/**
	 * Cron hook that auto-purges demand logs beyond the retention window.
	 */
	const PURGE_HOOK = 'tuki_purge_demand';

	/**
	 * Option flag recording that historical sale rows have had their order value
	 * backfilled.
	 */
	const BACKFILL_OPTION = 'tuki_order_value_backfilled';

	/**
	 * Registers sale-attribution hooks and the daily demand-log purge. Logging
	 * itself is done via static calls.
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_order_session' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_order_session' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'attribute_sale' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'attribute_sale' ), 20 );

		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge_demand' ) );

		// One-time: fill in order values for sales attributed before the revenue
		// column existed, so an upgrading store sees its real history rather than
		// a dashboard reporting zero revenue against orders it can see in WooCommerce.
		add_action( 'init', array( __CLASS__, 'backfill_order_values' ) );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Whether analytics logging is enabled in settings.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Tuki_Settings::get( 'analytics_enabled' );
	}

	/*
	 * Session
	 */

	/**
	 * Returns the caller's session id, creating (and cookie-setting) one if needed.
	 *
	 * @return string
	 */
	public static function session_id() {
		$existing = self::read_session_id();

		if ( '' !== $existing ) {
			return $existing;
		}

		$sid = wp_generate_password( 32, false );

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$sid,
				array(
					'expires'  => time() + MONTH_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::COOKIE ] = $sid;

		return $sid;
	}

	/**
	 * Reads an existing session id from cookie or WC session, without creating one.
	 *
	 * @return string
	 */
	private static function read_session_id() {
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$stored = WC()->session->get( self::COOKIE );

			if ( $stored ) {
				return sanitize_text_field( $stored );
			}
		}

		return '';
	}

	/*
	 * Event logging
	 */

	/**
	 * Logs a search/chat query, flagging it zero-result when nothing scored high enough.
	 *
	 * @param string $text    Query text.
	 * @param array  $results Ranked results with 'score' keys.
	 * @return void
	 */
	public static function record_query( $text, array $results ) {
		if ( ! self::enabled() ) {
			return;
		}

		$text = trim( (string) $text );

		if ( '' === $text ) {
			return;
		}

		$threshold = (float) Tuki_Settings::get( 'relevance_threshold' );

		if ( $threshold <= 0 ) {
			$threshold = self::ZERO_RESULT_THRESHOLD;
		}

		$hits = 0;

		foreach ( $results as $result ) {
			if ( isset( $result['score'] ) && (float) $result['score'] >= $threshold ) {
				++$hits;
			}
		}

		Tuki_DB::insert_event( 'query', $text, $hits, self::session_id() );
	}

	/*
	 * Demand insights
	 */

	/**
	 * Logs a chat query and its outcome for demand analysis.
	 *
	 * Anonymized by design: only the (PII-scrubbed) query, whether products were
	 * returned, whether the answer was confident, and a coarse intent are stored.
	 * No session id, no user id, no conversation. Gated by its own setting so it
	 * can be disabled independently of the other analytics.
	 *
	 * @param string $query  The user's chat message.
	 * @param array  $result The result array returned by Tuki_Chat::respond().
	 * @return void
	 */
	public static function record_demand( $query, array $result ) {
		if ( ! (bool) Tuki_Settings::get( 'demand_logging' ) ) {
			return;
		}

		$query = self::scrub_pii( trim( (string) $query ) );

		if ( '' === $query ) {
			return;
		}

		// How many products were shown (chat cards or a comparison set).
		$products = ( isset( $result['products'] ) && is_array( $result['products'] ) ) ? $result['products'] : array();
		$count    = count( $products );

		if ( isset( $result['comparison']['products'] ) && is_array( $result['comparison']['products'] ) ) {
			$count = max( $count, count( $result['comparison']['products'] ) );
		}

		$intent = isset( $result['intent'] ) ? (string) $result['intent'] : 'browse';

		// Prefer the explicit confidence flag from the KB path; otherwise derive
		// it from the intent/outcome (rescue -> 'zero_result', clarify, etc.).
		if ( array_key_exists( 'answered', $result ) ) {
			$answered = (bool) $result['answered'];
		} else {
			switch ( $intent ) {
				case 'zero_result':
				case 'clarify':
					$answered = false;
					break;
				case 'order_status':
					$answered = true;
					break;
				default:
					$answered = $count > 0;
			}
		}

		Tuki_DB::insert_demand( $query, $count, $answered, $intent );
	}

	/**
	 * Removes obvious personal data (e-mail addresses) from a query before it is
	 * stored, so demand logs never retain contact details a shopper typed.
	 *
	 * @param string $text Query text.
	 * @return string
	 */
	private static function scrub_pii( $text ) {
		return (string) preg_replace(
			'/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
			'[email]',
			(string) $text
		);
	}

	/**
	 * Cron callback: deletes demand + event rows older than the configured
	 * retention, keeping both analytics tables bounded.
	 *
	 * @return void
	 */
	public static function purge_demand() {
		$days = (int) Tuki_Settings::get( 'demand_retention_days' );

		if ( $days > 0 ) {
			Tuki_DB::purge_demand( $days );
			Tuki_DB::purge_events( $days );
		}
	}

	/**
	 * Logs a simple, session-stamped event (e.g. clarification_shown).
	 *
	 * @param string $type Event type.
	 * @return void
	 */
	public static function record_event( $type ) {
		if ( ! self::enabled() ) {
			return;
		}

		Tuki_DB::insert_event( $type, null, null, self::session_id() );
	}

	/**
	 * Logs a product-card click.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function record_click( $product_id ) {
		if ( ! self::enabled() ) {
			return;
		}

		Tuki_DB::insert_event( 'product_click', null, absint( $product_id ), self::session_id() );
	}

	/**
	 * Logs an add-to-cart action and remembers the session for sale attribution.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function record_add_to_cart( $product_id ) {
		if ( ! self::enabled() ) {
			return;
		}

		$sid = self::session_id();

		Tuki_DB::insert_event( 'add_to_cart', null, absint( $product_id ), $sid );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::COOKIE, $sid );
		}
	}

	/*
	 * Sale attribution
	 */

	/**
	 * Stamps the Tukify session id onto an order at checkout.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function capture_order_session( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$sid = self::read_session_id();

		if ( '' !== $sid ) {
			$order->update_meta_data( self::ORDER_META, $sid );
			$order->save();
		}
	}

	/**
	 * Attributes a completed/processing order to Tukify if its session interacted with us.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function attribute_sale( $order_id ) {
		if ( ! self::enabled() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$sid = $order->get_meta( self::ORDER_META );

		if ( empty( $sid ) || self::sale_recorded( $order_id ) ) {
			return;
		}

		// Snapshot the order total on the event row. Reading it back later from
		// WooCommerce would mean one wc_get_order() per sale on every dashboard
		// render, and would silently drop revenue for orders the store deletes.
		$total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : null;

		Tuki_DB::insert_event( 'sale', 'order:' . absint( $order_id ), null, sanitize_text_field( $sid ), $total );
	}

	/**
	 * Logs that a chat turn could not be answered confidently.
	 *
	 * The demand table already records this, but anonymously — with no session it
	 * cannot say what share of *conversations* failed. This event carries the
	 * session id, which is what a resolution rate needs.
	 *
	 * @return void
	 */
	public static function record_unresolved() {
		self::record_event( 'chat_unresolved' );
	}

	/**
	 * Whether a sale event already exists for an order (idempotency).
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private static function sale_recorded( $order_id ) {
		global $wpdb;

		$table = Tuki_DB::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE event_type = 'sale' AND query_text = %s LIMIT 1",
				'order:' . absint( $order_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null !== $found;
	}

	/*
	 * Dashboard aggregates
	 */

	/**
	 * Returns the top query strings by frequency, with a zero-result flag.
	 *
	 * @param int $limit Max rows.
	 * @return array List of [ 'query' => string, 'count' => int, 'zero_result' => bool ].
	 */
	public static function top_searches( $limit = 6 ) {
		global $wpdb;

		$table = Tuki_DB::events_table();
		$limit = max( 1, (int) $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text, COUNT(*) AS cnt, MAX(product_id) AS best
				FROM {$table}
				WHERE event_type = 'query' AND query_text <> ''
				GROUP BY query_text
				ORDER BY cnt DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'query'       => (string) $row['query_text'],
				'count'       => (int) $row['cnt'],
				'zero_result' => 0 === (int) $row['best'],
			);
		}

		return $out;
	}

	/**
	 * Counts query events between two timestamps.
	 *
	 * @param int $from_ts Inclusive start (local timestamp).
	 * @param int $to_ts   Exclusive end (local timestamp).
	 * @return int
	 */
	private static function count_queries_between( $from_ts, $to_ts ) {
		global $wpdb;

		$table = Tuki_DB::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = 'query' AND created_at >= %s AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', $from_ts ),
				gmdate( 'Y-m-d H:i:s', $to_ts )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Counts events of a type (all-time).
	 *
	 * @param string $type Event type.
	 * @return int
	 */
	/**
	 * Total number of chat/search interactions logged (used to gauge whether the
	 * store has actively used the plugin, e.g. for the review request).
	 *
	 * @return int
	 */
	public static function chat_count() {
		return self::count_events( 'query' );
	}

	private static function count_events( $type ) {
		global $wpdb;

		$table = Tuki_DB::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $type )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Counts distinct sessions that ran at least one query.
	 *
	 * @return int
	 */
	private static function count_query_sessions() {
		global $wpdb;

		$table = Tuki_DB::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT session_id) FROM {$table} WHERE event_type = 'query' AND session_id <> ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Assembles every metric the dashboard needs.
	 *
	 * @return array
	 */
	public static function dashboard_data() {
		// Site-local timestamp: compared against created_at values stored in site time.
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$now       = current_time( 'timestamp' );
		$week      = self::count_queries_between( $now - WEEK_IN_SECONDS, $now + DAY_IN_SECONDS );
		$prev_week = self::count_queries_between( $now - ( 2 * WEEK_IN_SECONDS ), $now - WEEK_IN_SECONDS );

		if ( $prev_week > 0 ) {
			$trend = (int) round( ( ( $week - $prev_week ) / $prev_week ) * 100 );
		} else {
			$trend = $week > 0 ? 100 : 0;
		}

		$sessions   = self::count_query_sessions();
		$attributed = self::count_events( 'sale' );

		return array(
			'queries_week'  => $week,
			'queries_trend' => $trend,
			'sessions'      => $sessions,
			'attributed'    => $attributed,
			'top_searches'  => self::top_searches( 6 ),
		);
	}
	/*
	 * Conversion KPIs
	 */

	/**
	 * Every session-level conversion metric for a window, in ONE query.
	 *
	 * Each KPI here (chat-to-sale, cart recovery, resolution rate, AOV) is a ratio
	 * over the same population: distinct sessions in the range. Computing them
	 * separately would range-scan the events table once per metric. Instead the
	 * inner query collapses the window to one row per session — flags for which
	 * event types it saw, when the nudge and the order happened, and its revenue —
	 * and the outer query aggregates those rows into a single result row.
	 *
	 * Constraining event_type as well as the date range matters: without it MySQL
	 * prefers the session_id index and scans the WHOLE table to get its grouping
	 * for free, ignoring the window entirely. With it, the existing type_created
	 * index serves a real range scan — measured at 6k examined rows against 40k,
	 * and 25ms against 83ms. The five types listed are exactly the ones the
	 * aggregates below read; a session with none of them cannot move any KPI.
	 *
	 * The result is always one row regardless of traffic volume. Nothing
	 * per-session is ever returned to PHP, so a busy store costs the same as a
	 * quiet one.
	 *
	 * @param string $since MySQL datetime lower bound (site time, inclusive).
	 * @param string $until MySQL datetime upper bound (site time, exclusive).
	 * `sessions` counts sessions with at least one conversion-relevant event, not
	 * every session that ever touched the widget — a session that only clicked a
	 * product card cannot affect any ratio here.
	 *
	 * @return array{
	 *     sessions:int, query_sessions:int, iv_sessions:int, iv_recovered:int,
	 *     chat_sale_sessions:int, chat_orders:int, chat_revenue:float,
	 *     unresolved_sessions:int, cart_sessions:int
	 * }
	 */
	public static function conversion_rollup( $since, $until ) {
		global $wpdb;

		$table = Tuki_DB::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS sessions,
					COALESCE( SUM( has_query ), 0 ) AS query_sessions,
					COALESCE( SUM( has_cart ), 0 ) AS cart_sessions,
					COALESCE( SUM( has_iv ), 0 ) AS iv_sessions,
					COALESCE( SUM( CASE WHEN iv_at IS NOT NULL AND sale_at IS NOT NULL AND sale_at >= iv_at THEN 1 ELSE 0 END ), 0 ) AS iv_recovered,
					COALESCE( SUM( CASE WHEN has_query = 1 AND has_sale = 1 THEN 1 ELSE 0 END ), 0 ) AS chat_sale_sessions,
					COALESCE( SUM( CASE WHEN has_query = 1 THEN sale_orders ELSE 0 END ), 0 ) AS chat_orders,
					COALESCE( SUM( CASE WHEN has_query = 1 THEN sale_revenue ELSE 0 END ), 0 ) AS chat_revenue,
					COALESCE( SUM( CASE WHEN has_query = 1 AND has_unresolved = 1 THEN 1 ELSE 0 END ), 0 ) AS unresolved_sessions
				FROM (
					SELECT
						session_id,
						MAX( event_type = 'query' ) AS has_query,
						MAX( event_type = 'add_to_cart' ) AS has_cart,
						MAX( event_type = 'sale' ) AS has_sale,
						MAX( event_type = 'intervention_shown' ) AS has_iv,
						MAX( event_type = 'chat_unresolved' ) AS has_unresolved,
						MIN( CASE WHEN event_type = 'intervention_shown' THEN created_at END ) AS iv_at,
						MAX( CASE WHEN event_type = 'sale' THEN created_at END ) AS sale_at,
						SUM( event_type = 'sale' ) AS sale_orders,
						SUM( CASE WHEN event_type = 'sale' THEN COALESCE( order_value, 0 ) ELSE 0 END ) AS sale_revenue
					FROM {$table}
					WHERE event_type IN ( 'query', 'add_to_cart', 'sale', 'intervention_shown', 'chat_unresolved' )
						AND created_at >= %s AND created_at < %s
						AND session_id <> ''
					GROUP BY session_id
				) s",
				$since,
				$until
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array(
			'sessions'            => 0,
			'query_sessions'      => 0,
			'cart_sessions'       => 0,
			'iv_sessions'         => 0,
			'iv_recovered'        => 0,
			'chat_sale_sessions'  => 0,
			'chat_orders'         => 0,
			'chat_revenue'        => 0.0,
			'unresolved_sessions' => 0,
		);

		if ( ! is_array( $row ) ) {
			return $out;
		}

		foreach ( $out as $key => $default ) {
			if ( ! isset( $row[ $key ] ) ) {
				continue;
			}

			$out[ $key ] = is_float( $default ) ? (float) $row[ $key ] : (int) $row[ $key ];
		}

		return $out;
	}

	/**
	 * Daily attributed orders + revenue, for the dashboard trend line.
	 *
	 * Grouped in SQL rather than in PHP, and served by the type_created index, so
	 * only one row per day crosses into PHP.
	 *
	 * @param string $since MySQL datetime lower bound (site time, inclusive).
	 * @param string $until MySQL datetime upper bound (site time, exclusive).
	 * @return array<string,array{orders:int,revenue:float}> Keyed by Y-m-d.
	 */
	public static function sale_series( $since, $until ) {
		global $wpdb;

		$table = Tuki_DB::events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE( created_at ) AS d, COUNT(*) AS orders, COALESCE( SUM( order_value ), 0 ) AS revenue
				FROM {$table}
				WHERE event_type = 'sale' AND created_at >= %s AND created_at < %s
				GROUP BY DATE( created_at )
				ORDER BY d ASC",
				$since,
				$until
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['d'] ] = array(
				'orders'  => (int) $row['orders'],
				'revenue' => (float) $row['revenue'],
			);
		}

		return $out;
	}

	/**
	 * Store-wide paid orders and revenue in a window, for the AOV comparison.
	 *
	 * Aggregated in SQL against whichever order store WooCommerce is using: the
	 * HPOS orders table, or posts + postmeta on a legacy install. Both shapes are
	 * a single indexed aggregate — never a wc_get_orders() loop, which would pull
	 * every order object into memory just to sum one column.
	 *
	 * Order totals only: no customer, no items, nothing identifying.
	 *
	 * @param string $since_gmt GMT datetime lower bound (inclusive).
	 * @param string $until_gmt GMT datetime upper bound (exclusive).
	 * @return array{orders:int,revenue:float}
	 */
	public static function store_wide_orders( $since_gmt, $until_gmt ) {
		global $wpdb;

		$out = array(
			'orders'  => 0,
			'revenue' => 0.0,
		);

		if ( ! function_exists( 'WC' ) ) {
			return $out;
		}

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			// Bail rather than error if HPOS is reported on but the table is absent.
			if ( $orders_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ) ) {
				return $out;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS orders, COALESCE( SUM( total_amount ), 0 ) AS revenue
					FROM {$orders_table}
					WHERE type = 'shop_order'
						AND status IN ( 'wc-processing', 'wc-completed' )
						AND date_created_gmt >= %s AND date_created_gmt < %s",
					$since_gmt,
					$until_gmt
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS orders, COALESCE( SUM( pm.meta_value + 0 ), 0 ) AS revenue
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
					WHERE p.post_type = 'shop_order'
						AND p.post_status IN ( 'wc-processing', 'wc-completed' )
						AND p.post_date_gmt >= %s AND p.post_date_gmt < %s",
					$since_gmt,
					$until_gmt
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( is_array( $row ) ) {
			$out['orders']  = (int) $row['orders'];
			$out['revenue'] = (float) $row['revenue'];
		}

		return $out;
	}

	/**
	 * The conversion KPI set the dashboard renders, for one date range.
	 *
	 * Ratios are returned already computed (and already guarded against division
	 * by zero) alongside the raw counts the UI needs for its sub-labels, so the
	 * view does no arithmetic of its own.
	 *
	 * @param int $days Range length in days; 0 means all time.
	 * @return array
	 */
	public static function conversion_kpis( $days ) {
		$days = max( 0, (int) $days );

		// Site-local now: event rows store created_at via current_time( 'mysql' ).
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$now = current_time( 'timestamp' );

		// Include everything up to the end of today so orders placed minutes ago
		// still land inside the window.
		$until_ts = $now + DAY_IN_SECONDS;

		if ( 0 === $days ) {
			// "All time" is still bounded in practice: the daily purge trims events
			// past the retention window, so this never scans beyond that.
			$since    = '1970-01-01 00:00:00';
			$since_ts = 0;
		} else {
			$since_ts = $now - ( $days * DAY_IN_SECONDS );
			$since    = gmdate( 'Y-m-d H:i:s', $since_ts );
		}

		$until = gmdate( 'Y-m-d H:i:s', $until_ts );

		$roll = self::conversion_rollup( $since, $until );

		// WooCommerce stores order dates in GMT while our events are in site time,
		// so the store-wide window is converted rather than reusing the bounds.
		$offset    = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$since_gmt = ( 0 === $days ) ? '1970-01-01 00:00:00' : gmdate( 'Y-m-d H:i:s', $since_ts - $offset );
		$until_gmt = gmdate( 'Y-m-d H:i:s', $until_ts - $offset );

		$store = self::store_wide_orders( $since_gmt, $until_gmt );

		$query_sessions = (int) $roll['query_sessions'];
		$iv_sessions    = (int) $roll['iv_sessions'];
		$chat_orders    = (int) $roll['chat_orders'];

		// Chat-to-sale compares distinct sessions on BOTH sides. The previous
		// version divided every sale row by query-sessions, so a shopper who was
		// only nudged (never queried) still counted as a sale — which could push
		// the rate past 100%.
		$chat_to_sale = $query_sessions > 0
			? round( ( $roll['chat_sale_sessions'] / $query_sessions ) * 100, 1 )
			: 0.0;

		$cart_recovery = $iv_sessions > 0
			? round( ( $roll['iv_recovered'] / $iv_sessions ) * 100, 1 )
			: 0.0;

		$resolution = $query_sessions > 0
			? round( ( ( $query_sessions - $roll['unresolved_sessions'] ) / $query_sessions ) * 100, 1 )
			: 0.0;

		$chat_aov  = $chat_orders > 0 ? ( $roll['chat_revenue'] / $chat_orders ) : 0.0;
		$store_aov = $store['orders'] > 0 ? ( $store['revenue'] / $store['orders'] ) : 0.0;

		// Only meaningful once both sides have orders to compare.
		$aov_delta = ( $chat_aov > 0 && $store_aov > 0 )
			? round( ( ( $chat_aov - $store_aov ) / $store_aov ) * 100 )
			: null;

		return array(
			'range_days'          => $days,
			'since'               => $since,
			'until'               => $until,
			'sessions'            => (int) $roll['sessions'],
			'query_sessions'      => $query_sessions,
			'cart_sessions'       => (int) $roll['cart_sessions'],
			'chat_sale_sessions'  => (int) $roll['chat_sale_sessions'],
			'chat_orders'         => $chat_orders,
			'chat_revenue'        => (float) $roll['chat_revenue'],
			'unresolved_sessions' => (int) $roll['unresolved_sessions'],
			'iv_sessions'         => $iv_sessions,
			'iv_recovered'        => (int) $roll['iv_recovered'],
			'chat_to_sale'        => $chat_to_sale,
			'cart_recovery'       => $cart_recovery,
			'resolution'          => $resolution,
			'chat_aov'            => $chat_aov,
			'store_aov'           => $store_aov,
			'store_orders'        => (int) $store['orders'],
			'aov_delta'           => $aov_delta,
			'series'              => self::sale_series( $since, $until ),
		);
	}
	/**
	 * Backfills order_value on sale rows recorded before the column existed.
	 *
	 * Sale rows already carry their order id in query_text ("order:123"), so the
	 * totals can be recovered with a single JOIN against whichever order store
	 * WooCommerce uses — no per-order object loading. Runs once, and only touches
	 * rows where the value is still unknown, so re-running is harmless.
	 *
	 * Orders deleted since attribution simply stay NULL: their revenue is genuinely
	 * unknowable, and NULL keeps that distinct from a zero-value order.
	 *
	 * @return void
	 */
	public static function backfill_order_values() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		// Needs WooCommerce loaded to know which order store to read.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		global $wpdb;

		$table = Tuki_DB::events_table();

		// The column arrives with the schema upgrade; if that has not run yet,
		// leave the flag unset so this retries on the next request.
		$has_column = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'order_value' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! $has_column ) {
			return;
		}

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			if ( $orders_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ) ) {
				$wpdb->query(
					"UPDATE {$table} e
					INNER JOIN {$orders_table} o ON o.id = CAST( SUBSTRING( e.query_text, 7 ) AS UNSIGNED )
					SET e.order_value = o.total_amount
					WHERE e.event_type = 'sale'
						AND e.order_value IS NULL
						AND e.query_text LIKE 'order:%'"
				);
			}
		} else {
			$wpdb->query(
				"UPDATE {$table} e
				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = CAST( SUBSTRING( e.query_text, 7 ) AS UNSIGNED )
					AND pm.meta_key = '_order_total'
				SET e.order_value = pm.meta_value + 0
				WHERE e.event_type = 'sale'
					AND e.order_value IS NULL
					AND e.query_text LIKE 'order:%'"
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( self::BACKFILL_OPTION, 1, false );
	}
}
