<?php
/**
 * AI provider contract.
 *
 * Every AI backend (Gemini, OpenAI, Anthropic/Claude, xAI/Grok) implements this
 * so the rest of the plugin never cares which provider is selected. All calls
 * are made server-side; an API key never reaches the frontend.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract shared by all Tukify AI providers.
 */
interface Tuki_AI_Provider {

	/**
	 * Whether this provider supports a capability.
	 *
	 * @param string $capability One of 'chat', 'embeddings', 'vision'.
	 * @return bool
	 */
	public function supports( string $capability ): bool;

	/**
	 * Generates a chat completion.
	 *
	 * @param array $messages Ordered messages: [ [ 'role' => 'user|assistant', 'content' => '...' ], ... ].
	 * @param array $opts     Options: 'system' (string) system instruction, 'json_schema'
	 *                        (array|null) to request a structured JSON object response.
	 * @return string Assistant reply text (a JSON string when json_schema was requested).
	 * @throws Exception On a provider or transport error.
	 */
	public function generate_chat( array $messages, array $opts = array() ): string;

	/**
	 * Embeds one or more strings into vectors.
	 *
	 * @param array $texts List of input strings.
	 * @return array One float-vector (array of floats) per input, in order.
	 * @throws Exception If the provider has no embeddings endpoint, or on a call error.
	 */
	public function create_embeddings( array $texts ): array;

	/**
	 * Analyzes an image with a prompt (multimodal).
	 *
	 * @param string     $base64 Raw base64 image data (no data-URL prefix).
	 * @param string     $mime   Image MIME type.
	 * @param string     $prompt Instruction for the model.
	 * @param array|null $schema Optional JSON schema for structured output.
	 * @return string Model output (a JSON string when a schema was given).
	 * @throws Exception If the provider has no vision support, or on a call error.
	 */
	public function generate_vision( string $base64, string $mime, string $prompt, $schema = null ): string;

	/**
	 * Performs a tiny live call to verify credentials and connectivity.
	 *
	 * @return array{success:bool,message:string} Result with a human-readable message.
	 */
	public function test_connection(): array;

	/**
	 * Human-readable provider name (e.g. "OpenAI"), used in messages.
	 *
	 * @return string
	 */
	public function label(): string;
}
