<?php
/**
 * WhatsApp channel — Meta WhatsApp Business Cloud API.
 *
 * A CHANNEL ADAPTER, not a second brain: inbound messages are normalised and
 * handed to Tuki_Channel::handle_message(), and the reply object that comes back
 * is rendered into WhatsApp messages by Tuki_WA_Format. All product search, RAG
 * and provider logic stays in Tuki_Chat.
 *
 * Security + platform rules honoured here:
 *  - Every inbound POST must carry a valid X-Hub-Signature-256 (HMAC-SHA256 of
 *    the raw body with the app secret). Unsigned/invalid requests are rejected
 *    before any parsing.
 *  - The webhook acknowledges with 200 immediately and does the slow work
 *    (provider call + send) in an async Action Scheduler job, so Meta never
 *    times out and retries.
 *  - Meta retries deliveries, so every message id is de-duplicated.
 *  - 24-hour customer-service window: this class only ever REPLIES to an inbound
 *    message. There is no proactive/outbound campaign path.
 *  - The access token is server-side only and is never logged.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inbound webhook + outbound Cloud API client.
 */
class Tuki_WhatsApp {

	/**
	 * Graph API version.
	 */
	const API_VERSION = 'v21.0';

	/**
	 * Graph API base.
	 */
	const API_BASE = 'https://graph.facebook.com/';

	/**
	 * Async processing hook + Action Scheduler group.
	 */
	const HOOK_PROCESS = 'tuki_wa_process';
	const GROUP        = 'tukify-whatsapp';

	/**
	 * Outbound request timeout (seconds).
	 */
	const TIMEOUT = 20;

	/**
	 * Inbound rate limit: max messages per phone per window.
	 */
	const RATE_MAX    = 20;
	const RATE_WINDOW = 300;

