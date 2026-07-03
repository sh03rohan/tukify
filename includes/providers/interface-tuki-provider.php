<?php
/**
 * Provider interface.
 *
 * Every AI provider (Gemini default, OpenAI, Anthropic) implements this so the
 * rest of the plugin never cares which provider is selected.
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
interface Tuki_Provider {

	/**
	 * Embeds one or more strings.
	 *
	 * @param array $texts List of input strings.
	 * @return array       One float-vector (array of floats) per input string.
	 * @throws Exception   On a provider or transport error.
	 */
	public function embed( array $texts ): array;

	/**
	 * Runs a grounded chat completion.
	 *
	 * @param array $messages Ordered messages: [ [ 'role' => 'user|assistant', 'content' => '...' ], ... ].
	 * @param array $context  Retrieval/cart context. Supports a 'system' key for the system instruction.
	 * @return string         Assistant reply text.
	 * @throws Exception      On a provider or transport error.
	 */
	public function chat( array $messages, array $context ): string;

	/**
	 * Performs a tiny live call to verify credentials and connectivity.
	 *
	 * @return array{success:bool,message:string} Result with a human-readable message.
	 */
	public function test_connection(): array;
}
