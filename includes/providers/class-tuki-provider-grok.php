<?php
/**
 * Grok (xAI) provider.
 *
 * Chat + vision via xAI's OpenAI-compatible REST API (server-side, Bearer auth).
 * xAI has no public embeddings endpoint, so embeddings fall back to the base's
 * "not supported" error and RAG uses the configured embedding provider instead.
 *
 * The OpenAI-style request/response shape is duplicated here (rather than
 * extending Tuki_Provider_OpenAI) because the provider files load alphabetically
 * and "grok" loads before "openai".
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grok (xAI) implementation of Tuki_AI_Provider.
 */
class Tuki_Provider_Grok extends Tuki_Provider_Base {

	/**
	 * API base URL (OpenAI-compatible).
	 */
	const API_BASE = 'https://api.x.ai/v1/';

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
			'model'    => $this->chat_model ? $this->chat_model : 'grok-2-latest',
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
	 * Analyzes an image with a prompt (multimodal).
	 *
	 * @param string     $base64 Raw base64 image data.
	 * @param string     $mime   Image MIME type.
	 * @param string     $prompt Instruction for the model.
	 * @param array|null $schema Optional JSON schema for structured output.
	 * @return string Model output (a JSON string when a schema was given).
	 * @throws Exception On a provider or transport error.
	 */
	public function generate_vision( string $base64, string $mime, string $prompt, $schema = null ): string {
		$json    = null !== $schema;
		$content = array(
			array(
				'type' => 'text',
				'text' => $json ? $prompt . "\n\nRespond ONLY with a JSON object." : $prompt,
			),
			array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => 'data:' . $mime . ';base64,' . $base64 ),
			),
		);

		$body = array(
			'model'    => $this->chat_model ? $this->chat_model : 'grok-2-vision-1212',
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);

		if ( $json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = $this->request( self::API_BASE . 'chat/completions', $body, $this->auth_headers() );
		$this->track_chat_usage( $response, $this->estimate( $prompt ) );

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
