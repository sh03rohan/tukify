<?php
/**
 * Natural-language filters → real WooCommerce query constraints.
 *
 * Turns a structured filter object (price, attributes, brand, stock, category,
 * sort) into a WP_Query for candidate product IDs. Only maps taxonomies/terms
 * that actually exist in the store — never invents filters. The semantic ranking
 * happens separately (hybrid) in Tuki_Chat.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds WooCommerce candidate queries from parsed filters.
 */
class Tuki_Filters {

	/**
	 * Max candidate products fetched for a filtered query.
	 */
	const MAX_CANDIDATES = 200;

	/**
	 * Normalizes the raw filters object from the model into a clean shape.
	 *
	 * @param mixed $raw Raw filters from the decision.
	 * @return array
	 */
	public static function normalize( $raw ) {
		$out = array(
			'price_min'  => null,
			'price_max'  => null,
			'attributes' => array(),
			'brand'      => '',
			'in_stock'   => false,
			'category'   => '',
			'sort'       => 'relevance',
			'free_text'  => '',
		);

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		if ( isset( $raw['price_min'] ) && is_numeric( $raw['price_min'] ) && (float) $raw['price_min'] > 0 ) {
			$out['price_min'] = (float) $raw['price_min'];
		}
		if ( isset( $raw['price_max'] ) && is_numeric( $raw['price_max'] ) && (float) $raw['price_max'] > 0 ) {
			$out['price_max'] = (float) $raw['price_max'];
		}
		if ( ! empty( $raw['in_stock'] ) ) {
			$out['in_stock'] = true;
		}
		if ( isset( $raw['brand'] ) ) {
			$out['brand'] = sanitize_text_field( (string) $raw['brand'] );
		}
		if ( isset( $raw['category'] ) ) {
			$out['category'] = sanitize_text_field( (string) $raw['category'] );
		}
		if ( isset( $raw['free_text'] ) ) {
			$out['free_text'] = sanitize_text_field( (string) $raw['free_text'] );
		}

		$sort_allowed = array( 'relevance', 'price_asc', 'price_desc', 'newest', 'rating' );
		if ( isset( $raw['sort'] ) && in_array( $raw['sort'], $sort_allowed, true ) ) {
			$out['sort'] = $raw['sort'];
		}

		if ( isset( $raw['attributes'] ) && is_array( $raw['attributes'] ) ) {
			foreach ( $raw['attributes'] as $attr ) {
				if ( is_array( $attr ) && ! empty( $attr['name'] ) && isset( $attr['value'] ) && '' !== $attr['value'] ) {
					$out['attributes'][] = array(
						'name'  => sanitize_text_field( (string) $attr['name'] ),
						'value' => sanitize_text_field( (string) $attr['value'] ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Whether the filters include WooCommerce-queryable hard constraints.
	 *
	 * @param array $f Normalized filters.
	 * @return bool
	 */
	public static function has_query_constraints( $f ) {
		return null !== $f['price_min']
			|| null !== $f['price_max']
			|| $f['in_stock']
			|| '' !== $f['brand']
			|| '' !== $f['category']
			|| ! empty( $f['attributes'] );
	}

	/**
	 * Whether any actionable filter (including a non-default sort) is present.
	 *
	 * @param array $f Normalized filters.
	 * @return bool
	 */
	public static function is_active( $f ) {
		return self::has_query_constraints( $f ) || ( 'relevance' !== $f['sort'] && '' !== $f['sort'] );
	}

	/**
	 * Candidate product IDs matching the hard constraints (null = no constraints → all).
	 *
	 * @param array $f Normalized filters.
	 * @return array|null
	 */
	public static function query_ids( $f ) {
		if ( ! self::has_query_constraints( $f ) ) {
			return null;
		}

		$args = self::build_query_args( $f );

		// If no constraint clause actually resolved (e.g. an unknown category or
		// brand that maps to no real term), treat it as no hard filter so the
		// caller falls back to the pure-semantic path.
		if ( empty( $args['tax_query'] ) && empty( $args['meta_query'] ) ) {
			return null;
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Progressively relaxes constraints until some products match.
	 *
	 * @param array $f Normalized filters.
	 * @return array [ candidate_ids|null, skipped_filters[] ].
	 */
	public static function query_ids_relaxed( $f ) {
		$order = array( 'in_stock', 'attributes', 'brand', 'price', 'category' );
		$skip  = array();

		foreach ( $order as $drop ) {
			$skip[] = $drop;

			if ( ! self::has_query_constraints( self::without( $f, $skip ) ) ) {
				break;
			}

			$query = new WP_Query( self::build_query_args( $f, $skip ) );
			$ids   = array_map( 'intval', $query->posts );

			if ( ! empty( $ids ) ) {
				return array( $ids, $skip );
			}
		}

		return array( null, $skip );
	}

	/**
	 * Returns a copy of the filters with the skipped keys cleared (for relaxation checks).
	 *
	 * @param array $f    Filters.
	 * @param array $skip Keys to clear.
	 * @return array
	 */
	private static function without( $f, $skip ) {
		if ( in_array( 'price', $skip, true ) ) {
			$f['price_min'] = null;
			$f['price_max'] = null;
		}
		if ( in_array( 'in_stock', $skip, true ) ) {
			$f['in_stock'] = false;
		}
		if ( in_array( 'brand', $skip, true ) ) {
			$f['brand'] = '';
		}
		if ( in_array( 'category', $skip, true ) ) {
			$f['category'] = '';
		}
		if ( in_array( 'attributes', $skip, true ) ) {
			$f['attributes'] = array();
		}

		return $f;
	}

	/**
	 * Builds WP_Query args from filters, skipping the given constraint groups.
	 *
	 * @param array $f    Normalized filters.
	 * @param array $skip Constraint groups to omit.
	 * @return array
	 */
	private static function build_query_args( $f, $skip = array() ) {
		$tax_query  = array( 'relation' => 'AND' );
		$meta_query = array( 'relation' => 'AND' );

		if ( ! in_array( 'category', $skip, true ) && '' !== $f['category'] ) {
			$term_id = Tuki_Cart::resolve_category( $f['category'] );
			if ( $term_id ) {
				$tax_query[] = array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $term_id,
				);
			}
		}

		if ( ! in_array( 'brand', $skip, true ) && '' !== $f['brand'] ) {
			$brand_tax = self::brand_taxonomy();
			if ( $brand_tax ) {
				$term_id = self::find_term( $f['brand'], $brand_tax );
				if ( $term_id ) {
					$tax_query[] = array(
						'taxonomy' => $brand_tax,
						'field'    => 'term_id',
						'terms'    => $term_id,
					);
				}
			}
		}

		if ( ! in_array( 'attributes', $skip, true ) && ! empty( $f['attributes'] ) ) {
			foreach ( $f['attributes'] as $attr ) {
				$taxonomy = self::attribute_taxonomy( $attr['name'] );
				if ( $taxonomy ) {
					$term_id = self::find_term( $attr['value'], $taxonomy );
					if ( $term_id ) {
						$tax_query[] = array(
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => $term_id,
						);
					}
				}
			}
		}

		if ( ! in_array( 'price', $skip, true ) && ( null !== $f['price_min'] || null !== $f['price_max'] ) ) {
			$price = array(
				'key'  => '_price',
				'type' => 'NUMERIC',
			);
			if ( null !== $f['price_min'] && null !== $f['price_max'] ) {
				$price['value']   = array( $f['price_min'], $f['price_max'] );
				$price['compare'] = 'BETWEEN';
			} elseif ( null !== $f['price_max'] ) {
				$price['value']   = $f['price_max'];
				$price['compare'] = '<=';
			} else {
				$price['value']   = $f['price_min'];
				$price['compare'] = '>=';
			}
			$meta_query[] = $price;
		}

		if ( ! in_array( 'in_stock', $skip, true ) && $f['in_stock'] ) {
			$meta_query[] = array(
				'key'   => '_stock_status',
				'value' => 'instock',
			);
		}

		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'fields'              => 'ids',
			'posts_per_page'      => self::MAX_CANDIDATES,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		switch ( $f['sort'] ) {
			case 'price_asc':
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				break;
			case 'price_desc':
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			case 'newest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'rating':
				$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
		}

		return $args;
	}

	/**
	 * Finds the store's brand taxonomy, if any.
	 *
	 * @return string Taxonomy name, or '' if none.
	 */
	private static function brand_taxonomy() {
		foreach ( array( 'product_brand', 'pa_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return '';
	}

	/**
	 * Resolves a filter attribute name to an existing global attribute taxonomy.
	 *
	 * @param string $name Attribute name (e.g. "color").
	 * @return string Taxonomy (e.g. "pa_color"), or '' if it does not exist.
	 */
	private static function attribute_taxonomy( $name ) {
		$slug = sanitize_title( $name );

		if ( '' === $slug ) {
			return '';
		}

		if ( taxonomy_exists( 'pa_' . $slug ) ) {
			return 'pa_' . $slug;
		}

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				if ( sanitize_title( $attribute->attribute_name ) === $slug || sanitize_title( $attribute->attribute_label ) === $slug ) {
					$taxonomy = 'pa_' . $attribute->attribute_name;
					if ( taxonomy_exists( $taxonomy ) ) {
						return $taxonomy;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Finds a term id in a taxonomy by slug, name, or fuzzy match.
	 *
	 * @param string $value    Term value.
	 * @param string $taxonomy Taxonomy.
	 * @return int Term id, or 0.
	 */
	private static function find_term( $value, $taxonomy ) {
		$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );

		if ( ! $term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}

		if ( ! $term ) {
			$matches = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'name__like' => $value,
					'hide_empty' => false,
					'number'     => 1,
				)
			);

			if ( ! is_wp_error( $matches ) && ! empty( $matches ) ) {
				$term = $matches[0];
			}
		}

		return $term ? (int) $term->term_id : 0;
	}
}
