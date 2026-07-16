<?php
/**
 * Channel adapter — the single, channel-agnostic door into the chat brain.
 *
 * Every channel (the web widget, WhatsApp, anything added later) calls
 * handle_message() and gets the same reply object back. The brain itself
 * (Tuki_Chat) knows nothing about HTTP, the widget, or WhatsApp: it takes plain
 * arguments and returns a plain array. This class is the only place that maps a
 * channel's "session + message + attachments" onto that brain, so the pipeline
 * is never duplicated per channel.
 *
 * Reply object (exactly what Tuki_Chat returns, unchanged):
 *   [
 *     'reply'    => string,        // assistant text
 *     'products' => array,         // product card payloads (may be empty)
 *     'intent'   => string,        // clarify|order_status|policy|compare|browse_all|…
 *     …intent-specific keys (quick_replies, order_form, browse, comparison)
 *   ]
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes a channel message into the chat pipeline.
 */
class Tuki_Channel {

	/**
	 * Maximum conversation turns any channel may carry into the brain. Mirrors the
	 * web widget's own cap so every channel behaves identically.
	 */
	const MAX_HISTORY = 10;

	/**
	 * Maximum recently-shown product ids carried for reference resolution
	 * ("compare these", "the second one").
	 */
	const MAX_CONTEXT_IDS = 8;

	/**
	 * Normalises a channel session into the shape the brain expects.
	 *
	 * Channels store their session differently (the widget keeps it in the
	 * browser, WhatsApp in a DB row), so each one hands us a loose array and this
	 * enforces the same bounds and types for all of them.
	 *
	 * @param array $session Raw session: history, clarify_count, context_ids.
	 * @return array{history:array,clarify_count:int,context_ids:array}
	 */
	public static function normalize_session( $session ) {
		$session = is_array( $session ) ? $session : array();

		$history = array();

		if ( ! empty( $session['history'] ) && is_array( $session['history'] ) ) {
			foreach ( $session['history'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$content = isset( $item['content'] ) ? trim( (string) $item['content'] ) : '';

				if ( '' === $content ) {
					continue;
				}

				$history[] = array(
					'role'    => ( isset( $item['role'] ) && 'assistant' === $item['role'] ) ? 'assistant' : 'user',
					'content' => $content,
				);
			}
		}

		$context_ids = array();

		if ( ! empty( $session['context_ids'] ) && is_array( $session['context_ids'] ) ) {
			$context_ids = array_values( array_filter( array_map( 'absint', $session['context_ids'] ) ) );
		}

		return array(
			'history'       => array_slice( $history, -self::MAX_HISTORY ),
			'clarify_count' => isset( $session['clarify_count'] ) ? absint( $session['clarify_count'] ) : 0,
			'context_ids'   => array_slice( $context_ids, -self::MAX_CONTEXT_IDS ),
		);
	}

	/**
	 * THE entry point. Runs one turn of the assistant for any channel.
	 *
	 * Text goes through the RAG pipeline; an image attachment is routed to the
	 * existing visual-search pipeline. Both return the same reply-object shape.
	 *
	 * @param array  $session     Channel session (history, clarify_count, context_ids).
	 * @param string $message     The shopper's message text (may be '' for an image-only turn).
	 * @param array  $attachments Optional: [ [ 'type' => 'image', 'data' => base64, 'mime' => 'image/jpeg' ], … ].
	 * @return array Reply object.
	 * @throws Exception If the provider or retrieval fails (callers map this to a friendly fallback).
	 */
	public static function handle_message( $session, $message, array $attachments = array() ) {
		$session = self::normalize_session( $session );
		$message = trim( (string) $message );
		$chat    = new Tuki_Chat();

		// Image turn → visual search (same brain, different door).
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) || 'image' !== ( $attachment['type'] ?? '' ) ) {
				continue;
			}

			$data = (string) ( $attachment['data'] ?? '' );
			$mime = (string) ( $attachment['mime'] ?? 'image/jpeg' );

			if ( '' !== $data ) {
				return $chat->visual_search( $data, $mime );
			}
		}

		if ( '' === $message ) {
			return array(
				'reply'    => __( 'Could you tell me what you\'re looking for?', 'tukify' ),
				'products' => array(),
				'intent'   => 'clarify',
			);
		}

		return $chat->respond( $message, $session['history'], $session['clarify_count'], $session['context_ids'] );
	}

	/**
	 * Appends one completed turn to a session's history, keeping it bounded.
	 *
	 * Channels that persist their own conversation (WhatsApp) use this so the
	 * trimming rule lives in one place.
	 *
	 * @param array  $session Session to update.
	 * @param string $message The shopper's message.
	 * @param array  $reply   The reply object returned by handle_message().
	 * @return array Updated session.
	 */
	public static function append_turn( $session, $message, array $reply ) {
		$session = self::normalize_session( $session );
		$message = trim( (string) $message );

		if ( '' !== $message ) {
			$session['history'][] = array(
				'role'    => 'user',
				'content' => $message,
			);
		}

		$text = isset( $reply['reply'] ) ? trim( (string) $reply['reply'] ) : '';

		if ( '' !== $text ) {
			$session['history'][] = array(
				'role'    => 'assistant',
				'content' => $text,
			);
		}

		// Track what was shown so follow-ups like "compare these" resolve.
		if ( ! empty( $reply['products'] ) && is_array( $reply['products'] ) ) {
			foreach ( $reply['products'] as $product ) {
				if ( isset( $product['id'] ) ) {
					$session['context_ids'][] = absint( $product['id'] );
				}
			}
		}

		// A clarifying question was asked — count it so we never loop on clarifying.
		if ( isset( $reply['intent'] ) && 'clarify' === $reply['intent'] ) {
			++$session['clarify_count'];
		}

		return self::normalize_session( $session );
	}
}
