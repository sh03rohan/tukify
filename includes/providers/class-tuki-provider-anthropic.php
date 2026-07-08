<?php
/**
 * Anthropic (Claude) provider.
 *
 * Chat + vision via the Anthropic Messages API (server-side, x-api-key auth).
 * Anthropic has no embeddings endpoint, so embeddings fall back to the base's
 * "not supported" error and RAG uses the configured embedding provider instead.
 * Structured output is requested via a system instruction; Tuki_Chat's parser
 * extracts the JSON object from the reply.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic implementation of Tuki_AI_Provider.
 */
class Tuki_Provider_Anthropic extends Tuki_Provider_Base {

	/**
	 * Messages endpoint.
	 */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * API version header value.
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * Default max output tokens.
	 */
	const MAX_TOKENS = 1024;

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
		return __( 'Claude', 'tukify' );
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
		$json   = ! empty( $opts['json_schema'] );
		$system = isset( $opts['system'] ) ? (string) $opts['system'] : '';

		if ( $json ) {
			$system = trim( $system . "\n\nRespond ONLY with a single valid JSON object and nothing else." );
		}

		$body = array(
			'model'      => $this->chat_model ? $this->chat_model : 'claude-3-5-haiku-latest',
			'max_tokens' => self::MAX_TOKENS,
			'messages'   => $this->build_messages( $messages ),
		);

		if ( '' !== trim( $system ) ) {
			$body['system'] = $system;
		}

		$response = $this->request( self::API_URL, $body, $this->auth_headers() );
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
		$json = null !== $schema;

		$content = array(
			array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => $mime,
					'data'       => $base64,
				),
			),
			array(
				'type' => 'text',
				'text' => $json ? $prompt . "\n\nRespond ONLY with a JSON object." : $prompt,
			),
		);

		$body = array(
			'model'      => $this->chat_model ? $this->chat_model : 'claude-3-5-haiku-latest',
			'max_tokens' => self::MAX_TOKENS,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);

		$response = $this->request( self::API_URL, $body, $this->auth_headers() );
		$this->track_chat_usage( $response, $this->estimate( $prompt ) );

		return $this->extract_text( $response );
	}

	/**
	 * Auth + version headers for Anthropic.
	 *
	 * @return array
	 */
	private function auth_headers() {
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * Builds the Anthropic messages array (system is a top-level param, not a
	 * message). Roles are user/assistant; the first message must be from the user.
	 *
	 * @param array $messages Conversation messages.
	 * @return array
	 */
	private function build_messages( array $messages ) {
		$out = array();

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

		// Anthropic requires the first message to be from the user.
		if ( ! empty( $out ) && 'user' !== $out[0]['role'] ) {
			array_unshift(
				$out,
				array(
					'role'    => 'user',
					'content' => '(context)',
				)
			);
		}

		return $out;
	}

	/**
	 * Extracts the reply text from a Messages API response.
	 *
	 * @param array $response Decoded response.
	 * @return string
	 */
	private function extract_text( $response ) {
		if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
			foreach ( $response['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					return (string) $block['text'];
				}
			}
		}

		return '';
	}

	/**
	 * Records chat usage from Anthropic's usage block (input_tokens/output_tokens).
	 *
	 * @param array $response        Decoded response.
	 * @param int   $fallback_prompt Estimated prompt tokens if none reported.
	 * @return void
	 */
	private function track_chat_usage( $response, $fallback_prompt ) {
		$usage  = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$prompt = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : (int) $fallback_prompt;
		$out    = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;

		$this->record_usage( 'chat', $prompt, $out );
	}
}
