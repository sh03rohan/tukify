<?php
/**
 * Size & fit advisor.
 *
 * Deterministic (no AI) size recommendation from a merchant-defined per-category
 * size chart plus a few shopper answers (height, weight, usual brand size, fit
 * preference). Keeping the logic rule-based guarantees the wording stays neutral
 * and helpful — never medical, never body-shaming — and that the same inputs
 * always give the same advice.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommends a size for a product from a category size chart + shopper answers.
 */
class Tuki_Size {

	/**
	 * Whether the advisor is enabled in settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Tuki_Settings::get( 'size_advisor_enabled' );
	}

	/**
	 * The available size options for a product, read from its size attribute
	 * (global `pa_size` or a custom attribute named by the setting). Values are
	 * returned as human-readable labels in the order the product defines them.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product_sizes( $product ) {
		if ( ! $product || ! is_callable( array( $product, 'get_attribute' ) ) ) {
			return array();
		}

		$attr = trim( (string) Tuki_Settings::get( 'size_attribute' ) );

		if ( '' === $attr ) {
			$attr = 'size';
		}

		foreach ( array( $attr, 'pa_' . $attr, ucfirst( $attr ) ) as $name ) {
			$raw = (string) $product->get_attribute( $name );

			if ( '' !== trim( $raw ) ) {
				return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
			}
		}

		return array();
	}

	/**
	 * Whether a product is eligible for size advice (has 2+ size options).
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function has_sizes( $product ) {
		return count( self::product_sizes( $product ) ) >= 2;
	}

	/**
	 * The size-band rows (chart) that apply to a product, resolved from its
	 * categories. The first category that has a chart wins.
	 *
	 * @param WC_Product $product Product.
	 * @return array List of bands: [ label, h_min, h_max, w_min, w_max ].
	 */
	public static function product_chart( $product ) {
		$charts = (array) Tuki_Settings::get( 'size_charts' );

		if ( empty( $charts ) || ! $product ) {
			return array();
		}

		$by_cat = array();

		foreach ( $charts as $row ) {
			$cat = isset( $row['category'] ) ? (int) $row['category'] : 0;

			if ( $cat > 0 ) {
				$by_cat[ $cat ][] = $row;
			}
		}

		$term_ids = wc_get_product_term_ids( $product->get_id(), 'product_cat' );

		foreach ( (array) $term_ids as $tid ) {
			if ( ! empty( $by_cat[ (int) $tid ] ) ) {
				return $by_cat[ (int) $tid ];
			}
		}

		return array();
	}

	/**
	 * Builds the guided-form payload for the chat when size advice is offered.
	 *
	 * @param int    $product_id Product id.
	 * @param string $reply      Friendly lead-in from the model.
	 * @return array
	 */
	public static function form_payload( $product_id, $reply ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		return array(
			'reply'     => $reply,
			'products'  => array(),
			'intent'    => 'size_advice',
			'size_form' => array(
				'product_id' => (int) $product_id,
				'title'      => $product ? $product->get_name() : '',
				'unit'       => self::unit(),
				'sizes'      => $product ? self::product_sizes( $product ) : array(),
			),
		);
	}

	/**
	 * The configured measurement unit ('metric' or 'imperial').
	 *
	 * @return string
	 */
	public static function unit() {
		return 'imperial' === Tuki_Settings::get( 'size_unit' ) ? 'imperial' : 'metric';
	}

