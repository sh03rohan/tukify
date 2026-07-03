<?php
/**
 * MySQL + PHP cosine-similarity search backend.
 *
 * Loads stored vectors and ranks them in PHP. Fine to ~1,000 products; swap in
 * an external vector DB behind Tuki_Search_Backend beyond that.
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
	 * {@inheritDoc}
	 */
	public function search( array $query_vector, $n, $candidate_ids = null ) {
		global $wpdb;

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

		$table = Tuki_DB::embeddings_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_array( $candidate_ids ) ) {
			$ids          = array_map( 'absint', $candidate_ids );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( "SELECT product_id, embedding FROM {$table} WHERE product_id IN ({$placeholders})", $ids ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT product_id, embedding FROM {$table}", ARRAY_A );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$scored = array();

		foreach ( $rows as $row ) {
			$vector = json_decode( $row['embedding'], true );

			if ( ! is_array( $vector ) || empty( $vector ) ) {
				continue;
			}

			$scored[] = array(
				'product_id' => (int) $row['product_id'],
				'score'      => $this->cosine( $query_vector, $vector, $query_norm ),
			);
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
