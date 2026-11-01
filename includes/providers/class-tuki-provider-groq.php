<?php
/**
 * Groq (groq.com) provider — the fast-inference platform, NOT xAI's Grok.
 *
 * Chat only, via Groq's OpenAI-compatible REST API (server-side, Bearer auth).
 * Groq has no embeddings or vision endpoint, so those inherit the base's
 * "not supported" errors: RAG uses the configured embedding provider (Gemini /
 * OpenAI) and vision features fall back to a vision-capable provider.
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

// Provider files load alphabetically and "groq" sorts before "openai", so load
// the parent first (require_once: the loader's own later include is a no-op).
require_once __DIR__ . '/class-tuki-provider-openai.php';

/**
 * Groq (fast inference) implementation of Tuki_AI_Provider.
 */
class Tuki_Provider_Groq extends Tuki_Provider_OpenAI {

	/**
	 * API base URL (OpenAI-compatible).
	 */
	const API_BASE = 'https://api.groq.com/openai/v1/';

	/**
	 * Default chat model when none is configured. Easy to change here or via the
	 * provider registry in Tuki_Settings.
	 */
	const DEFAULT_MODEL = 'openai/gpt-oss-120b';

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array( 'chat' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Groq (fast inference)', 'tukify' );
	}
}
