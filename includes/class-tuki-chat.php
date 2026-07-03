<?php
/**
 * RAG chat.
 *
 * Retrieves real products, builds a grounded prompt, calls the chat provider,
 * and returns the reply plus product cards. The model may only recommend from
 * the retrieved products — it never invents catalog items.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces grounded assistant replies.
 */
class Tuki_Chat {

	/**
	 * Runs the retrieval-augmented chat pipeline.
	 *
	 * @param string $message Shopper's latest message.
	 * @param array  $history Prior messages: [ [ 'role' => 'user|assistant', 'content' => '...' ], ... ].
	 * @return array{reply:string,products:array}
	 * @throws Exception If retrieval or the chat call fails.
	 */
	public function respond( $message, array $history = array(), $clarify_count = 0, array $context_ids = array() ) {
		$message = trim( (string) $message );
		$count   = (int) Tuki_Settings::get( 'retrieval_count' );

		$may_clarify = Tuki_Settings::get( 'clarify_enabled' ) && (int) $clarify_count < (int) Tuki_Settings::get( 'clarify_max' );

		$search  = new Tuki_Search();
		$results = $search->query( $message, $count );

		Tuki_Analytics::record_query( $message, $results );

		// Collect valid, published products with their similarity scores, ranked.
		$scored = $this->scored_products( $results );

		// Split on the relevance threshold. Above-threshold products are real
		// matches the model may recommend; if none clear the bar we fall back to
		// a "zero-result rescue" that offers the closest alternatives instead.
		$threshold = (float) Tuki_Settings::get( 'relevance_threshold' );

		$above = array_values(
			array_filter(
				$scored,
				static function ( $item ) use ( $threshold ) {
					return $item['score'] >= $threshold;
				}
			)
		);

		$rescue = empty( $above );
		$pool   = $rescue ? array_slice( $scored, 0, 3 ) : $above;

		$retrieved     = array();
		$context_lines = array();
		$index         = 0;

		foreach ( $pool as $item ) {
			$product = $item['product'];
			$index++;
			$retrieved[ $product->get_id() ] = $product;
			$context_lines[]                 = $this->product_line( $index, $product );
		}

		// Add recently-shown products (from the widget) so the model can resolve
		// references like "these" — needed for comparisons and follow-up questions.
		foreach ( array_slice( $context_ids, 0, 6 ) as $context_id ) {
			$context_id = absint( $context_id );

			if ( ! $context_id || isset( $retrieved[ $context_id ] ) || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}

			$product = wc_get_product( $context_id );

			if ( $product && 'publish' === $product->get_status() ) {
				$index++;
				$retrieved[ $context_id ] = $product;
				$context_lines[]          = $this->product_line( $index, $product );
			}
		}

		// Feature 4: retrieve store policy/FAQ snippets so the model can route and
		// answer policy questions from real content (grounded).
		$kb_available = Tuki_KB::is_available();
		$kb_snippets  = array();

		if ( $kb_available ) {
			$kb          = new Tuki_KB();
			$kb_snippets = $kb->search( $message, 3 );
		}

		$provider = Tuki_Settings::make_provider();

		if ( ! $provider ) {
			throw new Exception( esc_html__( 'No chat provider is configured.', 'tukify' ) );
		}

		$system   = $this->build_system_prompt( $context_lines, $rescue, $may_clarify, $kb_snippets, $kb_available );
		$messages = $this->build_messages( $history, $message );
		$raw      = (string) $provider->chat(
			$messages,
			array(
				'system'          => $system,
				'response_schema' => $this->decision_schema(),
			)
		);

		$decision = $this->parse_decision( $raw );
		$reply    = trim( $decision['reply'] );

		if ( '' === $reply ) {
			$reply = __( 'Sorry, I could not generate a reply just now. Please try again.', 'tukify' );
		}

		// Feature 1: if the request is too vague, ask one clarifying question
		// (with tappable quick replies) instead of dumping mediocre products.
		if ( $may_clarify && 'clarify' === $decision['intent'] && '' !== $reply ) {
			Tuki_Analytics::record_event( 'clarification_shown' );

			return array(
				'reply'         => $reply,
				'products'      => array(),
				'quick_replies' => $decision['quick_replies'],
				'intent'        => 'clarify',
			);
		}

		// Order status: hand off to the secure server-side lookup. The model never
		// sees or invents order data — it only asks for the order number and
		// billing email; verification + retrieval happen in the /order-status
		// endpoint. The widget renders the lookup form from this flag.
		if ( 'order_status' === $decision['intent'] ) {
			Tuki_Analytics::record_event( 'order_status_requested' );

			return array(
				'reply'      => $reply,
				'products'   => array(),
				'intent'     => 'order_status',
				'order_form' => array( 'logged_in' => is_user_logged_in() ),
			);
		}

		// Feature 4 + site-wide RAG: answer store policy/FAQ questions and general
		// informational questions about the site from the knowledge base, grounded.
		if ( 'policy' === $decision['intent'] || ( 'info' === $decision['intent'] && $kb_available ) ) {
			return $this->kb_response( $reply, $kb_snippets, $decision['intent'] );
		}

		// Feature 3: side-by-side comparison of 2-3 products.
		if ( 'compare' === $decision['intent'] ) {
			return $this->compare_response( $decision, $reply );
		}

		// Feature 2: when the message carries structured constraints (price, colour,
		// brand, stock, category, sort), apply them as real WooCommerce queries and
		// rank the filtered set semantically (hybrid).
		$filters = Tuki_Filters::normalize( $decision['filters'] );

		if ( Tuki_Settings::get( 'hybrid_filtering' ) && Tuki_Filters::is_active( $filters ) ) {
			return $this->filtered_response( $message, $filters, $reply );
		}

		// Browse intents list the catalog directly from WooCommerce, paginated —
		// never capped at the semantic top-N.
		if ( ! $rescue && 'browse_all' === $decision['intent'] ) {
			$batch  = $this->browse_batch_size();
			$browse = Tuki_Cart::browse(
				array(
					'mode'     => 'all',
					'page'     => 1,
					'per_page' => $batch,
				)
			);

			return array(
				'reply'    => $reply,
				'products' => $browse['products'],
				'intent'   => 'browse_all',
				'browse'   => $this->browse_meta( $browse, 'all', 0 ),
			);
		}

		if ( ! $rescue && 'category_browse' === $decision['intent'] ) {
			$term_id = Tuki_Cart::resolve_category( '' !== $decision['category'] ? $decision['category'] : $message );

			if ( $term_id ) {
				$batch  = $this->browse_batch_size();
				$browse = Tuki_Cart::browse(
					array(
						'mode'     => 'category',
						'category' => $term_id,
						'page'     => 1,
						'per_page' => $batch,
					)
				);

				if ( ! empty( $browse['products'] ) ) {
					return array(
						'reply'    => $reply,
						'products' => $browse['products'],
						'intent'   => 'category_browse',
						'browse'   => $this->browse_meta( $browse, 'category', $term_id ),
					);
				}
			}
			// No matching category/products — fall through to the semantic display below.
		}

		if ( $rescue ) {
			// No confident match: always surface the closest alternatives.
			$display_ids = array_keys( $retrieved );
		} else {
			// Grounding: only ever display above-threshold retrieved products.
			$show_ids = array_values(
				array_filter(
					$decision['show_product_ids'],
					static function ( $id ) use ( $retrieved ) {
						return isset( $retrieved[ $id ] );
					}
				)
			);

			if ( 'info' === $decision['intent'] ) {
				// Info questions: at most the single product being discussed.
				$display_ids = array_slice( $show_ids, 0, 1 );
			} elseif ( ! empty( $show_ids ) ) {
				$display_ids = $show_ids;
			} else {
				// Browse intent but the model named nothing specific: show all matches.
				$display_ids = array_keys( $retrieved );
			}
		}

		$products = array();

		foreach ( $display_ids as $id ) {
			if ( isset( $retrieved[ $id ] ) ) {
				$products[] = Tuki_Cart::product_card( $retrieved[ $id ] );
			}
		}

		return array(
			'reply'    => $reply,
			'products' => $products,
			'intent'   => $rescue ? 'zero_result' : $decision['intent'],
		);
	}

