<?php
/**
 * WhatsApp conversation sessions.
 *
 * Phone number = identity, but the raw number is never stored. Each shopper is
 * keyed by a salted HMAC of their wa_id; replies always use the wa_id supplied
 * by the inbound webhook, so the raw number is never needed from the database
 * (and never lands in a log or an export).
 *
 * The stored context is deliberately bounded — only the last few turns — so a
 * long-running conversation can't grow without limit.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, writes, and purges WhatsApp conversation sessions.
 */
class Tuki_WA_Sessions {

	/**
	 * Cron hook that purges stale sessions.
	 */
	const PURGE_HOOK = 'tuki_purge_wa_sessions';

	/**
	 * Registers the purge schedule.
	 */
	public function __construct() {
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Salted, irreversible hash of a phone number, used as the session key.
	 *
	 * Uses wp_salt() so the hash is site-specific and cannot be reversed with a
	 * rainbow table of phone numbers.
	 *
	 * @param string $phone Raw wa_id / phone number.
	 * @return string 64-char hex hash, or '' when no number was given.
	 */
	public static function hash_phone( $phone ) {
		$phone = preg_replace( '/[^0-9]/', '', (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		return hash_hmac( 'sha256', $phone, wp_salt( 'tuki_wa' ) );
	}

	/**
	 * Loads (or lazily creates) the session for a phone number.
	 *
	 * @param string $phone        Raw wa_id from the webhook.
	 * @param string $display_name Optional WhatsApp profile name.
	 * @return array{id:int,phone_hash:string,session:array} Session row + decoded context.
	 */
	public static function get( $phone, $display_name = '' ) {
		global $wpdb;

		$hash = self::hash_phone( $phone );

		if ( '' === $hash ) {
			return array(
				'id'         => 0,
				'phone_hash' => '',
				'session'    => Tuki_Channel::normalize_session( array() ),
			);
		}

		$table = Tuki_DB::wa_sessions_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, conversation_context FROM {$table} WHERE phone_hash = %s", $hash ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			$now = current_time( 'mysql' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'phone_hash'           => $hash,
					'display_name'         => sanitize_text_field( (string) $display_name ),
					'conversation_context' => wp_json_encode( Tuki_Channel::normalize_session( array() ) ),
					'created_at'           => $now,
					'updated_at'           => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);

			return array(
				'id'         => (int) $wpdb->insert_id,
				'phone_hash' => $hash,
				'session'    => Tuki_Channel::normalize_session( array() ),
			);
		}

		$context = json_decode( (string) $row['conversation_context'], true );

		return array(
			'id'         => (int) $row['id'],
			'phone_hash' => $hash,
			'session'    => Tuki_Channel::normalize_session( is_array( $context ) ? $context : array() ),
		);
	}

	/**
	 * Persists an updated (already bounded) session.
	 *
	 * @param string $phone        Raw wa_id.
	 * @param array  $session      Session to store.
	 * @param string $display_name Optional profile name.
	 * @return void
	 */
	public static function save( $phone, array $session, $display_name = '' ) {
		global $wpdb;

		$hash = self::hash_phone( $phone );

		if ( '' === $hash ) {
			return;
		}

		$table = Tuki_DB::wa_sessions_table();
		$data  = array(
			'conversation_context' => wp_json_encode( Tuki_Channel::normalize_session( $session ) ),
			'updated_at'           => current_time( 'mysql' ),
		);
		$format = array( '%s', '%s' );

		if ( '' !== $display_name ) {
			$data['display_name'] = sanitize_text_field( $display_name );
			$format[]             = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, array( 'phone_hash' => $hash ), $format, array( '%s' ) );
	}

	/**
	 * Deletes sessions untouched for longer than the configured retention window.
	 *
	 * @return int Rows removed.
	 */
	public static function purge() {
		global $wpdb;

		$days = (int) Tuki_Settings::get( 'wa_session_days' );

		if ( $days < 1 ) {
			$days = 30;
		}

		$table  = Tuki_DB::wa_sessions_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $cutoff )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $removed;
	}

	/**
	 * Recent conversations for the admin log viewer.
	 *
	 * @param int $limit Rows to return.
	 * @return array List of [ id, phone_hash, display_name, updated_at, turns ].
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;

		$table = Tuki_DB::wa_sessions_table();
		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, phone_hash, display_name, conversation_context, updated_at
				 FROM {$table} ORDER BY updated_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$context = json_decode( (string) $row['conversation_context'], true );
			$history = ( is_array( $context ) && ! empty( $context['history'] ) ) ? $context['history'] : array();

			$out[] = array
			(
				'id'           => (int) $row['id'],
				// Only a short fingerprint is ever shown — never a phone number.
				'ref'          => substr( (string) $row['phone_hash'], 0, 8 ),
				'display_name' => (string) $row['display_name'],
				'updated_at'   => (string) $row['updated_at'],
				'history'      => is_array( $history ) ? $history : array(),
			);
		}

		return $out;
	}
}