	/**
	 * Recommends a size from the product's chart + the shopper's answers.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $answers height, weight, fit ('slim'|'regular'|'loose'), brand_size.
	 * @return array{recommended:string,alternate:string,confidence:string,confidence_label:string,rationale:string,caveat:string,sizes:array,title:string}
	 */
	public static function recommend( $product, array $answers ) {
		$sizes  = self::product_sizes( $product );
		$chart  = self::product_chart( $product );
		$height = isset( $answers['height'] ) ? (float) $answers['height'] : 0.0;
		$weight = isset( $answers['weight'] ) ? (float) $answers['weight'] : 0.0;
		$fit    = in_array( $answers['fit'] ?? '', array( 'slim', 'regular', 'loose' ), true ) ? $answers['fit'] : 'regular';
		$brand  = isset( $answers['brand_size'] ) ? trim( (string) $answers['brand_size'] ) : '';

		$caveat = __( 'Sizes can vary between brands and styles — if you\'re between sizes, check this product\'s size chart before ordering.', 'tukify' );

		$base       = '';
		$confidence = 'low';

		// 1) Best band from the chart via height + weight.
		if ( ! empty( $chart ) && ( $height > 0 || $weight > 0 ) ) {
			$best        = null;
			$best_signal = self::score_bands( $chart, $height, $weight );
			$best        = $best_signal['band'];

			if ( $best ) {
				$base = (string) $best['label'];

				if ( 2 === $best_signal['signals'] && 2 === $best_signal['inrange'] ) {
					$confidence = 'high';
				} elseif ( $best_signal['inrange'] >= 1 ) {
					$confidence = 'medium';
				} else {
					$confidence = 'low';
				}
			}
		}

		// 2) Fall back to the shopper's usual size if it matches an option.
		if ( '' === $base && '' !== $brand ) {
			$match = self::match_size( $brand, $sizes );

			if ( '' !== $match ) {
				$base       = $match;
				$confidence = 'medium';
			}
		}

		// 3) Last resort: the middle available size, low confidence.
		if ( '' === $base && ! empty( $sizes ) ) {
			$base       = $sizes[ (int) floor( ( count( $sizes ) - 1 ) / 2 ) ];
			$confidence = 'low';
		}

		// Nothing to go on at all.
		if ( '' === $base ) {
			return array(
				'recommended'      => '',
				'alternate'        => '',
				'confidence'       => 'low',
				'confidence_label' => self::confidence_label( 'low' ),
				'rationale'        => __( 'I don\'t have enough size information for this product yet. Please check its size chart to choose.', 'tukify' ),
				'caveat'           => $caveat,
				'sizes'            => $sizes,
				'title'            => $product ? $product->get_name() : '',
			);
		}

		// Map the chosen label to an actual available size and pick a fit-based
		// alternate one step snugger (slim) or roomier (loose).
		$recommended = self::match_size( $base, $sizes );

		if ( '' === $recommended ) {
			$recommended = $base;
		}

		$alternate = '';

		if ( 'slim' === $fit ) {
			$alternate = self::adjacent( $recommended, $sizes, -1 );
		} elseif ( 'loose' === $fit ) {
			$alternate = self::adjacent( $recommended, $sizes, 1 );
		}

		return array(
			'recommended'      => $recommended,
			'alternate'        => $alternate,
			'confidence'       => $confidence,
			'confidence_label' => self::confidence_label( $confidence ),
			'rationale'        => self::rationale( $recommended, $confidence, $fit, $alternate, ! empty( $chart ) ),
			'caveat'           => $caveat,
			'sizes'            => $sizes,
			'title'            => $product ? $product->get_name() : '',
		);
	}

	/**
	 * Scores every band against height/weight and returns the best.
	 *
	 * @param array $chart  Bands.
	 * @param float $height Height in the configured unit (0 if unknown).
	 * @param float $weight Weight in the configured unit (0 if unknown).
	 * @return array{band:?array,inrange:int,signals:int}
	 */
	private static function score_bands( array $chart, $height, $weight ) {
		$best        = null;
		$best_score  = -INF;
		$best_inrng  = 0;
		$best_signal = 0;

		foreach ( $chart as $band ) {
			$score   = 0.0;
			$inrange = 0;
			$signals = 0;

			if ( $height > 0 && ( self::num( $band, 'h_min' ) > 0 || self::num( $band, 'h_max' ) > 0 ) ) {
				++$signals;
				list( $ok, $s ) = self::range_fit( $height, self::num( $band, 'h_min' ), self::num( $band, 'h_max' ) );
				$score         += $s;
				$inrange       += $ok ? 1 : 0;
			}

			if ( $weight > 0 && ( self::num( $band, 'w_min' ) > 0 || self::num( $band, 'w_max' ) > 0 ) ) {
				++$signals;
				list( $ok, $s ) = self::range_fit( $weight, self::num( $band, 'w_min' ), self::num( $band, 'w_max' ) );
				$score         += $s;
				$inrange       += $ok ? 1 : 0;
			}

			if ( $signals > 0 && $score > $best_score ) {
				$best_score  = $score;
				$best        = $band;
				$best_inrng  = $inrange;
				$best_signal = $signals;
			}
		}

		return array(
			'band'    => $best,
			'inrange' => $best_inrng,
			'signals' => $best_signal,
		);
	}

