<?php
/**
 * Search backend interface.
 *
 * Abstracts the vector store so a future release can swap MySQL + PHP cosine
 * similarity for an external vector DB (Qdrant/Pinecone) without touching
 * callers.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a retrieval backend.
 */
interface Tuki_Search_Backend {

	/**
	 * Returns the top-N products most similar to a query vector.
	 *
	 * @param array      $query_vector  Query embedding (array of floats).
	 * @param int        $n             Maximum number of results.
	 * @param array|null $candidate_ids Optional product IDs to restrict ranking to (hybrid filtering).
	 * @return array List of [ 'product_id' => int, 'score' => float ], ranked descending.
	 */
	public function search( array $query_vector, $n, $candidate_ids = null );
}
