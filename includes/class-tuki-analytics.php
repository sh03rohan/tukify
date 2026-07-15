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
	 * Registers sale-attribution hooks and the daily demand-log purge. Logging
	 * itself is done via static calls.
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_order_session' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_order_session' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'attribute_sale' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'attribute_sale' ), 20 );

		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge_demand' ) );

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

		Tuki_DB::insert_event( 'sale', 'order:' . absint( $order_id ), null, sanitize_text_field( $sid ) );
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

		$sessions     = self::count_query_sessions();
		$attributed   = self::count_events( 'sale' );
		$chat_to_sale = $sessions > 0 ? round( ( $attributed / $sessions ) * 100, 1 ) : 0.0;

		return array(
			'queries_week'  => $week,
			'queries_trend' => $trend,
			'chat_to_sale'  => $chat_to_sale,
			'attributed'    => $attributed,
			'top_searches'  => self::top_searches( 6 ),
		);
	}
}
