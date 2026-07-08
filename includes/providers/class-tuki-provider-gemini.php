<?php
/**
 * Google Gemini provider.
 *
 * Chat + embeddings + vision. Behaviour matches the original single-provider
 * Gemini implementation (Generative Language API, server-side, key never
 * exposed). Shared transport/error/usage handling comes from Tuki_Provider_Base.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini implementation of Tuki_AI_Provider.
 */
class Tuki_Provider_Gemini extends Tuki_Provider_Base {

	/**
	 * Base URL for the Generative Language API.
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/';

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array( 'chat', 'embeddings', 'vision' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Gemini', 'tukify' );
	}

	/**
	 * Embeds one or more strings into vectors.
	 *
	 * @param array $texts Input strings.
	 * @return array One float-vector per input, in order.
	 * @throws Exception On a provider or transport error.
	 */
	public function create_embeddings( array $texts ): array {
		$texts = array_values( $texts );

		if ( empty( $texts ) ) {
			return array();
		}

		$model    = $this->normalize_model( $this->embedding_model ? $this->embedding_model : 'gemini-embedding-001' );
		$requests = array();

		foreach ( $texts as $text ) {
			$requests[] = array(
				'model'   => $model,
				'content' => array( 'parts' => array( array( 'text' => (string) $text ) ) ),
			);
		}

		$response = $this->request(
			self::API_BASE . $model . ':batchEmbedContents',
			array( 'requests' => $requests ),
			$this->auth_headers()
		);

		// The embeddings endpoint doesn't report tokens; estimate from the input.
		$this->record_usage( 'embedding', $this->estimate( $texts ), 0 );

		$vectors = array();

		if ( isset( $response['embeddings'] ) && is_array( $response['embeddings'] ) ) {
			foreach ( $response['embeddings'] as $embedding ) {
				$vectors[] = isset( $embedding['values'] ) ? array_map( 'floatval', $embedding['values'] ) : array();
			}
		}

		return $vectors;
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
		$model    = $this->normalize_model( $this->chat_model ? $this->chat_model : 'gemini-2.5-flash' );
		$contents = array();

		foreach ( $messages as $message ) {
			$role = ( isset( $message['role'] ) && 'assistant' === $message['role'] ) ? 'model' : 'user';
			$text = isset( $message['content'] ) ? (string) $message['content'] : '';

			if ( '' === $text ) {
				continue;
			}

			$contents[] = array(
				'role'  => $role,
				'parts' => array( array( 'text' => $text ) ),
			);
		}

		$body = array( 'contents' => $contents );

		if ( ! empty( $opts['system'] ) ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $opts['system'] ) ) );
		}

		if ( ! empty( $opts['json_schema'] ) ) {
			$body['generationConfig'] = array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => $opts['json_schema'],
			);
		}

		$response = $this->request( self::API_BASE . $model . ':generateContent', $body, $this->auth_headers() );

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
		$model = $this->normalize_model( $this->chat_model ? $this->chat_model : 'gemini-2.5-flash' );

		$body = array(
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $prompt ),
						array(
							'inlineData' => array(
								'mimeType' => $mime,
								'data'     => $base64,
							),
						),
					),
				),
			),
		);

		if ( $schema ) {
			$body['generationConfig'] = array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => $schema,
			);
		}

		$response = $this->request( self::API_BASE . $model . ':generateContent', $body, $this->auth_headers() );

		// Image bytes dominate the payload; estimate from the prompt only.
		$this->track_chat_usage( $response, $this->estimate( $prompt ) );

		return $this->extract_text( $response );
	}

	/**
	 * Auth header for Gemini.
	 *
	 * @return array
	 */
	private function auth_headers() {
		return array( 'x-goog-api-key' => $this->api_key );
	}

	/**
	 * Extracts the reply text from a generateContent response.
	 *
	 * @param array $response Decoded response.
	 * @return string
	 */
	private function extract_text( $response ) {
		return isset( $response['candidates'][0]['content']['parts'][0]['text'] )
			? (string) $response['candidates'][0]['content']['parts'][0]['text']
			: '';
	}

	/**
	 * Records chat usage using Gemini's usageMetadata when present.
	 *
	 * @param array $response         Decoded response.
	 * @param int   $fallback_prompt  Estimated prompt tokens if none reported.
	 * @return void
	 */
	private function track_chat_usage( $response, $fallback_prompt ) {
		$meta   = isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ? $response['usageMetadata'] : array();
		$prompt = isset( $meta['promptTokenCount'] ) ? (int) $meta['promptTokenCount'] : (int) $fallback_prompt;
		$out    = isset( $meta['candidatesTokenCount'] ) ? (int) $meta['candidatesTokenCount'] : 0;

		$this->record_usage( 'chat', $prompt, $out );
	}

	/**
	 * Ensures a model name carries the required "models/" prefix.
	 *
	 * @param string $model Raw model name.
	 * @return string
	 */
	private function normalize_model( $model ) {
		$model = trim( (string) $model );

		if ( 0 !== strpos( $model, 'models/' ) ) {
			$model = 'models/' . $model;
		}

		return $model;
	}
}