	/**
	 * Registers the async processor and the tokenized add-to-cart link handler.
	 *
	 * Nothing is hooked while the feature is locked, so no WhatsApp code path can
	 * run at all — not the async processor, not the link handler.
	 */
	public function __construct() {
		if ( ! self::feature_enabled() ) {
			return;
		}

		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process' ), 10, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_add_to_cart_link' ) );
	}

	/*
	 * Configuration
	 */

	/**
	 * The build-time feature flag. While false the whole channel is inert and the
	 * settings UI shows a "Coming soon" teaser.
	 *
	 * @return bool
	 */
	public static function feature_enabled() {
		return defined( 'TUKI_WHATSAPP_ENABLED' ) && TUKI_WHATSAPP_ENABLED;
	}

	/**
	 * Whether the WhatsApp channel is switched on and fully configured.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		// The feature flag wins over any stored setting.
		if ( ! self::feature_enabled() ) {
			return false;
		}

		if ( ! Tuki_Settings::get( 'wa_enabled' ) ) {
			return false;
		}

		return '' !== self::token() && '' !== self::phone_number_id();
	}

	/**
	 * Access token (server-side only — never echoed or logged).
	 *
	 * @return string
	 */
	public static function token() {
		return (string) Tuki_Settings::get( 'wa_access_token' );
	}

	/**
	 * Sender phone number ID.
	 *
	 * @return string
	 */
	public static function phone_number_id() {
		return (string) Tuki_Settings::get( 'wa_phone_number_id' );
	}

	/**
	 * Meta app secret, used to verify inbound signatures.
	 *
	 * @return string
	 */
	public static function app_secret() {
		return (string) Tuki_Settings::get( 'wa_app_secret' );
	}

	/**
	 * The token Meta echoes during the webhook verification handshake.
	 *
	 * @return string
	 */
	public static function verify_token() {
		return (string) Tuki_Settings::get( 'wa_verify_token' );
	}

	/**
	 * The webhook URL to paste into Meta's dashboard.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( Tuki_Rest::NAMESPACE . '/whatsapp' );
	}

	/*
	 * Inbound: verification + signed webhook
	 */

	/**
	 * GET — Meta's webhook verification handshake. Echoes hub.challenge verbatim
	 * only when the mode is 'subscribe' AND hub.verify_token matches ours.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_verify( WP_REST_Request $request ) {
		// Meta sends hub.mode / hub.verify_token / hub.challenge. PHP rewrites dots
		// to underscores in query keys, but accept both spellings defensively.
		$param = static function ( $name ) use ( $request ) {
			$value = $request->get_param( 'hub_' . $name );

			if ( null === $value || '' === $value ) {
				$value = $request->get_param( 'hub.' . $name );
			}

			return (string) $value;
		};

		$mode      = $param( 'mode' );
		$token     = $param( 'verify_token' );
		$challenge = $param( 'challenge' );
		$expected  = self::verify_token();

		if ( 'subscribe' !== $mode || '' === $expected || ! hash_equals( $expected, $token ) ) {
			return new WP_Error( 'tuki_wa_forbidden', __( 'Verification failed.', 'tukify' ), array( 'status' => 403 ) );
		}

		// Meta requires the raw challenge string echoed back, not JSON.
		$response = new WP_REST_Response( $challenge );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );

		return $response;
	}

	/**
	 * Permission callback for the inbound POST: the signature IS the auth.
	 *
	 * Runs before any payload parsing, so an unsigned or tampered request never
	 * reaches the handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function verify_signature( WP_REST_Request $request ) {
		$secret = self::app_secret();

		if ( '' === $secret ) {
			return new WP_Error( 'tuki_wa_unconfigured', __( 'WhatsApp is not configured.', 'tukify' ), array( 'status' => 403 ) );
		}

		$header = (string) $request->get_header( 'x_hub_signature_256' );
		$body   = (string) $request->get_body();

		if ( '' === $header || 0 !== strpos( $header, 'sha256=' ) || '' === $body ) {
			return new WP_Error( 'tuki_wa_unsigned', __( 'Missing signature.', 'tukify' ), array( 'status' => 403 ) );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected, $header ) ) {
			return new WP_Error( 'tuki_wa_bad_signature', __( 'Invalid signature.', 'tukify' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * POST — signed inbound webhook. Acknowledges immediately and queues the real
	 * work, because a provider round-trip is far slower than Meta's timeout.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		// Always 200: Meta retries anything else, and a retry storm would multiply
		// provider cost. Problems are handled internally instead.
		$ack = new WP_REST_Response( array( 'received' => true ), 200 );

		if ( ! is_array( $payload ) || ! self::is_enabled() ) {
			return $ack;
		}

		foreach ( self::extract_messages( $payload ) as $message ) {
			// Meta retries on any hiccup — never answer the same message twice.
			$dedupe_key = 'tuki_wa_seen_' . md5( $message['message_id'] );

			if ( get_transient( $dedupe_key ) ) {
				continue;
			}

			set_transient( $dedupe_key, 1, 10 * MINUTE_IN_SECONDS );

			if ( ! self::rate_ok( $message['wa_id'] ) ) {
				continue;
			}

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_PROCESS, array( $message ), self::GROUP );
			} else {
				// No Action Scheduler (WooCommerce inactive): degrade to inline.
				self::process( $message );
			}
		}

		return $ack;
	}

	/**
	 * Flattens Meta's nested webhook payload into simple message descriptors.
	 *
	 * @param array $payload Decoded webhook body.
	 * @return array List of [ wa_id, name, message_id, type, text, media_id ].
	 */
	private static function extract_messages( array $payload ) {
		$out = array();

		foreach ( (array) ( $payload['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
				$value = $change['value'] ?? array();

				if ( empty( $value['messages'] ) || ! is_array( $value['messages'] ) ) {
					continue; // Status callbacks (delivered/read) — nothing to answer.
				}

				// Profile names arrive alongside, keyed by wa_id.
				$names = array();

				foreach ( (array) ( $value['contacts'] ?? array() ) as $contact ) {
					if ( isset( $contact['wa_id'] ) ) {
						$names[ (string) $contact['wa_id'] ] = (string) ( $contact['profile']['name'] ?? '' );
					}
				}

				foreach ( $value['messages'] as $message ) {
					$wa_id = (string) ( $message['from'] ?? '' );
					$type  = (string) ( $message['type'] ?? '' );

					if ( '' === $wa_id ) {
						continue;
					}

					$text = '';

					if ( 'text' === $type ) {
						$text = (string) ( $message['text']['body'] ?? '' );
					} elseif ( 'interactive' === $type ) {
						// A tapped list row / reply button: treat its title as the message.
						$interactive = $message['interactive'] ?? array();
						$text        = (string) (
							$interactive['list_reply']['title'] ?? $interactive['button_reply']['title'] ?? ''
						);
						$type        = 'text';
					}

					$out[] = array(
						'wa_id'      => $wa_id,
						'name'       => (string) ( $names[ $wa_id ] ?? '' ),
						'message_id' => (string) ( $message['id'] ?? wp_generate_uuid4() ),
						'type'       => $type,
						'text'       => $text,
						'media_id'   => (string) ( $message['image']['id'] ?? '' ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Per-phone inbound rate limit, so a flood can't run up provider cost.
	 *
	 * @param string $wa_id Sender.
	 * @return bool
	 */
	private static function rate_ok( $wa_id ) {
		$key   = 'tuki_wa_rl_' . substr( Tuki_WA_Sessions::hash_phone( $wa_id ), 0, 20 );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return true;
	}

	/*
	 * Processing (async) — one brain, second door
	 */

	/**
	 * Runs one inbound message through the shared pipeline and sends the reply.
	 *
	 * @param array $message Descriptor from extract_messages().
	 * @return void
	 */
	public static function process( $message ) {
		if ( ! is_array( $message ) || ! self::is_enabled() ) {
			return;
		}

		$wa_id = (string) ( $message['wa_id'] ?? '' );
		$type  = (string) ( $message['type'] ?? '' );
		$text  = trim( (string) ( $message['text'] ?? '' ) );

		if ( '' === $wa_id ) {
			return;
		}

		// Anything that isn't text or a photo: say so politely and stop.
		if ( ! in_array( $type, array( 'text', 'image' ), true ) ) {
			self::send_text( $wa_id, __( 'I can read text and photos — please send your question as a message, or a photo of what you\'re looking for.', 'tukify' ) );
			return;
		}

		$stored  = Tuki_WA_Sessions::get( $wa_id, (string) ( $message['name'] ?? '' ) );
		$session = $stored['session'];

		// Explicit request for a person — hand off, don't try to answer.
		if ( '' !== $text && self::wants_human( $text ) ) {
			self::handoff( $wa_id, $session, $text, __( 'A shopper asked to speak with a person.', 'tukify' ) );
			return;
		}

		$attachments = array();

		if ( 'image' === $type && '' !== (string) ( $message['media_id'] ?? '' ) ) {
			$media = self::download_media( (string) $message['media_id'] );

			if ( ! $media ) {
				self::send_text( $wa_id, __( 'Sorry, I couldn\'t open that photo. Could you send it again, or describe what you\'re after?', 'tukify' ) );
				return;
			}

			$attachments[] = array(
				'type' => 'image',
				'data' => $media['data'],
				'mime' => $media['mime'],
			);
		}

		try {
			$reply = Tuki_Channel::handle_message( $session, $text, $attachments );
		} catch ( Exception $e ) {
			// Provider/retrieval failure → friendly fallback, never a raw error.
			self::send_text( $wa_id, __( 'Sorry — I\'m having trouble right now. Please try again in a moment.', 'tukify' ) );
			return;
		}

		// Low confidence / nothing useful found → offer a human instead of waffling.
		if ( self::is_low_confidence( $reply ) ) {
			self::handoff( $wa_id, $session, $text, __( 'The assistant could not answer confidently.', 'tukify' ) );
			return;
		}

		foreach ( Tuki_WA_Format::build( $reply ) as $outbound ) {
			self::send( $wa_id, $outbound );
		}

		$session = Tuki_Channel::append_turn( $session, $text, $reply );
		Tuki_WA_Sessions::save( $wa_id, $session, (string) ( $message['name'] ?? '' ) );
	}

	/**
	 * Whether the shopper is asking for a human.
	 *
	 * @param string $text Message.
	 * @return bool
	 */
	private static function wants_human( $text ) {
		$text = mb_strtolower( $text );

		/**
		 * Filters the phrases that trigger a human handoff. Translators/stores can
		 * add local-language phrases (e.g. Bangla) without touching the plugin.
		 *
		 * @param array $phrases Lowercase needles.
		 */
		$phrases = apply_filters(
			'tuki_wa_human_phrases',
			array(
				'human',
				'agent',
				'real person',
				'speak to someone',
				'talk to someone',
				'customer service',
				'support team',
				'manager',
			)
		);

		foreach ( (array) $phrases as $phrase ) {
			if ( '' !== $phrase && false !== mb_strpos( $text, mb_strtolower( $phrase ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Treats an empty, product-less non-informational reply as low confidence.
	 *
	 * @param array $reply Reply object.
	 * @return bool
	 */
	private static function is_low_confidence( array $reply ) {
		$text   = trim( (string) ( $reply['reply'] ?? '' ) );
		$intent = (string) ( $reply['intent'] ?? '' );

		if ( '' === $text ) {
			return true;
		}

		// Clarifying questions and KB/policy answers are legitimate replies.
		if ( in_array( $intent, array( 'clarify', 'policy', 'info', 'order_status', 'size_advice' ), true ) ) {
			return false;
		}

		return empty( $reply['products'] );
	}

	/**
	 * Tells the shopper a human will follow up and emails the store the snippet.
	 *
	 * @param string $wa_id   Sender.
	 * @param array  $session Session (for the transcript).
	 * @param string $text    Latest message.
	 * @param string $reason  Why we handed off.
	 * @return void
	 */
	private static function handoff( $wa_id, array $session, $text, $reason ) {
		self::send_text( $wa_id, __( 'Thanks for reaching out — our team will get back to you shortly.', 'tukify' ) );

		$to = (string) Tuki_Settings::get( 'wa_notify_email' );

		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$lines = array( $reason, '' );

		foreach ( array_slice( (array) ( $session['history'] ?? array() ), -6 ) as $turn ) {
			$who     = ( 'assistant' === ( $turn['role'] ?? '' ) ) ? __( 'Assistant', 'tukify' ) : __( 'Shopper', 'tukify' );
			$lines[] = $who . ': ' . (string) ( $turn['content'] ?? '' );
		}

		if ( '' !== $text ) {
			$lines[] = __( 'Shopper', 'tukify' ) . ': ' . $text;
		}

		$lines[] = '';
		/* translators: %s: short, non-identifying conversation reference. */
		$lines[] = sprintf( __( 'Conversation reference: %s', 'tukify' ), substr( Tuki_WA_Sessions::hash_phone( $wa_id ), 0, 8 ) );
		$lines[] = __( 'Reply to the shopper in WhatsApp Manager (the phone number is not stored by Tukify).', 'tukify' );

		wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] A WhatsApp shopper needs a human', 'tukify' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			implode( "\n", $lines )
		);
	}

	/*
	 * Outbound (Cloud API)
	 */

	/**
	 * Sends one formatted outbound message descriptor.
	 *
	 * @param string $wa_id    Recipient.
	 * @param array  $outbound Descriptor from Tuki_WA_Format::build().
	 * @return bool
	 */
	public static function send( $wa_id, array $outbound ) {
		$type = (string) ( $outbound['type'] ?? 'text' );

		if ( 'image' === $type ) {
			return self::send_image( $wa_id, (string) $outbound['link'], (string) ( $outbound['caption'] ?? '' ) );
		}

		if ( 'list' === $type ) {
			return self::send_list( $wa_id, $outbound );
		}

		return self::send_text( $wa_id, (string) ( $outbound['text'] ?? '' ) );
	}

	/**
	 * Sends a plain text message.
	 *
	 * @param string $wa_id Recipient.
	 * @param string $text  Body.
	 * @return bool
	 */
	public static function send_text( $wa_id, $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return false;
		}

		return (bool) self::request(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $wa_id,
				'type'              => 'text',
				'text'              => array(
					'preview_url' => true,
					'body'        => $text,
				),
			)
		);
	}

	/**
	 * Sends an image with a caption.
	 *
	 * @param string $wa_id   Recipient.
	 * @param string $link    Public image URL.
	 * @param string $caption Caption text.
	 * @return bool
	 */
	public static function send_image( $wa_id, $link, $caption = '' ) {
		if ( '' === $link ) {
			return self::send_text( $wa_id, $caption );
		}

		$sent = (bool) self::request(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $wa_id,
				'type'              => 'image',
				'image'             => array(
					'link'    => $link,
					'caption' => $caption,
				),
			)
		);

		// A local/unreachable image URL is common on staging — never lose the reply.
		if ( ! $sent && '' !== $caption ) {
			return self::send_text( $wa_id, $caption );
		}

		return $sent;
	}

	/**
	 * Sends an interactive list (used when several products matched).
	 *
	 * @param string $wa_id    Recipient.
	 * @param array  $outbound Descriptor: body, button, rows.
	 * @return bool
	 */
	public static function send_list( $wa_id, array $outbound ) {
		$rows = array_slice( (array) ( $outbound['rows'] ?? array() ), 0, 10 );

		if ( empty( $rows ) ) {
			return self::send_text( $wa_id, (string) ( $outbound['body'] ?? '' ) );
		}

		$sent = (bool) self::request(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $wa_id,
				'type'              => 'interactive',
				'interactive'       => array(
					'type'   => 'list',
					'body'   => array( 'text' => (string) ( $outbound['body'] ?? '' ) ),
					'action' => array(
						'button'   => (string) ( $outbound['button'] ?? __( 'View products', 'tukify' ) ),
						'sections' => array(
							array(
								'title' => (string) ( $outbound['section'] ?? __( 'Products', 'tukify' ) ),
								'rows'  => $rows,
							),
						),
					),
				),
			)
		);

		if ( ! $sent ) {
			return self::send_text( $wa_id, (string) ( $outbound['fallback'] ?? ( $outbound['body'] ?? '' ) ) );
		}

		return true;
	}

	/**
	 * POSTs to the Cloud API messages endpoint.
	 *
	 * The access token is only ever used as a header here; neither it nor the
	 * recipient number is written to any log.
	 *
	 * @param array $body Request body.
	 * @return array|false Decoded response, or false on failure.
	 */
	private static function request( array $body ) {
		$token = self::token();
		$phone = self::phone_number_id();

		if ( '' === $token || '' === $phone ) {
			return false;
		}

		$response = wp_remote_post(
			self::API_BASE . self::API_VERSION . '/' . rawurlencode( $phone ) . '/messages',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Downloads an inbound image: resolve the media id to a URL, then fetch bytes.
	 *
	 * @param string $media_id Meta media id.
	 * @return array{data:string,mime:string}|false Base64 payload for the vision pipeline.
	 */
	private static function download_media( $media_id ) {
		$token = self::token();

		if ( '' === $token || '' === $media_id ) {
			return false;
		}

		$meta = wp_remote_get(
			self::API_BASE . self::API_VERSION . '/' . rawurlencode( $media_id ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $meta ) ) {
			return false;
		}

		$info = json_decode( wp_remote_retrieve_body( $meta ), true );
		$url  = isset( $info['url'] ) ? (string) $info['url'] : '';
		$mime = isset( $info['mime_type'] ) ? strtolower( (string) $info['mime_type'] ) : 'image/jpeg';

		if ( '' === $url ) {
			return false;
		}

		$file = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $file ) ) {
			return false;
		}

		$bytes = wp_remote_retrieve_body( $file );

		if ( '' === $bytes || strlen( $bytes ) > 4194304 ) {
			return false;
		}

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			$mime = 'image/jpeg';
		}

		return array(
			// Encoding binary image bytes for the vision provider, not obfuscation.
			'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'mime' => $mime,
		);
	}

	/*
	 * Tokenized add-to-cart links
	 */

	/**
	 * Builds a signed add-to-cart link for a product.
	 *
	 * Signed (and day-scoped) so the links Tukify hands out can't be rewritten to
	 * stuff arbitrary products into a cart or replayed indefinitely.
	 *
	 * @param int $product_id Product.
	 * @return string
	 */
	public static function add_to_cart_url( $product_id ) {
		$product_id = absint( $product_id );

		return add_query_arg(
			array(
				'tuki_atc' => $product_id,
				'tuki_t'   => self::atc_token( $product_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Token for an add-to-cart link (valid for the current UTC day).
	 *
	 * @param int $product_id Product.
	 * @return string
	 */
	private static function atc_token( $product_id ) {
		return substr( hash_hmac( 'sha256', $product_id . '|' . gmdate( 'Y-m-d' ), wp_salt( 'tuki_wa_atc' ) ), 0, 20 );
	}

	/**
	 * Validates a tokenized add-to-cart link and forwards to WooCommerce's own
	 * add-to-cart handler, then the cart.
	 *
	 * @return void
	 */
	public static function handle_add_to_cart_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link (HMAC token), not a form post.
		if ( ! isset( $_GET['tuki_atc'], $_GET['tuki_t'] ) ) {
			return;
		}

		$product_id = absint( $_GET['tuki_atc'] );
		$token      = sanitize_text_field( wp_unslash( $_GET['tuki_t'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $product_id || ! hash_equals( self::atc_token( $product_id ), $token ) ) {
			return;
		}

		if ( ! function_exists( 'wc_get_cart_url' ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'add-to-cart', $product_id, wc_get_cart_url() ) );
		exit;
	}
}
