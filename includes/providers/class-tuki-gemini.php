<?php
/**
 * Google Gemini provider (default).
 *
 * All calls are server-side. The API key is never exposed to the frontend.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini implementation of the Tuki_Provider contract.
 */
class Tuki_Gemini implements Tuki_Provider {

	/**
	 * Base URL for the Generative Language API.
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Embedding model name.
	 *
	 * @var string
	 */
	private $embedding_model;

	/**
	 * Chat model name.
	 *
	 * @var string
	 */
	private $chat_model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key         Gemini API key.
	 * @param string $embedding_model Embedding model (defaults to gemini-embedding-001).
	 * @param string $chat_model      Chat model (defaults to gemini-2.5-flash).
	 */
	public function __construct( $api_key, $embedding_model = 'gemini-embedding-001', $chat_model = 'gemini-2.5-flash' ) {
		$this->api_key         = (string) $api_key;
		$this->embedding_model = $embedding_model ? (string) $embedding_model : 'gemini-embedding-001';
		$this->chat_model      = $chat_model ? (string) $chat_model : 'gemini-2.5-flash';
	}

	/**
	 * Embeds a batch of texts into vectors (implements Tuki_Provider::embed).
	 *
	 * @param array $texts List of strings to embed.
	 * @return array One float-vector per input, in order.
	 * @throws Exception On a provider or transport error.
	 */
	public function embed( array $texts ): array {
		$texts = array_values( $texts );

		if ( empty( $texts ) ) {
			return array();
		}

		$model    = $this->normalize_model( $this->embedding_model );
		$requests = array();

		foreach ( $texts as $text ) {
			$requests[] = array(
				'model'   => $model,
				'content' => array( 'parts' => array( array( 'text' => (string) $text ) ) ),
			);
		}

		$endpoint = self::API_BASE . $model . ':batchEmbedContents';
		$response = $this->request( $endpoint, array( 'requests' => $requests ) );

		// The embeddings endpoint doesn't report token usage, so estimate from the
		// input text. One batch call = one request regardless of item count.
		$this->track_usage( 'embedding', $response, $this->estimate( $texts ), 0 );

		$vectors = array();

		if ( isset( $response['embeddings'] ) && is_array( $response['embeddings'] ) ) {
			foreach ( $response['embeddings'] as $embedding ) {
				$vectors[] = isset( $embedding['values'] ) ? array_map( 'floatval', $embedding['values'] ) : array();
			}
		}

		return $vectors;
	}

