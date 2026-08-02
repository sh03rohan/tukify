<?php
/**
 * Groq (groq.com) provider — the fast-inference platform, NOT xAI's Grok.
 *
 * Chat only, via Groq's OpenAI-compatible REST API (server-side, Bearer auth).
 * Groq has no embeddings or vision endpoint, so those inherit the base's
 * "not supported" errors: RAG uses the configured embedding provider (Gemini /
 * OpenAI) and vision features fall back to a vision-capable provider.
 *
 * The OpenAI-style request/response shape is duplicated here (rather than
 * extending Tuki_Provider_OpenAI) because the provider files load alphabetically
 * and "groq" loads before "openai" — mirrors how the xAI Grok provider does it.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groq (fast inference) implementation of Tuki_AI_Provider.
 */
class Tuki_Provider_Groq extends Tuki_Provider_Base {

	/**
	 * API base URL (OpenAI-compatible).
	 */
	const API_BASE = 'https://api.groq.com/openai/v1/';

	/**
	 * Default chat model when none is configured. Easy to change here or via the
	 * provider registry in Tuki_Settings.
	 */
	const DEFAULT_MODEL = 'llama-3.3-70b-versatile';

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

	/**
	 * Generates a chat completion.
	 *
	 * @param array $messages Conversation messages.
	 * @param array $opts     'system' instruction and optional 'json_schema'.
	 * @return string Reply text (a JSON string when json_schema was requested).
	 * @throws Exception On a provider or transport error.
	 */
	public function generate_chat( array $messages, array $opts = array() ): string {
		$json = ! empty( $opts['json_schema'] );
		$body = array(
			'model'    => $this->chat_model ? $this->chat_model : self::DEFAULT_MODEL,
			'messages' => $this->build_messages( $messages, isset( $opts['system'] ) ? (string) $opts['system'] : '', $json ),
		);

		if ( $json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = $this->request( self::API_BASE . 'chat/completions', $body, $this->auth_headers() );
		$this->track_chat_usage( $response, $this->estimate( wp_json_encode( $body ) ) );

		return $this->extract_text( $response );
	}

	/**
	 * Bearer auth header.
	 *
	 * @return array
	 */
	private function auth_headers() {
		return array( 'Authorization' => 'Bearer ' . $this->api_key );
	}

	/**
	 * Builds the OpenAI-compatible messages array (system first).
	 *
	 * @param array  $messages Conversation messages.
	 * @param string $system   System instruction.
	 * @param bool   $json     Whether JSON output was requested.
	 * @return array
	 */
	private function build_messages( array $messages, $system, $json ) {
		$out = array();

		if ( '' !== trim( $system ) ) {
			if ( $json ) {
				$system .= "\n\nRespond ONLY with a single valid JSON object.";
			}
			$out[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		} elseif ( $json ) {
			$out[] = array(
				'role'    => 'system',
				'content' => 'Respond ONLY with a single valid JSON object.',
			);
		}

		foreach ( $messages as $message ) {
			$role = ( isset( $message['role'] ) && 'assistant' === $message['role'] ) ? 'assistant' : 'user';
			$text = isset( $message['content'] ) ? (string) $message['content'] : '';

			if ( '' !== $text ) {
				$out[] = array(
					'role'    => $role,
					'content' => $text,
				);
			}
		}

		return $out;
	}

	/**
	 * Extracts the assistant text from a chat/completions response.
	 *
	 * @param array $response Decoded response.
	 * @return string
	 */
	private function extract_text( $response ) {
		return isset( $response['choices'][0]['message']['content'] )
			? (string) $response['choices'][0]['message']['content']
			: '';
	}

	/**
	 * Records chat usage from the response's usage block.
	 *
	 * @param array $response        Decoded response.
	 * @param int   $fallback_prompt Estimated prompt tokens if none reported.
	 * @return void
	 */
	private function track_chat_usage( $response, $fallback_prompt ) {
		$usage  = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$prompt = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : (int) $fallback_prompt;
		$out    = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;

		$this->record_usage( 'chat', $prompt, $out );
	}
}
