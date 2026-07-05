<?php
/**
 * Embedding orchestration + caching.
 *
 * Wraps the configured provider. Bulk indexing uses batched raw calls (the
 * content_hash skip in Tuki_Indexer already prevents redundant work), while
 * query embeddings are cached in transients to avoid paying twice.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces embedding vectors via the active provider.
 */
class Tuki_Embeddings {

	/**
	 * Returns the currently configured embedding model name.
	 *
	 * @return string
	 */
	private function model() {
		return (string) Tuki_Settings::get( 'embedding_model' );
	}

	/**
	 * Embeds a batch of texts in a single provider call (no caching).
	 *
	 * @param array $texts List of strings.
	 * @return array       One float-vector per input, in the same order.
	 * @throws Exception   If no provider is configured or the call fails.
	 */
	public function embed_batch( array $texts ) {
		$texts = array_values( $texts );

		if ( empty( $texts ) ) {
			return array();
		}

		$provider = Tuki_Settings::make_provider();

		if ( ! $provider ) {
			throw new Exception( esc_html__( 'No embedding provider is configured. Anthropic has no embeddings endpoint; select Gemini or OpenAI.', 'tukify' ) );
		}

		return $provider->embed( $texts );
	}

	/**
	 * Embeds a single query string, cached (near-identical repeats reuse the
	 * cached vector, so no API call — and no cost — is incurred).
	 *
	 * @param string $text Query text.
	 * @return array       Float vector (empty on failure).
	 * @throws Exception   If the provider call fails.
	 */
	public function embed_query( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		$model = $this->model();

		$cached = Tuki_Cache::get_embedding( $model, $text );

		if ( null !== $cached ) {
			return $cached;
		}

		$vectors = $this->embed_batch( array( $text ) );
		$vector  = isset( $vectors[0] ) ? $vectors[0] : array();

		if ( ! empty( $vector ) ) {
			Tuki_Cache::set_embedding( $model, $text, $vector );
		}

		return $vector;
	}
}
