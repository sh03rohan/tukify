<?php
/**
 * Custom database table management.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and manages Tukify's custom database tables.
 *
 * Two tables are used:
 *  - {prefix}_tuki_embeddings — product vectors for semantic search.
 *  - {prefix}_tuki_events     — lightweight analytics events.
 */
class Tuki_DB {

	/**
	 * Returns the fully-qualified embeddings table name (with prefix).
	 *
	 * @return string
	 */
	public static function embeddings_table() {
		global $wpdb;

		return $wpdb->prefix . 'tuki_embeddings';
	}

	/**
	 * Returns the fully-qualified events table name (with prefix).
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'tuki_events';
	}

	/**
	 * Returns the fully-qualified knowledge-base table name (with prefix).
	 *
	 * @return string
	 */
	public static function kb_table() {
		global $wpdb;

		return $wpdb->prefix . 'tuki_kb';
	}

	/**
	 * Returns the fully-qualified demand-insights table name (with prefix).
	 *
	 * @return string
	 */
	public static function demand_table() {
		global $wpdb;

		return $wpdb->prefix . 'tuki_demand';
	}

	/**
	 * Creates (or updates) all custom tables via dbDelta.
	 *
	 * Safe to call repeatedly: dbDelta only applies the necessary changes.
	 * Called on plugin activation.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$embeddings      = self::embeddings_table();
		$events          = self::events_table();

		// Embeddings table: one row per indexed product.
		$sql_embeddings = "CREATE TABLE {$embeddings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			embedding LONGTEXT NOT NULL,
			model VARCHAR(100) NOT NULL DEFAULT '',
			dims INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY content_hash (content_hash)
		) {$charset_collate};";

		// Events table: lightweight analytics log.
		$sql_events = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(30) NOT NULL DEFAULT '',
			query_text TEXT NULL,
			product_id BIGINT UNSIGNED NULL,
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY product_id (product_id),
			KEY session_id (session_id)
		) {$charset_collate};";

		// Knowledge base table: policy/FAQ content chunks + embeddings.
		$kb     = self::kb_table();
		$sql_kb = "CREATE TABLE {$kb} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type VARCHAR(20) NOT NULL DEFAULT '',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chunk INT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL DEFAULT '',
			content LONGTEXT NOT NULL,
			url TEXT NULL,
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			embedding LONGTEXT NOT NULL,
			model VARCHAR(100) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY source (source_type, source_id)
		) {$charset_collate};";

		// Demand insights: one row per chat query + its outcome. No personal data,
		// no conversation — only the query text, result outcome, and coarse intent.
		$demand     = self::demand_table();
		$sql_demand = "CREATE TABLE {$demand} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query_text VARCHAR(191) NOT NULL DEFAULT '',
			matched_results_count INT UNSIGNED NOT NULL DEFAULT 0,
			was_answered TINYINT(1) NOT NULL DEFAULT 0,
			intent VARCHAR(30) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY was_answered (was_answered),
			KEY matched_results_count (matched_results_count)
		) {$charset_collate};";

		dbDelta( $sql_embeddings );
		dbDelta( $sql_events );
		dbDelta( $sql_kb );
		dbDelta( $sql_demand );

		update_option( 'tuki_db_version', TUKI_VERSION );
	}

	/**
	 * Runs create_tables() when the stored DB version is behind the plugin version,
	 * so tables added in an update (e.g. the KB table) appear on existing installs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tuki_db_version' ) !== TUKI_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Drops all custom tables.
	 *
	 * Used on uninstall. Guarded so it only runs in an uninstall context.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$embeddings = self::embeddings_table();
		$events     = self::events_table();
		$kb         = self::kb_table();

		// Table names are internally built from $wpdb->prefix and cannot be
		// passed as prepared placeholders; they are safe from user input.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$demand = self::demand_table();
		$wpdb->query( "DROP TABLE IF EXISTS {$embeddings}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$events}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$kb}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$demand}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Returns the stored embedding metadata for a product, if any.
	 *
	 * Deliberately omits the (large) embedding column; callers that need the
	 * vector should query it explicitly.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null Row as an associative array, or null if not found.
	 */
	public static function get_embedding_row( $product_id ) {
		global $wpdb;

		$table = self::embeddings_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT product_id, content_hash, model, dims FROM {$table} WHERE product_id = %d", absint( $product_id ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Inserts or updates the embedding row for a product.
	 *
	 * @param int    $product_id     Product ID.
	 * @param string $content_hash   Hash of the indexed text.
	 * @param string $embedding_json JSON-encoded float vector.
	 * @param string $model          Embedding model used.
	 * @param int    $dims           Vector length.
	 * @return void
	 */
	public static function upsert_embedding( $product_id, $content_hash, $embedding_json, $model, $dims ) {
		global $wpdb;

		$table = self::embeddings_table();
		$data  = array(
			'product_id'   => absint( $product_id ),
			'content_hash' => $content_hash,
			'embedding'    => $embedding_json,
			'model'        => $model,
			'dims'         => absint( $dims ),
			'updated_at'   => current_time( 'mysql' ),
		);
		$format = array( '%d', '%s', '%s', '%s', '%d', '%s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE product_id = %d", absint( $product_id ) )
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ), $format, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $format );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Returns decoded embedding vectors for a set of products.
	 *
	 * @param array $product_ids Product IDs.
	 * @return array Map of product_id => float vector.
	 */
	public static function embedding_vectors( array $product_ids ) {
		global $wpdb;

		if ( empty( $product_ids ) ) {
			return array();
		}

		$table        = self::embeddings_table();
		$ids          = array_map( 'absint', $product_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_id, embedding FROM {$table} WHERE product_id IN ({$placeholders})", $ids ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$vector = json_decode( $row['embedding'], true );

			if ( is_array( $vector ) && ! empty( $vector ) ) {
				$out[ (int) $row['product_id'] ] = $vector;
			}
		}

		return $out;
	}

	/**
	 * Deletes the embedding row for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function delete_embedding( $product_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::embeddings_table(), array( 'product_id' => absint( $product_id ) ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Inserts an analytics event row into the events table.
	 *
	 * @param string      $event_type One of query|product_click|add_to_cart|sale.
	 * @param string|null $query_text Query text (or null).
	 * @param int|null    $product_id Related product ID / count (or null).
	 * @param string      $session_id Anonymous session hash.
	 * @return void
	 */
	public static function insert_event( $event_type, $query_text, $product_id, $session_id ) {
		global $wpdb;

		$data   = array(
			'event_type' => substr( (string) $event_type, 0, 30 ),
			'query_text' => ( null === $query_text ) ? null : (string) $query_text,
			'product_id' => ( null === $product_id ) ? null : absint( $product_id ),
			'session_id' => substr( (string) $session_id, 0, 64 ),
			'created_at' => current_time( 'mysql' ),
		);
		$format = array( '%s', '%s', '%d', '%s', '%s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::events_table(), $data, $format );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Inserts or updates a knowledge-base entry (keyed by source_type+source_id+chunk).
	 *
	 * @param array $data Row data (source_type, source_id, chunk, title, content, url, content_hash, embedding, model).
	 * @return void
	 */
	public static function upsert_kb( array $data ) {
		global $wpdb;

		$table = self::kb_table();
		$row   = array(
			'source_type'  => substr( (string) $data['source_type'], 0, 20 ),
			'source_id'    => absint( $data['source_id'] ),
			'chunk'        => absint( $data['chunk'] ),
			'title'        => substr( (string) $data['title'], 0, 255 ),
			'content'      => (string) $data['content'],
			'url'          => (string) $data['url'],
			'content_hash' => (string) $data['content_hash'],
			'embedding'    => (string) $data['embedding'],
			'model'        => (string) $data['model'],
			'updated_at'   => current_time( 'mysql' ),
		);
		$format = array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_type = %s AND source_id = %d AND chunk = %d",
				$row['source_type'],
				$row['source_id'],
				$row['chunk']
			)
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $row, array( 'id' => $existing_id ), $format, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $row, $format );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Returns the stored content hash for a KB chunk, or '' if not present.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @param int    $chunk       Chunk index.
	 * @return string
	 */
	public static function get_kb_hash( $source_type, $source_id, $chunk ) {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content_hash FROM {$table} WHERE source_type = %s AND source_id = %d AND chunk = %d",
				substr( (string) $source_type, 0, 20 ),
				absint( $source_id ),
				absint( $chunk )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $hash ? (string) $hash : '';
	}

	/**
	 * Returns a map of every KB chunk's stored content hash, keyed
	 * "source_type:source_id:chunk". Used to diff against freshly-gathered
	 * chunks so only changed/new chunks are re-embedded.
	 *
	 * @return array<string,string>
	 */
	public static function kb_hash_map() {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT source_type, source_id, chunk, content_hash FROM {$table}", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map = array();

		foreach ( (array) $rows as $row ) {
			$key         = $row['source_type'] . ':' . (int) $row['source_id'] . ':' . (int) $row['chunk'];
			$map[ $key ] = (string) $row['content_hash'];
		}

		return $map;
	}

	/**
	 * Returns the stored chunk => content_hash map for a single source.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @return array<int,string>
	 */
	public static function kb_source_hashes( $source_type, $source_id ) {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT chunk, content_hash FROM {$table} WHERE source_type = %s AND source_id = %d",
				substr( (string) $source_type, 0, 20 ),
				absint( $source_id )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['chunk'] ] = (string) $row['content_hash'];
		}

		return $map;
	}

