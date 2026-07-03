<?php
/**
 * Semantic retrieval orchestrator.
 *
 * Embeds a query and delegates ranking to a pluggable Tuki_Search_Backend
 * (MySQL by default).
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a natural-language query into ranked product IDs.
 */
class Tuki_Search {

	/**
	 * Retrieval backend.
	 *
	 * @var Tuki_Search_Backend
	 */
	private $backend;

	/**
	 * Constructor.
	 *
	 * @param Tuki_Search_Backend|null $backend Optional backend override.
	 */
	public function __construct( Tuki_Search_Backend $backend = null ) {
		$this->backend = $backend ? $backend : new Tuki_Search_MySQL();
	}

	/**
	 * Runs a semantic search.
	 *
	 * @param string     $text          Natural-language query.
	 * @param int|null   $n             Number of results (defaults to the configured retrieval count).
	 * @param array|null $candidate_ids Optional product IDs to restrict ranking to (hybrid filtering).
	 * @return array List of [ 'product_id' => int, 'score' => float ], ranked descending.
	 * @throws Exception If embedding the query fails.
	 */
	public function query( $text, $n = null, $candidate_ids = null ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		if ( null === $n ) {
			$n = (int) Tuki_Settings::get( 'retrieval_count' );
		}

		$n = max( 1, (int) $n );

		$embeddings = new Tuki_Embeddings();
		$vector     = $embeddings->embed_query( $text );

		if ( empty( $vector ) ) {
			return array();
		}

		return $this->backend->search( $vector, $n, $candidate_ids );
	}
}
