<?php
/**
 * Shared base for AI providers.
 *
 * Holds the credentials + model names and implements everything common to every
 * provider: the server-side wp_remote_post transport with timeout and retry/
 * backoff, mapping any provider's error/rate-limit response to a consistent
 * friendly message, capability checks, and usage tracking. Each concrete
 * provider only builds its request body/headers and parses its response shape.
 *
 * Lives in includes/ (not includes/providers/) so it loads before the provider
 * subclasses in the glob autoload order and can be extended by them.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common provider behaviour (transport, errors, usage, capabilities).
 */
abstract class Tuki_Provider_Base implements Tuki_AI_Provider {

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 30;

	/**
	 * Max attempts before giving up (retries on transport error / 429 / 5xx).
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Chat model name.
	 *
	 * @var string
	 */
	protected $chat_model;

	/**
	 * Embedding model name.
	 *
	 * @var string
	 */
	protected $embedding_model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key         Provider API key.
	 * @param string $chat_model      Chat model name.
	 * @param string $embedding_model Embedding model name.
	 */
	public function __construct( $api_key, $chat_model = '', $embedding_model = '' ) {
		$this->api_key         = (string) $api_key;
		$this->chat_model      = (string) $chat_model;
		$this->embedding_model = (string) $embedding_model;
	}

	/**
	 * The capabilities this provider supports ('chat', 'embeddings', 'vision').
	 *
	 * @return array
	 */
	abstract protected function capabilities(): array;

	/**
	 * Whether this provider supports a capability.
	 *
	 * @param string $capability 'chat' | 'embeddings' | 'vision'.
	 * @return bool
	 */
	public function supports( string $capability ): bool {
		return in_array( $capability, $this->capabilities(), true );
	}

	// These two defaults always throw for providers that lack the capability; the
	// interface's return types are kept for compatibility, so the "no return"
	// notice is expected here.
	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Default: providers without an embeddings endpoint inherit this clean error.
	 *
	 * @param array $texts Ignored.
	 * @return array
	 * @throws Exception Always — this provider has no embeddings endpoint.
	 */
	public function create_embeddings( array $texts ): array {
		unset( $texts );
		/* translators: %s: provider name. */
		$message = sprintf( __( '%s does not provide embeddings. Choose Gemini or OpenAI as your embedding provider.', 'tukify' ), $this->label() );
		throw new Exception( esc_html( $message ) );
	}

	/**
	 * Default: providers without vision inherit this clean error.
	 *
	 * @param string     $base64 Ignored.
	 * @param string     $mime   Ignored.
	 * @param string     $prompt Ignored.
	 * @param array|null $schema Ignored.
	 * @return string
	 * @throws Exception Always — this provider has no vision support.
	 */
	public function generate_vision( string $base64, string $mime, string $prompt, $schema = null ): string {
		unset( $base64, $mime, $prompt, $schema );
		/* translators: %s: provider name. */
		$message = sprintf( __( '%s does not support image input.', 'tukify' ), $this->label() );
		throw new Exception( esc_html( $message ) );
	}

	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Generic connectivity test: embeds a short string when embeddings are
	 * supported, otherwise sends a one-word chat. Providers may override.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function test_connection(): array {
		try {
			if ( $this->supports( 'embeddings' ) ) {
				$vectors = $this->create_embeddings( array( 'Tukify connection test.' ) );

				if ( ! empty( $vectors[0] ) ) {
					return array(
						'success' => true,
						/* translators: 1: provider name, 2: number of dimensions. */
						'message' => sprintf( __( 'Connected to %1$s. Embedding returned %2$d dimensions.', 'tukify' ), $this->label(), count( $vectors[0] ) ),
					);
				}
			} else {
				$reply = $this->generate_chat(
					array(
						array(
							'role'    => 'user',
							'content' => 'Reply with the single word: ok',
						),
					),
					array( 'system' => 'You are a connectivity test. Reply with one word.' )
				);

				if ( '' !== trim( $reply ) ) {
					return array(
						'success' => true,
						/* translators: %s: provider name. */
						'message' => sprintf( __( 'Connected to %s.', 'tukify' ), $this->label() ),
					);
				}
			}

			/* translators: %s: provider name. */
			$message = sprintf( __( '%s responded but returned no usable data.', 'tukify' ), $this->label() );

			return array(
				'success' => false,
				'message' => $message,
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/*
	 * Shared transport + helpers
	 */

	/**
	 * Ensures an API key is present before making a request.
	 *
	 * @return void
	 * @throws Exception If no key is configured.
	 */
	protected function require_key() {
		if ( '' === $this->api_key ) {
			/* translators: %s: provider name. */
			throw new Exception( esc_html( sprintf( __( 'No %s API key is configured.', 'tukify' ), $this->label() ) ) );
		}
	}

	/**
	 * Performs a server-side POST with retry + exponential backoff on 429/5xx.
	 *
	 * @param string $url     Full endpoint URL.
	 * @param array  $body    Request body (JSON-encoded here).
	 * @param array  $headers Extra request headers (Content-Type is added).
	 * @return array          Decoded JSON response.
	 * @throws Exception       On a transport error or non-200 response.
	 */
	protected function request( $url, array $body, array $headers ) {
		$this->require_key();

		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		);

		$attempt = 0;

		while ( true ) {
			++$attempt;

			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt < self::MAX_ATTEMPTS ) {
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
					/* translators: %s: provider name. */
					throw new Exception( esc_html( sprintf( __( 'Could not parse the %s response.', 'tukify' ), $this->label() ) ) );
				}

				return is_array( $data ) ? $data : array();
			}

			if ( ( 429 === $code || $code >= 500 ) && $attempt < self::MAX_ATTEMPTS ) {
				sleep( $attempt );
				continue;
			}

			$decoded = json_decode( $raw, true );

			throw new Exception( esc_html( $this->friendly_error( $code, $this->extract_error( $decoded ) ) ) );
		}
	}