	/**
	 * Deletes a single KB chunk row (used when pruning stale/removed chunks).
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @param int    $chunk       Chunk index.
	 * @return void
	 */
	public static function delete_kb_chunk( $source_type, $source_id, $chunk ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::kb_table(),
			array(
				'source_type' => substr( (string) $source_type, 0, 20 ),
				'source_id'   => absint( $source_id ),
				'chunk'       => absint( $chunk ),
			),
			array( '%s', '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Deletes every KB row for a given post id, regardless of source type.
	 *
	 * A single post can be indexed under a post-type source ("post"/"page"/
	 * "product") or a curated one ("wc"); this removes all of them when the post
	 * is trashed, unpublished, or excluded.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function delete_kb_post( $post_id ) {
		global $wpdb;

		$table = self::kb_table();

		// Exclude 'qa' rows: their source_id is a Q&A index, not a post id, so a
		// plain source_id match could delete the wrong manual entry.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source_id = %d AND source_type <> 'qa'",
				absint( $post_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes rows for a post stored under any source type other than the one
	 * given (and never touches 'qa' rows). Used when a post's canonical source
	 * type changes, to avoid indexing the same post twice.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $keep_type Source type to preserve.
	 * @return void
	 */
	public static function delete_kb_other_types( $post_id, $keep_type ) {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source_id = %d AND source_type NOT IN ( 'qa', %s )",
				absint( $post_id ),
				substr( (string) $keep_type, 0, 20 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Returns all KB rows (id, title, content, url, embedding) for retrieval.
	 *
	 * @return array
	 */
	public static function kb_rows() {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT title, content, url, embedding FROM {$table}", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes KB rows for a given source (all chunks), or by source type.
	 *
	 * @param string   $source_type Source type.
	 * @param int|null $source_id   Source id, or null to delete the whole type.
	 * @return void
	 */
	public static function delete_kb_source( $source_type, $source_id = null ) {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null === $source_id ) {
			$wpdb->delete( $table, array( 'source_type' => substr( (string) $source_type, 0, 20 ) ), array( '%s' ) );
		} else {
			$wpdb->delete(
				$table,
				array(
					'source_type' => substr( (string) $source_type, 0, 20 ),
					'source_id'   => absint( $source_id ),
				),
				array( '%s', '%d' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Empties the entire knowledge-base table.
	 *
	 * @return void
	 */
	public static function clear_kb() {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Counts knowledge-base entries.
	 *
	 * @return int
	 */
	public static function count_kb() {
		global $wpdb;

		$table = self::kb_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Counts how many products currently have an embedding.
	 *
	 * @return int
	 */
	public static function count_embeddings() {
		global $wpdb;

		$table = self::embeddings_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/* ---------------------------------------------------------------------
	 * Demand insights
	 * ------------------------------------------------------------------- */

	/**
	 * Records one chat query and its outcome (no personal data, no conversation).
	 *
	 * @param string $query_text    The (already anonymized) query text.
	 * @param int    $matched_count Number of product results returned.
	 * @param bool   $was_answered  Whether the assistant answered confidently.
	 * @param string $intent        Coarse intent/category guess.
	 * @return void
	 */
	public static function insert_demand( $query_text, $matched_count, $was_answered, $intent ) {
		global $wpdb;

		$data   = array(
			'query_text'            => substr( (string) $query_text, 0, 191 ),
			'matched_results_count' => max( 0, (int) $matched_count ),
			'was_answered'          => $was_answered ? 1 : 0,
			'intent'                => substr( (string) $intent, 0, 30 ),
			'created_at'            => current_time( 'mysql' ),
		);
		$format = array( '%s', '%d', '%d', '%s', '%s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::demand_table(), $data, $format );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Totals for the demand dashboard within a time window.
	 *
	 * @param string $since MySQL datetime lower bound.
	 * @return array{total:int,unanswered:int,zero_match:int}
	 */
	public static function demand_summary( $since ) {
		global $wpdb;

		$table = self::demand_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
						SUM( CASE WHEN was_answered = 0 THEN 1 ELSE 0 END ) AS unanswered,
						SUM( CASE WHEN matched_results_count = 0 THEN 1 ELSE 0 END ) AS zero_match
				 FROM {$table}
				 WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total'      => isset( $row['total'] ) ? (int) $row['total'] : 0,
			'unanswered' => isset( $row['unanswered'] ) ? (int) $row['unanswered'] : 0,
			'zero_match' => isset( $row['zero_match'] ) ? (int) $row['zero_match'] : 0,
		);
	}

	/**
	 * Top queries that returned zero product matches (unmet demand).
	 *
	 * @param string $since MySQL datetime lower bound.
	 * @param int    $limit Max rows.
	 * @return array List of [ query_text, hits, last_seen, intents ].
	 */
	public static function demand_zero_matches( $since, $limit = 10 ) {
		return self::demand_grouped( 'matched_results_count = 0', $since, $limit );
	}

	/**
	 * Top queries the assistant could not answer confidently.
	 *
	 * @param string $since MySQL datetime lower bound.
	 * @param int    $limit Max rows.
	 * @return array List of [ query_text, hits, last_seen, intents ].
	 */
	public static function demand_unanswered( $since, $limit = 10 ) {
		return self::demand_grouped( 'was_answered = 0', $since, $limit );
	}

	/**
	 * Shared grouped-by-query aggregation for the demand tables.
	 *
	 * @param string $where Extra WHERE condition (trusted, internal literal).
	 * @param string $since MySQL datetime lower bound.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	private static function demand_grouped( $where, $since, $limit ) {
		global $wpdb;

		$table = self::demand_table();
		$limit = max( 1, (int) $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text,
						COUNT(*) AS hits,
						MAX(created_at) AS last_seen,
						SUBSTRING_INDEX( GROUP_CONCAT( DISTINCT intent ORDER BY intent SEPARATOR ',' ), ',', 5 ) AS intents
				 FROM {$table}
				 WHERE {$where} AND query_text <> '' AND created_at >= %s
				 GROUP BY query_text
				 ORDER BY hits DESC, last_seen DESC
				 LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Trending search terms: top queries in the window, split into a recent vs
	 * prior half so the UI can show a direction of travel.
	 *
	 * @param string $since MySQL datetime lower bound (start of window).
	 * @param string $mid   MySQL datetime midpoint separating prior vs recent.
	 * @param int    $limit Max rows.
	 * @return array List of [ query_text, hits, recent, prior, last_seen ].
	 */
	public static function demand_trending( $since, $mid, $limit = 10 ) {
		global $wpdb;

		$table = self::demand_table();
		$limit = max( 1, (int) $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text,
						COUNT(*) AS hits,
						SUM( CASE WHEN created_at >= %s THEN 1 ELSE 0 END ) AS recent,
						SUM( CASE WHEN created_at <  %s THEN 1 ELSE 0 END ) AS prior,
						MAX(created_at) AS last_seen
				 FROM {$table}
				 WHERE query_text <> '' AND created_at >= %s
				 GROUP BY query_text
				 ORDER BY recent DESC, hits DESC
				 LIMIT %d",
				$mid,
				$mid,
				$since,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total demand rows stored.
	 *
	 * @return int
	 */
	public static function count_demand() {
		global $wpdb;

		$table = self::demand_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Deletes demand rows older than the given number of days.
	 *
	 * @param int $days Retention window in days.
	 * @return int Rows removed.
	 */
	public static function purge_demand( $days ) {
		$days = (int) $days;

		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;

		$table  = self::demand_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DateTime.CurrentTimeTimestamp.Requested
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DateTime.CurrentTimeTimestamp.Requested

		return (int) $deleted;
	}
}
