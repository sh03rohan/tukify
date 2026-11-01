<?php
/**
 * Grok (xAI) provider.
 *
 * Chat + vision via xAI's OpenAI-compatible REST API (server-side, Bearer auth).
 * xAI has no public embeddings endpoint, so embeddings fall back to the base's
 * "not supported" error and RAG uses the configured embedding provider instead.
 *
 * The request/response handling is Tuki_Provider_OpenAI's; this class only sets
 * the host, default model, label and capabilities.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Provider files load alphabetically and "grok" sorts before "openai", so load
// the parent first (require_once: the loader's own later include is a no-op).
require_once __DIR__ . '/class-tuki-provider-openai.php';

/**
 * Grok (xAI) implementation of Tuki_AI_Provider.
 */
class Tuki_Provider_Grok extends Tuki_Provider_OpenAI {

	/**
	 * API base URL (OpenAI-compatible).
	 */
	const API_BASE = 'https://api.x.ai/v1/';

	/**
	 * Chat model used when none is configured.
	 */
	const DEFAULT_MODEL = 'grok-4.7';

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array( 'chat', 'vision' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Grok', 'tukify' );
	}
}
