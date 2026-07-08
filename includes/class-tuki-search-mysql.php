<?php
/**
 * MySQL + PHP cosine-similarity search backend.
 *
 * Stored embeddings are read from the custom table and ranked in PHP. This is
 * an exhaustive (brute-force) nearest-neighbour scan: without a native vector
 * index, every stored vector must be compared to the query vector. It is a good
 * default for small-to-medium catalogs (roughly up to a few thousand products)
 * and, because it runs inside a REST request rather than on page render, it
 * never blocks the storefront.
 *
 * WHEN TO MOVE TO A VECTOR STORE: past a few thousand products (or if a single
 * search is noticeably slow / memory-heavy), swap in a real ANN vector index by
 * implementing Tuki_Search_Backend against, e.g.:
 *   - MySQL 9.0+ / HeatWave or MariaDB 11.7+ native VECTOR columns + VEC_DISTANCE,
 *   - Postgres + pgvector (HNSW/IVFFlat),
 *   - a dedicated store (Qdrant, Pinecone, Weaviate, Milvus, Elasticsearch kNN).
 * Those use an approximate-nearest-neighbour index, so query time becomes ~log(n)
 * instead of O(n) and the whole table no longer has to be read per query.
 *
 * To bound peak memory in the meantime, the full-catalog scan reads rows in
 * batches (only one batch of vector JSON is held at a time) and keeps just the
 * lightweight (id, score) pairs — never every decoded vector at once.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default retrieval backend.
 */
class Tuki_Search_MySQL implements Tuki_Search_Backend {

	/**
	 * Rows read per batch during a full-catalog scan, to cap peak memory
	 * regardless of catalog size.
	 */
	const SCAN_BATCH = 500;

	/**
	 * Ranks products by cosine similarity to a query vector.
	 *
	 * @param array      $query_vector  The query embedding.
	 * @param int        $n             Number of results to return.
	 * @param array|null $candidate_ids Optional product IDs to restrict ranking to.
	 * @return array List of [ 'product_id' => int, 'score' => float ], ranked descending.
	 */
	public function search( array $query_vector, $n, $candidate_ids = null ) {
		if ( empty( $query_vector ) ) {
			return array();
		}

		// A restricted-but-empty candidate set means nothing matched the filters.
		if ( is_array( $candidate_ids ) && empty( $candidate_ids ) ) {
			return array();
		}

		$query_norm = $this->norm( $query_vector );

		if ( $query_norm <= 0 ) {
			return array();
		}

		// Only compare against vectors built with the CURRENT embedding model.
		// Embeddings from a different provider/model have different dimensions, so
		// scoring across them would be meaningless — filtering by model keeps the
		// index from silently mixing incompatible vectors after a provider change.
		$model = Tuki_Settings::embedding_model();

		// Hybrid path: filters already narrowed the catalog to a bounded set, so a
		// single query over those ids is enough. Full path: stream the whole table
		// in batches so only one batch of vector JSON sits in memory at a time.
		if ( is_array( $candidate_ids ) ) {
			$scored = $this->score_rows( $this->fetch_candidates( $candidate_ids, $model ), $query_vector, $query_norm );
		} else {
			$scored = $this->score_full_catalog( $query_vector, $query_norm, $model );
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $n ) );
	}

	/**
	 * Fetches embedding rows for a bounded candidate set (hybrid filtering).
	 *
	 * @param array  $candidate_ids Product IDs to restrict to.
	 * @param string $model         Active embedding model (rows must match).
	 * @return array Rows of [ product_id, embedding ].
	 */
	private function fetch_candidates( array $candidate_ids, $model ) {
		global $wpdb;

		$ids = array_map( 'absint', $candidate_ids );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = Tuki_DB::embeddings_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( $ids, array( (string) $model ) );

		// The IN() list is a run of %d placeholders built from the id count and
		// bound via prepare() (UnfinishedPrepare is a false positive — the
		// placeholders live inside the interpolated $placeholders string).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_id, embedding FROM {$table} WHERE product_id IN ({$placeholders}) AND model = %s", $params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Scores the whole catalog in batches so peak memory stays bounded no matter
	 * how many products are stored (only SCAN_BATCH vector strings held at once).
	 *
	 * @param array  $query_vector Query embedding.
	 * @param float  $query_norm   Precomputed query norm.
	 * @param string $model        Active embedding model (rows must match).
	 * @return array List of [ product_id, score ].
	 */
	private function score_full_catalog( array $query_vector, $query_norm, $model ) {
		global $wpdb;

		$table  = Tuki_DB::embeddings_table();
		$scored = array();
		$offset = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT product_id, embedding FROM {$table} WHERE model = %s ORDER BY id ASC LIMIT %d OFFSET %d",
					(string) $model,
					self::SCAN_BATCH,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$count = is_array( $rows ) ? count( $rows ) : 0;

			if ( $count > 0 ) {
				// array_merge with the batch's small (id, score) pairs; the bulky
				// vector JSON in $rows is freed at the end of each iteration.
				$scored = array_merge( $scored, $this->score_rows( $rows, $query_vector, $query_norm ) );
			}

			$offset += self::SCAN_BATCH;
		} while ( self::SCAN_BATCH === $count );

		return $scored;
	}

	/**
	 * Decodes and cosine-scores a batch of embedding rows. Each vector is decoded,
	 * scored, and discarded, so only one decoded vector is alive at a time.
	 *
	 * @param array $rows         Rows of [ product_id, embedding ].
	 * @param array $query_vector Query embedding.
	 * @param float $query_norm   Precomputed query norm.
	 * @return array List of [ product_id, score ].
	 */
	private function score_rows( $rows, array $query_vector, $query_norm ) {
		$scored = array();

		foreach ( (array) $rows as $row ) {
			$vector = json_decode( $row['embedding'], true );

			if ( ! is_array( $vector ) || empty( $vector ) ) {
				continue;
			}

			$scored[] = array(
				'product_id' => (int) $row['product_id'],
				'score'      => $this->cosine( $query_vector, $vector, $query_norm ),
			);
		}

		return $scored;
	}

	/**
	 * Euclidean norm (vector magnitude).
	 *
	 * @param array $vector Float vector.
	 * @return float
	 */
	private function norm( array $vector ) {
		$sum = 0.0;

		foreach ( $vector as $value ) {
			$sum += $value * $value;
		}

		return sqrt( $sum );
	}

	/**
	 * Cosine similarity between two vectors.
	 *
	 * @param array $a         First vector.
	 * @param array $b         Second vector.
	 * @param float $a_norm    Precomputed norm of $a.
	 * @return float Similarity in the range [-1, 1].
	 */
	private function cosine( array $a, array $b, $a_norm ) {
		$length = min( count( $a ), count( $b ) );
		$dot    = 0.0;
		$b_sq   = 0.0;

		for ( $i = 0; $i < $length; $i++ ) {
			$dot  += $a[ $i ] * $b[ $i ];
			$b_sq += $b[ $i ] * $b[ $i ];
		}

		$b_norm = sqrt( $b_sq );

		if ( $a_norm <= 0 || $b_norm <= 0 ) {
			return 0.0;
		}

		return $dot / ( $a_norm * $b_norm );
	}
}