	/**
	 * Generates a chat completion (implements Tuki_Provider::chat).
	 *
	 * @param array $messages Conversation messages ([ 'role' => ..., 'content' => ... ]).
	 * @param array $context  Optional 'system' instruction and 'response_schema'.
	 * @return string The model's text reply.
	 * @throws Exception On a provider or transport error.
	 */
	public function chat( array $messages, array $context = array() ): string {
		$model    = $this->normalize_model( $this->chat_model );
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

		if ( ! empty( $context['system'] ) ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => (string) $context['system'] ) ),
			);
		}

		// Optional structured-output mode: force a JSON response matching a schema.
		if ( ! empty( $context['response_schema'] ) ) {
			$body['generationConfig'] = array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => $context['response_schema'],
			);
		}

		$endpoint = self::API_BASE . $model . ':generateContent';
		$response = $this->request( $endpoint, $body );

		$this->track_usage( 'chat', $response, $this->estimate( wp_json_encode( $body ) ), 0 );

		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return (string) $response['candidates'][0]['content']['parts'][0]['text'];
		}

		return '';
	}

	/**
	 * Analyzes an image with the (multimodal) chat model.
	 *
	 * @param string     $base64 Raw base64 image data (no data-URL prefix).
	 * @param string     $mime   Image MIME type.
	 * @param string     $prompt Instruction for the model.
	 * @param array|null $schema Optional response JSON schema for structured output.
	 * @return string Model output (JSON string if a schema was given).
	 * @throws Exception On a provider or transport error.
	 */
	public function vision( $base64, $mime, $prompt, $schema = null ) {
		$model = $this->normalize_model( $this->chat_model );

		$body = array(
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => (string) $prompt ),
						array(
							'inlineData' => array(
								'mimeType' => (string) $mime,
								'data'     => (string) $base64,
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

		$endpoint = self::API_BASE . $model . ':generateContent';
		$response = $this->request( $endpoint, $body );

		// Image bytes dominate the payload, so the fallback estimate uses the prompt
		// text only; usageMetadata (when present) supersedes it anyway.
		$this->track_usage( 'chat', $response, $this->estimate( (string) $prompt ), 0 );

		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return (string) $response['candidates'][0]['content']['parts'][0]['text'];
		}

		return '';
	}

	/**
	 * Records usage for one API call. Prefers Gemini's exact usageMetadata token
	 * counts; falls back to the caller's estimate when the response omits them.
	 *
	 * @param string $kind             'embedding' or 'chat'.
	 * @param array  $response         Decoded API response.
	 * @param int    $fallback_prompt  Estimated prompt tokens if none reported.
	 * @param int    $fallback_output  Estimated output tokens if none reported.
	 * @return void
	 */
	private function track_usage( $kind, $response, $fallback_prompt, $fallback_output ) {
		if ( ! class_exists( 'Tuki_Usage' ) ) {
			return;
		}

		$meta       = isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ? $response['usageMetadata'] : array();
		$prompt     = isset( $meta['promptTokenCount'] ) ? (int) $meta['promptTokenCount'] : (int) $fallback_prompt;
		$completion = isset( $meta['candidatesTokenCount'] ) ? (int) $meta['candidatesTokenCount'] : (int) $fallback_output;

		Tuki_Usage::record( $kind, 1, $prompt, $completion );
	}

	/**
	 * Rough token estimate for a string or list of strings (≈4 chars/token).
	 *
	 * @param string|array $text Text or list of texts.
	 * @return int
	 */
	private function estimate( $text ) {
		return class_exists( 'Tuki_Usage' ) ? Tuki_Usage::estimate_tokens( $text ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): array {
		try {
			$vectors = $this->embed( array( 'Tukify connection test.' ) );

			if ( ! empty( $vectors ) && ! empty( $vectors[0] ) ) {
				return array(
					'success' => true,
					/* translators: %d: number of embedding dimensions returned. */
					'message' => sprintf( __( 'Connected to Gemini. Embedding returned %d dimensions.', 'tukify' ), count( $vectors[0] ) ),
				);
			}

			return array(
				'success' => false,
				'message' => __( 'Gemini responded but returned no embedding data.', 'tukify' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
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

	/**
	 * Performs a POST request with retry + exponential backoff on 429/5xx.
	 *
	 * @param string $url  Full endpoint URL.
	 * @param array  $body Request body (encoded as JSON).
	 * @return array       Decoded response.
	 * @throws Exception   On a transport error or non-200 response.
	 */
	private function request( $url, array $body ) {
		if ( empty( $this->api_key ) ) {
			throw new Exception( esc_html__( 'No Gemini API key is configured.', 'tukify' ) );
		}

		$args = array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $this->api_key,
			),
			'body'    => wp_json_encode( $body ),
		);

		$max_attempts = 3;
		$attempt      = 0;

		while ( true ) {
			++$attempt;

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt < $max_attempts ) {
					sleep( $attempt );
					continue;
				}

				throw new Exception( esc_html( $response->get_error_message() ) );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {
				$data = json_decode( $raw, true );

				if ( JSON_ERROR_NONE !== json_last_error() ) {
					throw new Exception( esc_html__( 'Could not parse the Gemini response.', 'tukify' ) );
				}

				return is_array( $data ) ? $data : array();
			}

			if ( ( 429 === $code || $code >= 500 ) && $attempt < $max_attempts ) {
				sleep( $attempt );
				continue;
			}

			$decoded = json_decode( $raw, true );
			$api_msg = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';

			throw new Exception( esc_html( $this->friendly_error( $code, $api_msg ) ) );
		}
	}

	/**
	 * Maps an HTTP status code to a friendly, translatable message.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Raw provider message, if any.
	 * @return string
	 */
	private function friendly_error( $code, $message ) {
		switch ( true ) {
			case 400 === $code:
				/* translators: %s: raw error message from Gemini. */
				return sprintf( __( 'Gemini rejected the request (400). %s', 'tukify' ), $message );

			case 401 === $code:
			case 403 === $code:
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Gemini authentication failed (%d). Check that your API key is valid.', 'tukify' ), $code );

			case 429 === $code:
				return __( 'Gemini rate limit reached (429). Please wait a moment and try again.', 'tukify' );

			case $code >= 500:
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Gemini service error (%d). Please try again shortly.', 'tukify' ), $code );

			default:
				/* translators: 1: HTTP status code, 2: raw error message. */
				return sprintf( __( 'Gemini error (%1$d). %2$s', 'tukify' ), $code, $message );
		}
	}
}
