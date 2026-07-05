<?php
/**
 * Response + embedding cache.
 *
 * Cuts API cost by reusing results for identical / near-identical repeat work:
 *   - Query embeddings (deterministic given model + text).
 *   - Knowledge-base answers (grounded in static site content, not user state).
 *
 * Backed by WordPress transients. A salt option is mixed into every key so a
 * single "Clear cache" flips the salt and instantly orphans every entry (they
 * then expire on their own TTL) — no scanning required.
 *
 * SAFETY: nothing user-personal is ever cached here. The answer cache is only
 * ever written for non-personal, non-catalog intents (policy/FAQ/info) with no
 * conversation or session context, so order lookups, size advice, cart-aware
 * replies, and product/stock results never enter it. Callers must uphold this.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed cache for embeddings and KB answers.
 */
class Tuki_Cache {

	/**
	 * Option storing the cache salt (bumping it clears the cache).
	 */
	const SALT_OPTION = 'tuki_cache_salt';

	/**
	 * Whether caching is enabled in settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Tuki_Settings::get( 'cache_enabled' );
	}

	/**
	 * Cache lifetime in seconds, from the TTL-hours setting.
	 *
	 * @return int
	 */
	public static function ttl() {
		$hours = (int) Tuki_Settings::get( 'cache_ttl_hours' );
		$hours = min( 720, max( 1, $hours ) );

		return $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Returns the current cache salt, creating one on first use.
	 *
	 * @return string
	 */
	private static function salt() {
		$salt = get_option( self::SALT_OPTION );

		if ( ! $salt ) {
			$salt = self::new_salt();
			update_option( self::SALT_OPTION, $salt, false );
		}

		return (string) $salt;
	}

	/**
	 * Generates a fresh random salt.
	 *
	 * @return string
	 */
	private static function new_salt() {
		return function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false, false ) : (string) wp_rand();
	}

	/**
	 * Clears the whole cache by rotating the salt. Old transients expire on TTL.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::SALT_OPTION, self::new_salt(), false );
	}

	/**
	 * Normalizes text for near-identical matching: lowercased, punctuation-trimmed,
	 * and whitespace-collapsed, so "Return policy?" and "return policy" share a key.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text ) : strtolower( (string) $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		// Drop surrounding punctuation (?, !, ., quotes) that doesn't change intent.
		$text = trim( $text, " \t\n\r\0\x0B.?!,;:\"'" );

		return $text;
	}

	/**
	 * Builds a transient key for a namespace + payload.
	 *
	 * @param string $namespace Logical bucket (e.g. 'emb', 'ans').
	 * @param string $payload   Key material.
	 * @return string
	 */
	private static function key( $namespace, $payload ) {
		return 'tuki_c_' . md5( self::salt() . '|' . $namespace . '|' . $payload );
	}

	/* ---------------------------------------------------------------------
	 * Query-embedding cache
	 * ------------------------------------------------------------------- */

	/**
	 * Returns a cached query embedding, or null on miss / when disabled.
	 *
	 * @param string $model Embedding model.
	 * @param string $text  Query text.
	 * @return array|null Float vector, or null.
	 */
	public static function get_embedding( $model, $text ) {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$cached = get_transient( self::key( 'emb', $model . '|' . self::normalize( $text ) ) );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Stores a query embedding.
	 *
	 * @param string $model  Embedding model.
	 * @param string $text   Query text.
	 * @param array  $vector Float vector.
	 * @return void
	 */
	public static function set_embedding( $model, $text, array $vector ) {
		if ( ! self::is_enabled() || empty( $vector ) ) {
			return;
		}

		set_transient( self::key( 'emb', $model . '|' . self::normalize( $text ) ), $vector, self::ttl() );
	}

	/* ---------------------------------------------------------------------
	 * KB answer cache (non-personal intents only — enforced by the caller)
	 * ------------------------------------------------------------------- */

	/**
	 * Returns a cached answer for a message, or null on miss / when disabled.
	 *
	 * @param string $message Shopper message.
	 * @return array|null Cached result array, or null.
	 */
	public static function get_answer( $message ) {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$cached = get_transient( self::key( 'ans', self::normalize( $message ) ) );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Stores an answer for a message.
	 *
	 * @param string $message Shopper message.
	 * @param array  $result  Result array to cache (must be non-personal).
	 * @return void
	 */
	public static function set_answer( $message, array $result ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		set_transient( self::key( 'ans', self::normalize( $message ) ), $result, self::ttl() );
	}
}