	/**
	 * Fit of a value against a [min,max] band: in-range scores 1, and the score
	 * falls off (toward negative) the further outside it lands.
	 *
	 * @param float $value Measurement.
	 * @param float $min   Band minimum (0 = open).
	 * @param float $max   Band maximum (0 = open).
	 * @return array{0:bool,1:float}
	 */
	private static function range_fit( $value, $min, $max ) {
		$has_min = $min > 0;
		$has_max = $max > 0;

		$above_min = ! $has_min || $value >= $min;
		$below_max = ! $has_max || $value <= $max;

		if ( $above_min && $below_max ) {
			return array( true, 1.0 );
		}

		$span = max( 1.0, ( $has_min && $has_max ) ? ( $max - $min ) : 10.0 );
		$dist = ( $has_min && $value < $min ) ? ( $min - $value ) : ( $value - $max );
		$s    = 1.0 - ( $dist / $span );

		return array( false, max( -1.0, min( 1.0, $s ) ) );
	}

	/**
	 * Reads a numeric band field.
	 *
	 * @param array  $band Band row.
	 * @param string $key  Field key.
	 * @return float
	 */
	private static function num( $band, $key ) {
		return isset( $band[ $key ] ) ? (float) $band[ $key ] : 0.0;
	}

	/**
	 * Maps a size label to an actual available option: exact (case-insensitive),
	 * then nearest numeric, then the middle option.
	 *
	 * @param string $label Desired label.
	 * @param array  $sizes Available sizes.
	 * @return string Matched size, or '' if none available.
	 */
	private static function match_size( $label, array $sizes ) {
		$label = trim( (string) $label );

		if ( empty( $sizes ) || '' === $label ) {
			return '';
		}

		foreach ( $sizes as $size ) {
			if ( 0 === strcasecmp( trim( $size ), $label ) ) {
				return $size;
			}
		}

		if ( is_numeric( $label ) ) {
			$target  = (float) $label;
			$nearest = '';
			$best    = INF;

			foreach ( $sizes as $size ) {
				if ( is_numeric( $size ) ) {
					$d = abs( (float) $size - $target );

					if ( $d < $best ) {
						$best    = $d;
						$nearest = $size;
					}
				}
			}

			if ( '' !== $nearest ) {
				return $nearest;
			}
		}

		return '';
	}

	/**
	 * The option $step positions away from $size within the available list.
	 *
	 * @param string $size  Current size.
	 * @param array  $sizes Available sizes.
	 * @param int    $step  -1 for smaller, +1 for larger.
	 * @return string Adjacent size, or '' if none.
	 */
	private static function adjacent( $size, array $sizes, $step ) {
		$index = array_search( $size, $sizes, true );

		if ( false === $index ) {
			return '';
		}

		$next = (int) $index + (int) $step;

		return ( $next >= 0 && $next < count( $sizes ) ) ? $sizes[ $next ] : '';
	}

	/**
	 * Localized confidence label.
	 *
	 * @param string $confidence high|medium|low.
	 * @return string
	 */
	private static function confidence_label( $confidence ) {
		switch ( $confidence ) {
			case 'high':
				return __( 'High confidence', 'tukify' );
			case 'medium':
				return __( 'Good starting point', 'tukify' );
			default:
				return __( 'Rough guide', 'tukify' );
		}
	}

	/**
	 * Builds the neutral, helpful recommendation sentence(s). No measurements are
	 * echoed back and no judgemental language is ever used.
	 *
	 * @param string $size       Recommended size.
	 * @param string $confidence Confidence level.
	 * @param string $fit        Fit preference.
	 * @param string $alternate  Fit-based alternate size (may be '').
	 * @param bool   $had_chart  Whether a category chart was used.
	 * @return string
	 */
	private static function rationale( $size, $confidence, $fit, $alternate, $had_chart ) {
		if ( $had_chart ) {
			/* translators: %s: recommended size. */
			$lead = sprintf( __( 'Based on the details you shared and this item\'s size chart, %s looks like your best fit.', 'tukify' ), $size );
		} else {
			/* translators: %s: recommended size. */
			$lead = sprintf( __( 'From the details you shared, %s is a sensible choice for this item.', 'tukify' ), $size );
		}

		if ( '' !== $alternate ) {
			if ( 'slim' === $fit ) {
				/* translators: %s: alternate (smaller) size. */
				$lead .= ' ' . sprintf( __( 'Since you like a slimmer fit, %s would sit closer to the body if you prefer.', 'tukify' ), $alternate );
			} elseif ( 'loose' === $fit ) {
				/* translators: %s: alternate (larger) size. */
				$lead .= ' ' . sprintf( __( 'Since you like a relaxed fit, %s would give you a bit more room.', 'tukify' ), $alternate );
			}
		}

		return $lead;
	}
}