	/**
	 * Configured browse batch size (clamped).
	 *
	 * @return int
	 */
	private function browse_batch_size() {
		return min( 24, max( 4, (int) Tuki_Settings::get( 'browse_batch_size' ) ) );
	}

	/**
	 * Shapes browse pagination metadata for the client "Show more" control.
	 *
	 * @param array  $browse   Result from Tuki_Cart::browse().
	 * @param string $mode     'all' or 'category'.
	 * @param int    $category Category term id (0 for all).
	 * @return array
	 */
	private function browse_meta( $browse, $mode, $category ) {
		return array(
			'mode'     => $mode,
			'category' => (int) $category,
			'page'     => (int) $browse['page'],
			'has_more' => (bool) $browse['has_more'],
			'total'    => (int) $browse['total'],
		);
	}

	/**
	 * Structured-output schema for the model's decision.
	 *
	 * @return array
	 */
	private function decision_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'reply'                   => array( 'type' => 'STRING' ),
				'intent'                  => array(
					'type' => 'STRING',
					'enum' => array( 'clarify', 'policy', 'compare', 'browse', 'category_browse', 'browse_all', 'info', 'order_status' ),
				),
				'category'                => array( 'type' => 'STRING' ),
				'show_product_ids'        => array(
					'type'  => 'ARRAY',
					'items' => array( 'type' => 'INTEGER' ),
				),
				'suggested_quick_replies' => array(
					'type'  => 'ARRAY',
					'items' => array( 'type' => 'STRING' ),
				),
				'filters'                 => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'price_min'  => array( 'type' => 'NUMBER' ),
						'price_max'  => array( 'type' => 'NUMBER' ),
						'in_stock'   => array( 'type' => 'BOOLEAN' ),
						'brand'      => array( 'type' => 'STRING' ),
						'category'   => array( 'type' => 'STRING' ),
						'sort'       => array(
							'type' => 'STRING',
							'enum' => array( 'relevance', 'price_asc', 'price_desc', 'newest', 'rating' ),
						),
						'free_text'  => array( 'type' => 'STRING' ),
						'attributes' => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'name'  => array( 'type' => 'STRING' ),
									'value' => array( 'type' => 'STRING' ),
								),
							),
						),
					),
				),
			),
			'required'   => array( 'reply', 'intent' ),
		);
	}

	/**
	 * Parses the model's JSON decision, with a safe fallback.
	 *
	 * @param string $raw Raw model output (expected JSON).
	 * @return array{reply:string,intent:string,show_product_ids:int[]}
	 */
	private function parse_decision( $raw ) {
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', $raw, $match ) ) {
			$data = json_decode( $match[0], true );
		}

		if ( ! is_array( $data ) ) {
			// Non-JSON fallback: treat the text as the reply and show retrieved products.
			return array(
				'reply'            => (string) $raw,
				'intent'           => 'browse',
				'category'         => '',
				'show_product_ids' => array(),
				'quick_replies'    => array(),
				'filters'          => array(),
			);
		}

		$intent  = isset( $data['intent'] ) ? (string) $data['intent'] : 'browse';
		$allowed = array( 'clarify', 'policy', 'compare', 'browse', 'category_browse', 'browse_all', 'info', 'order_status' );

		if ( ! in_array( $intent, $allowed, true ) ) {
			$intent = 'browse';
		}

		$ids = array();

		if ( isset( $data['show_product_ids'] ) && is_array( $data['show_product_ids'] ) ) {
			foreach ( $data['show_product_ids'] as $id ) {
				$ids[] = absint( $id );
			}
		}

		$quick_replies = array();

		if ( isset( $data['suggested_quick_replies'] ) && is_array( $data['suggested_quick_replies'] ) ) {
			foreach ( $data['suggested_quick_replies'] as $chip ) {
				$chip = trim( sanitize_text_field( (string) $chip ) );

				if ( '' !== $chip ) {
					$quick_replies[] = $chip;
				}

				if ( count( $quick_replies ) >= 4 ) {
					break;
				}
			}
		}

		return array(
			'reply'            => isset( $data['reply'] ) ? (string) $data['reply'] : '',
			'intent'           => $intent,
			'category'         => isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : '',
			'show_product_ids' => $ids,
			'quick_replies'    => $quick_replies,
			'filters'          => isset( $data['filters'] ) && is_array( $data['filters'] ) ? $data['filters'] : array(),
		);
	}

	/**
	 * Hybrid filtered response: hard WooCommerce constraints + semantic ranking.
	 *
	 * @param string $message Shopper message.
	 * @param array  $filters Normalized filters.
	 * @param string $reply   Model reply (used when no relaxation was needed).
	 * @return array{reply:string,products:array,intent:string}
	 * @throws Exception If retrieval fails.
	 */
	private function filtered_response( $message, $filters, $reply ) {
		Tuki_Analytics::record_event( 'filter_applied' );

		$count     = (int) Tuki_Settings::get( 'retrieval_count' );
		$threshold = (float) Tuki_Settings::get( 'relevance_threshold' );
		$core      = '' !== $filters['free_text'] ? $filters['free_text'] : $message;

		// Candidate set from the hard constraints (null = no constraints → all products).
		$candidates = Tuki_Filters::query_ids( $filters );
		$relaxed    = false;

		if ( is_array( $candidates ) && empty( $candidates ) ) {
			list( $candidates, $skipped ) = Tuki_Filters::query_ids_relaxed( $filters );
			$relaxed                      = ! empty( $skipped );
		}

		$search  = new Tuki_Search();
		$results = $search->query( $core, max( $count, 50 ), is_array( $candidates ) ? $candidates : null );
		$scored  = $this->scored_products( $results );

		$above = array_values(
			array_filter(
				$scored,
				static function ( $item ) use ( $threshold ) {
					return $item['score'] >= $threshold;
				}
			)
		);

		if ( is_array( $candidates ) ) {
			// Hard-filtered candidates are already precise; if the threshold removes
			// everything, still show the filtered set ranked by similarity.
			$pool = ! empty( $above ) ? $above : $scored;
		} else {
			// Sort-only over the whole catalog (e.g. "cheapest speaker"): gather the
			// relevant type-cluster. Use the strict threshold on well-separated
			// catalogs, but relax to a band just below the top score when scores are
			// compressed, so sorting has the full cluster without pulling in
			// clearly-unrelated products.
			$top   = isset( $scored[0]['score'] ) ? (float) $scored[0]['score'] : 0.0;
			$floor = min( $threshold, $top - 0.05 );
			$pool  = array_values(
				array_filter(
					$scored,
					static function ( $item ) use ( $floor ) {
						return $item['score'] >= $floor;
					}
				)
			);

			if ( empty( $pool ) ) {
				$pool = $above;
			}

			$pool = array_slice( $pool, 0, 24 );
		}

		// Nothing at all — fall back to the closest few overall.
		if ( empty( $pool ) ) {
			$pool    = array_slice( $this->scored_products( $search->query( $core, 3 ) ), 0, 3 );
			$relaxed = true;
		}

		$pool = $this->apply_sort( $pool, $filters['sort'] );
		$pool = array_slice( $pool, 0, $count );

		$products = array();
		foreach ( $pool as $item ) {
			$products[] = Tuki_Cart::product_card( $item['product'] );
		}

		if ( $relaxed || '' === trim( $reply ) ) {
			$reply = __( 'I couldn\'t find an exact match for all of that, so here are the closest options.', 'tukify' );
		}

		return array(
			'reply'    => $reply,
			'products' => $products,
			'intent'   => 'filter',
		);
	}

	/**
	 * Sorts a scored pool by a hard sort key, or leaves semantic order for relevance.
	 *
	 * @param array  $pool Scored items [ 'product' => WC_Product, 'score' => float ].
	 * @param string $sort Sort key.
	 * @return array
	 */
	private function apply_sort( $pool, $sort ) {
		switch ( $sort ) {
			case 'price_asc':
				usort(
					$pool,
					static function ( $a, $b ) {
						return (float) $a['product']->get_price() <=> (float) $b['product']->get_price();
					}
				);
				break;
			case 'price_desc':
				usort(
					$pool,
					static function ( $a, $b ) {
						return (float) $b['product']->get_price() <=> (float) $a['product']->get_price();
					}
				);
				break;
			case 'newest':
				usort(
					$pool,
					static function ( $a, $b ) {
						$da = $a['product']->get_date_created() ? $a['product']->get_date_created()->getTimestamp() : 0;
						$db = $b['product']->get_date_created() ? $b['product']->get_date_created()->getTimestamp() : 0;
						return $db <=> $da;
					}
				);
				break;
			case 'rating':
				usort(
					$pool,
					static function ( $a, $b ) {
						return (float) $b['product']->get_average_rating() <=> (float) $a['product']->get_average_rating();
					}
				);
				break;
		}

		return $pool;
	}

	/**
	 * Answers a grounded knowledge-base question (policy/FAQ or general site
	 * info), attaching a source link to the most relevant snippet when available.
	 *
	 * @param string $reply       Model reply (grounded in the KB context).
	 * @param array  $kb_snippets Retrieved KB snippets (ranked).
	 * @param string $intent      'policy' or 'info'.
	 * @return array{reply:string,products:array,intent:string,source?:array}
	 */
	private function kb_response( $reply, $kb_snippets, $intent = 'policy' ) {
		$intent = ( 'info' === $intent ) ? 'info' : 'policy';

		Tuki_Analytics::record_event( 'policy' === $intent ? 'policy_answered' : 'info_answered' );

		$source = null;

		foreach ( $kb_snippets as $snippet ) {
			if ( ! empty( $snippet['url'] ) && (float) $snippet['score'] >= 0.3 ) {
				$source = array(
					'title' => $snippet['title'],
					'url'   => $snippet['url'],
				);
				break;
			}
		}

		return array(
			'reply'    => $reply,
			'products' => array(),
			'intent'   => $intent,
			'source'   => $source,
		);
	}

	/**
	 * Builds a side-by-side comparison of the products named in the decision.
	 *
	 * @param array  $decision Parsed decision (show_product_ids holds the targets).
	 * @param string $reply    Model's one-line trade-off summary.
	 * @return array{reply:string,products:array,intent:string,comparison?:array}
	 */
	private function compare_response( $decision, $reply ) {
		$ids      = array_slice( array_values( array_unique( array_map( 'absint', $decision['show_product_ids'] ) ) ), 0, 3 );
		$products = array();

		foreach ( $ids as $id ) {
			$product = ( $id && function_exists( 'wc_get_product' ) ) ? wc_get_product( $id ) : null;

			if ( $product && 'publish' === $product->get_status() ) {
				$products[] = $product;
			}
		}

		// Need at least two real products to compare; otherwise just show them.
		if ( count( $products ) < 2 ) {
			$cards = array();
			foreach ( $products as $product ) {
				$cards[] = Tuki_Cart::product_card( $product );
			}

			return array(
				'reply'    => $reply,
				'products' => $cards,
				'intent'   => 'browse',
			);
		}

		Tuki_Analytics::record_event( 'comparison_shown' );

		$comparison = $this->build_comparison( $products );

		return array(
			'reply'      => $reply,
			'products'   => $comparison['products'],
			'comparison' => $comparison,
			'intent'     => 'compare',
		);
	}

	/**
	 * Assembles the comparison payload: product columns + dimension rows.
	 *
	 * Universal rows (price, rating, availability) always appear; attribute rows
	 * are limited to attributes the products share so columns line up.
	 *
	 * @param array $products WC_Product objects (2-3).
	 * @return array{products:array,rows:array}
	 */
	private function build_comparison( $products ) {
		$cards      = array();
		$attr_maps  = array();

		foreach ( $products as $product ) {
			$cards[]     = Tuki_Cart::product_card( $product );
			$attr_maps[] = $this->product_attributes( $product );
		}

		$rows = array();

		$prices = array();
		$ratings = array();
		$stocks  = array();

		foreach ( $products as $product ) {
			$prices[]  = $this->price_text( $product );
			$ratings[] = $this->rating_text( $product );
			$stocks[]  = $product->is_in_stock() ? __( 'In stock', 'tukify' ) : __( 'Out of stock', 'tukify' );
		}

		$rows[] = array(
			'label'  => __( 'Price', 'tukify' ),
			'values' => $prices,
		);
		$rows[] = array(
			'label'  => __( 'Rating', 'tukify' ),
			'values' => $ratings,
		);
		$rows[] = array(
			'label'  => __( 'Availability', 'tukify' ),
			'values' => $stocks,
		);

		// Shared attributes (present on every compared product).
		$shared = null;
		foreach ( $attr_maps as $map ) {
			$keys   = array_keys( $map );
			$shared = ( null === $shared ) ? $keys : array_intersect( $shared, $keys );
		}
		$shared = $shared ? $shared : array();

		foreach ( $shared as $label ) {
			$values = array();
			foreach ( $attr_maps as $map ) {
				$values[] = isset( $map[ $label ] ) && '' !== $map[ $label ] ? $map[ $label ] : '—';
			}
			$rows[] = array(
				'label'  => $label,
				'values' => $values,
			);
		}

		return array(
			'products' => $cards,
			'rows'     => $rows,
		);
	}

	/**
	 * Returns a product's attributes as [ label => value ].
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private function product_attributes( $product ) {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_name' ) ) {
				continue;
			}

			$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();

			if ( $attribute->is_taxonomy() && function_exists( 'wc_get_product_terms' ) ) {
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
				$value = is_array( $terms ) ? implode( ', ', $terms ) : '';
			} else {
				$value = implode( ', ', (array) $attribute->get_options() );
			}

			if ( '' !== $value ) {
				$out[ $label ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Clean, formatted active price for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function price_text( $product ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $product->get_price() ) ), ENT_QUOTES );
		}

		return (string) $product->get_price();
	}

	/**
	 * Human-readable rating summary for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function rating_text( $product ) {
		$count = (int) $product->get_rating_count();

		if ( $count < 1 ) {
			return __( 'No ratings', 'tukify' );
		}

		/* translators: 1: average rating, 2: number of ratings. */
		return sprintf( __( '%1$s ★ (%2$d)', 'tukify' ), $product->get_average_rating(), $count );
	}

	/**
	 * Visual search: identify a product from an image, then match the catalog.
	 *
	 * Grounded like text chat — only real catalog products are ever shown, and
	 * the relevance threshold + zero-result rescue from Fix 2 apply.
	 *
	 * @param string $base64 Raw base64 image data.
	 * @param string $mime   Image MIME type.
	 * @return array{reply:string,products:array,intent:string}
	 * @throws Exception If the provider call fails.
	 */
	public function visual_search( $base64, $mime ) {
		$provider = Tuki_Settings::make_provider();

		if ( ! $provider || ! method_exists( $provider, 'vision' ) ) {
			throw new Exception( esc_html__( 'Image search is not available with the current provider.', 'tukify' ) );
		}

		$schema = array(
			'type'       => 'OBJECT',
			'properties' => array(
				'query' => array( 'type' => 'STRING' ),
				'reply' => array( 'type' => 'STRING' ),
			),
			'required'   => array( 'query' ),
		);

		$prompt = __( 'You are a shopping assistant. Look at the product in this image. In "query", write a short search phrase describing the product type and key visible attributes (color, material, style). In "reply", write one friendly sentence telling the shopper you will look for similar items — do NOT mention any specific store product, price, or stock.', 'tukify' );

		$raw  = (string) $provider->vision( $base64, $mime, $prompt, $schema );
		$data = json_decode( $raw, true );

		$query = is_array( $data ) && isset( $data['query'] ) ? trim( (string) $data['query'] ) : '';
		$reply = is_array( $data ) && isset( $data['reply'] ) ? trim( (string) $data['reply'] ) : '';

		if ( '' === $query ) {
			$query = trim( wp_strip_all_tags( $raw ) );
		}

		if ( '' === $query ) {
			return array(
				'reply'    => __( 'I couldn\'t tell what that image shows. Could you describe what you\'re looking for?', 'tukify' ),
				'products' => array(),
				'intent'   => 'zero_result',
			);
		}

		$count   = (int) Tuki_Settings::get( 'retrieval_count' );
		$search  = new Tuki_Search();
		$results = $search->query( $query, $count );

		Tuki_Analytics::record_query( '[image] ' . $query, $results );

		$scored    = $this->scored_products( $results );
		$threshold = (float) Tuki_Settings::get( 'relevance_threshold' );

		$above = array_values(
			array_filter(
				$scored,
				static function ( $item ) use ( $threshold ) {
					return $item['score'] >= $threshold;
				}
			)
		);

		$rescue = empty( $above );
		$pool   = $rescue ? array_slice( $scored, 0, 3 ) : $above;

		$products = array();

		foreach ( $pool as $item ) {
			$products[] = Tuki_Cart::product_card( $item['product'] );
		}

		if ( $rescue ) {
			$reply = __( 'I couldn\'t find a close match to that image, but here are the closest options.', 'tukify' );
		} elseif ( '' === $reply ) {
			$reply = __( 'Here are some similar items I found.', 'tukify' );
		}

		return array(
			'reply'    => $reply,
			'products' => $products,
			'intent'   => $rescue ? 'zero_result' : 'visual',
		);
	}

	/**
	 * Maps ranked search results to valid, published products with scores.
	 *
	 * @param array $results Ranked results with 'product_id' and 'score'.
	 * @return array List of [ 'product' => WC_Product, 'score' => float ].
	 */
	private function scored_products( array $results ) {
		$scored = array();

		foreach ( $results as $result ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $result['product_id'] ) : null;

			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$scored[] = array(
				'product' => $product,
				'score'   => isset( $result['score'] ) ? (float) $result['score'] : 0.0,
			);
		}

		return $scored;
	}

	/**
	 * Builds one CONTEXT line describing a product.
	 *
	 * @param int        $index   1-based position.
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	private function product_line( $index, $product ) {
		$price = wp_strip_all_tags( $product->get_price_html() );
		$stock = $product->is_in_stock() ? __( 'in stock', 'tukify' ) : __( 'out of stock', 'tukify' );

		$description = $product->get_short_description();

		if ( '' === trim( (string) $description ) ) {
			$description = $product->get_description();
		}

		$description = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $description ) ) );

		if ( function_exists( 'mb_substr' ) ) {
			$description = mb_substr( $description, 0, 200 );
		} else {
			$description = substr( $description, 0, 200 );
		}

		return sprintf(
			'%1$d. [id %2$d] %3$s — %4$s — %5$s.%6$s',
			$index,
			$product->get_id(),
			$product->get_name(),
			$price,
			$stock,
			'' !== $description ? ' ' . $description : ''
		);
	}

	/**
	 * Assembles the grounded system prompt.
	 *
	 * @param array $context_lines Product CONTEXT lines.
	 * @param bool  $rescue        Whether this is a zero-result rescue (no confident match).
	 * @param bool  $may_clarify   Whether a clarifying question is still allowed this turn.
	 * @param array $kb_snippets   Retrieved knowledge-base snippets.
	 * @param bool  $kb_available  Whether the knowledge base is enabled + populated.
	 * @return string
	 */
	private function build_system_prompt( array $context_lines, $rescue = false, $may_clarify = false, $kb_snippets = array(), $kb_available = false ) {
		$name  = apply_filters( 'tuki_assistant_name', __( 'Tukify', 'tukify' ) );
		$store = get_bloginfo( 'name' );

		/* translators: 1: assistant name, 2: store name. */
		$identity = sprintf( __( 'You are %1$s, a friendly shopping assistant for the store "%2$s".', 'tukify' ), $name, $store );

		// Clarification is the first decision and must be handled via the structured
		// fields (not in "reply"), so it takes priority over showing products.
		if ( $may_clarify ) {
			$clarify = array(
				__( 'STEP 1 — Decide whether the request is specific enough to return good products. Treat it as SPECIFIC (do NOT clarify) whenever the shopper names a product type, a category, a brand, a use-case, or any constraint such as price, colour, size, or material. Only set "intent" to "clarify" when the request is genuinely open-ended with none of those — for example "I need a gift", "show me something nice", or "help me shop". When you clarify, write ONE short follow-up question in "reply" and provide 2 to 4 short "suggested_quick_replies"; do not reference any products.', 'tukify' ),
				__( 'Never ask more than one clarifying question in a row: if the recent conversation already contains a clarifying question from you, do NOT use the "clarify" intent — answer with products instead.', 'tukify' ),
				__( 'STEP 2 — Otherwise, classify the intent as one of the product intents below and answer with products.', 'tukify' ),
			);
		} else {
			$clarify = array(
				__( 'Do not use the "clarify" intent; always answer with products.', 'tukify' ),
			);
		}

		$rules = array_merge(
			array( $identity ),
			$clarify,
			array(
				__( 'Recommend ONLY products listed in the CONTEXT below. Never invent, assume, or mention any product that is not in the CONTEXT.', 'tukify' ),
				__( 'Be concise, warm, and conversational. Refer to products by name.', 'tukify' ),
				__( 'Do not list prices or stock status yourself; the interface shows product cards with that information.', 'tukify' ),
				__( 'Reply in the same language the shopper used.', 'tukify' ),
				__( 'If nothing in the CONTEXT fits the request, say so briefly and suggest the closest available options.', 'tukify' ),
				// Intent classification for the structured "intent" field.
				__( 'Classify the shopper\'s latest message into one intent: "browse" = they want to see products matching a need (e.g. "show me headphones", "a gift for my dad"); "category_browse" = they ask to see all of a specific category or type (e.g. "show me all headphones"); "browse_all" = they ask to see the whole catalog (e.g. "what do you sell"); "info" = they ask a question about a specific product or a detail (e.g. "is this waterproof", "tell me about the Bose headphones").', 'tukify' ),
				// Which products to surface as cards.
				__( 'In "show_product_ids", put the CONTEXT product ids to display as cards: for browse or info follow the rule below; for category_browse or browse_all you may leave it empty because the catalog is listed separately.', 'tukify' ),
				__( 'For browse include every relevant matching product from the CONTEXT; for info include ONLY the single product being discussed, or leave it empty when the question is general and no specific product applies.', 'tukify' ),
				// Category name for category_browse.
				__( 'When the intent is category_browse, set "category" to the category or product type the shopper wants to see (e.g. "headphones", "wine racks"); otherwise leave "category" empty.', 'tukify' ),
				// Comparison (Feature 3).
				__( 'If the shopper asks to compare products or which is better (e.g. "compare the Bose and the Sony", "which of these is best for bass", "what is the difference"), set "intent" to "compare", put the 2 or 3 CONTEXT product ids to compare in "show_product_ids" (resolve references like "these" to the relevant products in the CONTEXT), and write a ONE-line trade-off summary in "reply" (e.g. "The Bose is cheaper; the Sony has richer bass"). Compare at most 3 products.', 'tukify' ),
				// Structured filters extraction (Feature 2).
				__( 'Extract any shopping constraints into the "filters" object: price_min / price_max (from "under $50", "between $20 and $40"; treat "cheap" as a modest price_max and "premium" as a higher price_min only if the shopper clearly means it), in_stock (true for "in stock" / "available now"), brand, category, and a list of attributes as {name,value} pairs for things like colour, size, or material ("red", "M", "cotton", "waterproof"). Set "sort" to price_asc for "cheapest", price_desc for "most expensive", newest for "newest", rating for "best rated", otherwise relevance. Put the core product description WITHOUT the constraints into filters.free_text. Only include a filter the shopper actually stated — never invent values, and leave the rest empty or null.', 'tukify' ),
				__( 'When the message includes such constraints, keep "reply" a short, friendly lead-in and do not name specific products (the interface shows the filtered results).', 'tukify' ),
			)
		);

		// Only steer toward a rescue answer when we are NOT going to clarify; for a
		// vague low-match request, clarifying wins.
		if ( $rescue && ! $may_clarify ) {
			$rules[] = __( 'IMPORTANT: no product in the catalog closely matches this request. The CONTEXT below lists only the CLOSEST available alternatives. Warmly acknowledge that you could not find an exact match, then gently suggest these closest options. Do not pretend they are an exact match.', 'tukify' );
		}

		$rules[] = __( 'If the shopper asks about the status of an order they already placed — tracking, delivery, "where is my order", "has my order shipped" — set "intent" to "order_status". In "reply", briefly ask for their order number and the billing email used on the order, and mention the email is not needed if the order is on their logged-in account. NEVER state, guess, or invent an order\'s status, tracking, dates, or contents yourself: a secure lookup handles that after they provide details. Do not use this intent for general shipping-policy questions (those are "policy").', 'tukify' );

		if ( $kb_available ) {
			$rules[] = __( 'A KNOWLEDGE BASE of the store\'s real content is provided below — it contains policy/FAQ text plus general content from across the site (pages, posts, and product information). If the shopper asks about shipping, delivery, returns, refunds, exchanges, warranty, orders or tracking, payment, or other store policy/support topics, set "intent" to "policy" and answer using ONLY the KNOWLEDGE BASE with the specific relevant details — do not show products. If the KNOWLEDGE BASE does not contain the answer, still set "intent" to "policy" but say you could not find that specific information and suggest contacting support. For other general informational questions about the store or its content that are not product-shopping requests, set "intent" to "info" and answer using ONLY the KNOWLEDGE BASE. Never invent facts that are not present in the KNOWLEDGE BASE: if the answer is not there, say you do not have that information. Never use the "policy" or "info" intent for product requests.', 'tukify' );
		}

		if ( empty( $context_lines ) ) {
			$context = __( 'No products matched this query.', 'tukify' );
		} else {
			$context = implode( "\n", $context_lines );
		}

		$prompt = implode( ' ', $rules ) . "\n\n" . __( 'CONTEXT (available products):', 'tukify' ) . "\n" . $context;

		if ( $kb_available ) {
			if ( ! empty( $kb_snippets ) ) {
				$kb_lines = array();
				$number   = 0;
				foreach ( $kb_snippets as $snippet ) {
					$number++;
					$kb_lines[] = $number . '. ' . $snippet['title'] . ': ' . $snippet['content'];
				}
				$kb_text = implode( "\n", $kb_lines );
			} else {
				$kb_text = __( 'No matching knowledge-base content found.', 'tukify' );
			}

			$prompt .= "\n\n" . __( 'KNOWLEDGE BASE (store content — policies, pages, posts & products):', 'tukify' ) . "\n" . $kb_text;
		}

		return $prompt;
	}

	/**
	 * Builds the message list: sanitized history plus the current message.
	 *
	 * @param array  $history Prior messages.
	 * @param string $message Current shopper message.
	 * @return array
	 */
	private function build_messages( array $history, $message ) {
		$messages = array();

		foreach ( $history as $item ) {
			if ( ! is_array( $item ) || empty( $item['content'] ) ) {
				continue;
			}

			$role = ( isset( $item['role'] ) && 'assistant' === $item['role'] ) ? 'assistant' : 'user';

			$messages[] = array(
				'role'    => $role,
				'content' => (string) $item['content'],
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		return $messages;
	}
}
