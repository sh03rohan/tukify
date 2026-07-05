<?php
/**
 * API usage tracking + cost estimation.
 *
 * Aggregates request/token counts per day and kind (embedding vs chat) so the
 * owner can see how their bring-your-own Gemini key is being spent. Only counts
 * are stored — never any query text or personal data. Caching (Tuki_Cache) is
 * what actually reduces the calls; this just measures what's left.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and reports Gemini API usage.
 */
class Tuki_Usage {

	/**
	 * Records one API call's usage against today's counters.
	 *
	 * @param string $kind       'embedding' or 'chat'.
	 * @param int    $requests   Number of API requests (usually 1).
	 * @param int    $prompt     Prompt/input tokens.
	 * @param int    $completion Completion/output tokens.
	 * @return void
	 */
	public static function record( $kind, $requests, $prompt, $completion ) {
		$kind = ( 'embedding' === $kind ) ? 'embedding' : 'chat';

		if ( ! class_exists( 'Tuki_DB' ) ) {
			return;
		}

		Tuki_DB::record_usage(
			current_time( 'Y-m-d' ),
			$kind,
			max( 0, (int) $requests ),
			max( 0, (int) $prompt ),
			max( 0, (int) $completion )
		);
	}

	/**
	 * Rough token estimate for a string (≈4 characters per token). Used when the
	 * provider does not report exact token usage (e.g. embeddings).
	 *
	 * @param string|array $text One string or a list of strings.
	 * @return int
	 */
	public static function estimate_tokens( $text ) {
		$chars = 0;

		foreach ( (array) $text as $chunk ) {
			$chunk  = (string) $chunk;
			$chars += function_exists( 'mb_strlen' ) ? mb_strlen( $chunk ) : strlen( $chunk );
		}

		return (int) ceil( $chars / 4 );
	}

	/**
	 * Configured price per 1M tokens for a metric, from settings.
	 *
	 * @param string $which 'chat_input' | 'chat_output' | 'embedding'.
	 * @return float USD per 1,000,000 tokens.
	 */
	public static function price( $which ) {
		$map = array(
			'chat_input'  => 'price_chat_input',
			'chat_output' => 'price_chat_output',
			'embedding'   => 'price_embedding',
		);

		if ( ! isset( $map[ $which ] ) ) {
			return 0.0;
		}

		return max( 0, (float) Tuki_Settings::get( $map[ $which ] ) );
	}

	/**
	 * Estimated cost (USD) for a totals-by-kind map from Tuki_DB::usage_totals().
	 *
	 * @param array $totals Map of kind => [ requests, prompt_tokens, completion_tokens ].
	 * @return float
	 */
	public static function estimated_cost( array $totals ) {
		$chat  = isset( $totals['chat'] ) ? $totals['chat'] : array();
		$embed = isset( $totals['embedding'] ) ? $totals['embedding'] : array();

		$cost = 0.0;
		$cost += ( isset( $chat['prompt_tokens'] ) ? (int) $chat['prompt_tokens'] : 0 ) / 1000000 * self::price( 'chat_input' );
		$cost += ( isset( $chat['completion_tokens'] ) ? (int) $chat['completion_tokens'] : 0 ) / 1000000 * self::price( 'chat_output' );
		$cost += ( isset( $embed['prompt_tokens'] ) ? (int) $embed['prompt_tokens'] : 0 ) / 1000000 * self::price( 'embedding' );

		return $cost;
	}

	/**
	 * Builds a normalized per-day series for the usage chart, filling empty days
	 * with zeros so the bars are evenly spaced.
	 *
	 * @param int $days Number of days back from today (min 1).
	 * @return array{days:array,max:int,total_tokens:int,total_requests:int}
	 */
	public static function series( $days ) {
		$days = max( 1, (int) $days );

		$now   = current_time( 'timestamp' );
		$start = $now - ( ( $days - 1 ) * DAY_IN_SECONDS );

		// Seed every day in range with zeros.
		$series = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$key            = gmdate( 'Y-m-d', $start + ( $i * DAY_IN_SECONDS ) );
			$series[ $key ] = array(
				'day'    => $key,
				'chat'   => 0,
				'embed'  => 0,
				'tokens' => 0,
			);
		}

		$since = gmdate( 'Y-m-d', $start );
		$rows  = Tuki_DB::usage_daily( $since );

		$max            = 0;
		$total_tokens   = 0;
		$total_requests = 0;

		foreach ( $rows as $row ) {
			$key = substr( (string) $row['day'], 0, 10 );

			if ( ! isset( $series[ $key ] ) ) {
				continue;
			}

			$tokens = (int) $row['prompt_tokens'] + (int) $row['completion_tokens'];

			if ( 'embedding' === $row['kind'] ) {
				$series[ $key ]['embed'] += $tokens;
			} else {
				$series[ $key ]['chat'] += $tokens;
			}

			$series[ $key ]['tokens'] += $tokens;
			$total_tokens             += $tokens;
			$total_requests           += (int) $row['requests'];
		}

		foreach ( $series as $entry ) {
			$max = max( $max, $entry['tokens'] );
		}

		return array(
			'days'           => array_values( $series ),
			'max'            => $max,
			'total_tokens'   => $total_tokens,
			'total_requests' => $total_requests,
		);
	}
}