	/**
	 * Pulls a human error message out of a decoded error response, across the
	 * slightly different shapes the providers use.
	 *
	 * @param mixed $decoded Decoded response body.
	 * @return string
	 */
	protected function extract_error( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		if ( isset( $decoded['error']['message'] ) ) {
			return (string) $decoded['error']['message'];
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			return (string) $decoded['error'];
		}

		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			return (string) $decoded['message'];
		}

		return '';
	}

	/**
	 * Maps an HTTP status code to a friendly, translatable message, so the chat
	 * UI shows a consistent error regardless of which provider failed.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Raw provider message, if any.
	 * @return string
	 */
	protected function friendly_error( $code, $message ) {
		$label = $this->label();

		switch ( true ) {
			case 401 === $code:
			case 403 === $code:
				/* translators: 1: provider name, 2: HTTP status code. */
				return sprintf( __( '%1$s authentication failed (%2$d). Check that your API key is valid.', 'tukify' ), $label, $code );

			case 429 === $code:
				/* translators: %s: provider name. */
				return sprintf( __( '%s rate limit reached (429). Please wait a moment and try again.', 'tukify' ), $label );

			case 400 === $code:
				/* translators: 1: provider name, 2: raw error message. */
				return trim( sprintf( __( '%1$s rejected the request (400). %2$s', 'tukify' ), $label, $message ) );

			case $code >= 500:
				/* translators: 1: provider name, 2: HTTP status code. */
				return sprintf( __( '%1$s service error (%2$d). Please try again shortly.', 'tukify' ), $label, $code );

			default:
				/* translators: 1: provider name, 2: HTTP status code, 3: raw error message. */
				return trim( sprintf( __( '%1$s error (%2$d). %3$s', 'tukify' ), $label, $code, $message ) );
		}
	}

	/**
	 * Records token usage against the daily counters (no-op if usage tracking
	 * isn't loaded).
	 *
	 * @param string $kind       'chat' or 'embedding'.
	 * @param int    $prompt     Prompt/input tokens.
	 * @param int    $completion Completion/output tokens.
	 * @return void
	 */
	protected function record_usage( $kind, $prompt, $completion ) {
		if ( class_exists( 'Tuki_Usage' ) ) {
			Tuki_Usage::record( $kind, 1, (int) $prompt, (int) $completion );
		}
	}

	/**
	 * Rough token estimate for a string or list of strings (≈4 chars/token),
	 * used when a provider response omits exact token counts.
	 *
	 * @param string|array $text Text or list of texts.
	 * @return int
	 */
	protected function estimate( $text ) {
		return class_exists( 'Tuki_Usage' ) ? Tuki_Usage::estimate_tokens( $text ) : 0;
	}
}
