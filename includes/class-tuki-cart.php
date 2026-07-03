<?php
/**
 * Cart context + product-card helpers.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats products for the frontend and handles add-to-cart.
 */
class Tuki_Cart {

	/**
	 * Builds a product-card payload for a WooCommerce product.
	 *
	 * @param WC_Product $product Product object.
	 * @return array|null Card data, or null if the product is missing.
	 */
	public static function product_card( $product ) {
		if ( ! $product ) {
			return null;
		}

		$image_id = $product->get_image_id();

		if ( $image_id ) {
			$image = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
		} else {
			$image = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '';
		}

		$in_stock = $product->is_in_stock();

		// Purchasable ceiling for the quantity stepper. Only cap when the product
		// actually manages stock and backorders are off; otherwise leave it open
		// (null) so the stepper has no upper limit. The REST handler re-checks
		// stock server-side, so this is only a UX hint, never the enforcement.
		$max_qty = null;

		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$stock_qty = $product->get_stock_quantity();

			if ( null !== $stock_qty ) {
				$max_qty = max( 1, (int) $stock_qty );
			}
		}

		return array(
			'id'           => $product->get_id(),
			'title'        => $product->get_name(),
			'price'        => wp_strip_all_tags( $product->get_price_html() ),
			'price_raw'    => (float) $product->get_price(),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'stock'        => $in_stock,
			'stock_status' => $product->get_stock_status(),
			'max_qty'      => $max_qty,
			'add_to_cart'  => $product->is_purchasable() && $in_stock,
			'image'        => $image ? $image : '',
			'url'          => get_permalink( $product->get_id() ),
		);
	}

	/**
	 * Maps ranked search results to product cards, skipping missing/unpublished items.
	 *
	 * @param array $results List of [ 'product_id' => int, 'score' => float ].
	 * @return array
	 */
	public static function cards_from_results( array $results ) {
		$cards = array();

		foreach ( $results as $result ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $result['product_id'] ) : null;

			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$card = self::product_card( $product );

			if ( isset( $result['score'] ) ) {
				$card['score'] = round( (float) $result['score'], 4 );
			}

			$cards[] = $card;
		}

		return $cards;
	}

	/**
	 * Paginated catalog browse — queries WooCommerce directly (not the vector index).
	 *
	 * Used for "show all" / category browse intents so results are never capped at
	 * the semantic top-N and never dump the whole catalog at once.
	 *
	 * @param array $args mode ('all'|'category'), category (term id), page, per_page.
	 * @return array{products:array,total:int,page:int,per_page:int,has_more:bool}
	 */
	public static function browse( array $args ) {
		$mode     = isset( $args['mode'] ) ? $args['mode'] : 'all';
		$per_page = max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 10 ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$query_args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'menu_order title',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
		);

		if ( 'category' === $mode && ! empty( $args['category'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => (int) $args['category'],
				),
			);
		}

		$query = new WP_Query( $query_args );
		$cards = array();

		foreach ( $query->posts as $post ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;

			if ( $product ) {
				$cards[] = self::product_card( $product );
			}
		}

		$total = (int) $query->found_posts;
		wp_reset_postdata();

		return array(
			'products' => $cards,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => ( $page * $per_page ) < $total,
		);
	}

	/**
	 * Cart-aware recommendations (no AI — WooCommerce-native, safe on page load).
	 *
	 * Priority: cart cross-sells → related to a product context → a category
	 * context → popular items. Cart items and the context product are excluded,
	 * and only in-stock published products are returned.
	 *
	 * @param array $context product_id and/or category_id of the current page.
	 * @param int   $count   Maximum cards to return.
	 * @return array Product cards.
	 */
	public static function recommendations( array $context, $count ) {
		$count   = max( 1, (int) $count );
		$exclude = array();
		$ids     = array();

		// 1) Cart cross-sells (complementary items).
		if ( self::ensure_cart_loaded() && WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$exclude[] = (int) $item['product_id'];
			}
			$ids = array_map( 'intval', (array) WC()->cart->get_cross_sells() );
		}

		// 2) Related to the current product.
		if ( empty( $ids ) && ! empty( $context['product_id'] ) ) {
			$product_id = absint( $context['product_id'] );
			$exclude[]  = $product_id;

			if ( function_exists( 'wc_get_related_products' ) ) {
				$ids = wc_get_related_products( $product_id, $count + count( $exclude ), $exclude );
			}
		}

		// 3) Products in the current category.
		if ( empty( $ids ) && ! empty( $context['category_id'] ) ) {
			$browse = self::browse(
				array(
					'mode'     => 'category',
					'category' => absint( $context['category_id'] ),
					'page'     => 1,
					'per_page' => $count + count( $exclude ),
				)
			);

			foreach ( $browse['products'] as $card ) {
				$ids[] = (int) $card['id'];
			}
		}

		// 4) Popular items as a last resort.
		if ( empty( $ids ) && function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'limit'        => $count + count( $exclude ),
					'orderby'      => 'popularity',
					'order'        => 'DESC',
					'status'       => 'publish',
					'stock_status' => 'instock',
					'return'       => 'ids',
				)
			);
		}

		$cards = array();

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			if ( in_array( $id, $exclude, true ) ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_in_stock() ) {
				continue;
			}

			$cards[] = self::product_card( $product );

			if ( count( $cards ) >= $count ) {
				break;
			}
		}

		return $cards;
	}

	/**
	 * Cart-aware upsell: genuinely complementary items (no AI call).
	 *
	 * Priority: WooCommerce cross-sells + up-sells → category/tag affinity
	 * (related products) → semantic similarity to the cart via stored embeddings.
	 * Never suggests random popular items, cart items, or out-of-stock products.
	 *
	 * @param int $count Max suggestions.
	 * @return array Product cards.
	 */
	public static function upsell( $count ) {
		if ( ! self::ensure_cart_loaded() || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}

		$count    = max( 1, (int) $count );
		$cart_ids = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$cart_ids[] = (int) $item['product_id'];
		}
		$cart_ids = array_values( array_unique( $cart_ids ) );

		// 1) Owner-defined cross-sells (cart) + up-sells (per product).
		$candidates = array_map( 'intval', (array) WC()->cart->get_cross_sells() );

		foreach ( $cart_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$candidates = array_merge( $candidates, array_map( 'intval', $product->get_upsell_ids() ) );
			}
		}

		$cards = self::cards_from_candidates( $candidates, $cart_ids, $count );

		// 2) Category/tag affinity (WooCommerce related products).
		if ( count( $cards ) < $count && function_exists( 'wc_get_related_products' ) ) {
			foreach ( $cart_ids as $product_id ) {
				$candidates = array_merge(
					$candidates,
					array_map( 'intval', wc_get_related_products( $product_id, $count + count( $cart_ids ), $cart_ids ) )
				);
			}
			$cards = self::cards_from_candidates( $candidates, $cart_ids, $count );
		}

		// 3) Semantic similarity to the cart (stored embeddings, no AI call).
		if ( count( $cards ) < $count ) {
			$candidates = array_merge( $candidates, self::semantic_complements( $cart_ids, $count * 3 ) );
			$cards      = self::cards_from_candidates( $candidates, $cart_ids, $count );
		}

		return $cards;
	}

	/**
	 * Builds cards from candidate IDs, excluding cart items and out-of-stock/dupes.
	 *
	 * @param array $ids      Candidate product IDs (in priority order).
	 * @param array $cart_ids Cart product IDs to exclude.
	 * @param int   $count    Max cards.
	 * @return array
	 */
	private static function cards_from_candidates( $ids, $cart_ids, $count ) {
		$cart_set = array_flip( array_map( 'intval', $cart_ids ) );
		$seen     = array();
		$cards    = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( $id <= 0 || isset( $cart_set[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_in_stock() ) {
				continue;
			}

			$cards[] = self::product_card( $product );

			if ( count( $cards ) >= $count ) {
				break;
			}
		}

		return $cards;
	}

	/**
	 * Nearest products to the cart's centroid embedding (no AI call).
	 *
	 * @param array $cart_ids Cart product IDs.
	 * @param int   $limit    Max neighbours.
	 * @return array Product IDs.
	 */
	private static function semantic_complements( $cart_ids, $limit ) {
		$vectors = Tuki_DB::embedding_vectors( $cart_ids );

		if ( empty( $vectors ) ) {
			return array();
		}

		$centroid = array();
		$n        = 0;

		foreach ( $vectors as $vector ) {
			$n++;
			foreach ( $vector as $i => $value ) {
				$centroid[ $i ] = ( isset( $centroid[ $i ] ) ? $centroid[ $i ] : 0 ) + $value;
			}
		}

		if ( 0 === $n ) {
			return array();
		}

		foreach ( $centroid as $i => $sum ) {
			$centroid[ $i ] = $sum / $n;
		}

		$backend = new Tuki_Search_MySQL();
		$results = $backend->search( $centroid, $limit + count( $cart_ids ) );

		$ids = array();
		foreach ( $results as $result ) {
			$ids[] = (int) $result['product_id'];
		}

		return $ids;
	}

	/**
	 * Resolves a free-text category/type name to a product_cat term id.
	 *
	 * @param string $name Category name/slug (e.g. "headphones").
	 * @return int Term id, or 0 if none matched.
	 */
	public static function resolve_category( $name ) {
		$name = trim( (string) $name );

		if ( '' === $name || ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}

		$term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );

		if ( ! $term ) {
			$term = get_term_by( 'name', $name, 'product_cat' );
		}

		if ( ! $term ) {
			$matches = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'name__like' => $name,
					'hide_empty' => true,
					'number'     => 1,
				)
			);

			if ( ! is_wp_error( $matches ) && ! empty( $matches ) ) {
				$term = $matches[0];
			}
		}

		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * Ensures the WooCommerce cart and session are loaded (needed in REST context).
	 *
	 * @return bool True if a cart is available.
	 */
	public static function ensure_cart_loaded() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		// In REST/AJAX contexts WooCommerce does not auto-load the cart. Load both
		// the session and the cart from the shopper's existing session cookie so the
		// running cart is restored (not a fresh, empty one) on every request.
		$loaded = false;

		if ( function_exists( 'wc_load_cart' ) && ( null === WC()->cart || null === WC()->session ) ) {
			wc_load_cart();
			$loaded = true;
		}

		if ( null === WC()->cart ) {
			return false;
		}

		// wc_load_cart() runs after `wp_loaded`, so WooCommerce's own
		// get_cart_from_session hook (bound to wp_loaded) never fires and the cart
		// stays empty. Force the restore so the saved cart is actually loaded —
		// without this, every add starts from an empty cart and never accumulates.
		if ( $loaded && method_exists( WC()->cart, 'get_cart_from_session' ) ) {
			WC()->cart->get_cart_from_session();
		}

		return true;
	}

	/**
	 * Writes the current cart back into the WooCommerce session and persists it.
	 *
	 * Without this, changes made during a REST request are never saved, so the
	 * cart never accumulates across requests.
	 *
	 * @return void
	 */
	public static function persist_cart() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}

		WC()->cart->calculate_totals();
		WC()->cart->set_session();

		if ( WC()->session ) {
			WC()->session->save_data();
		}

		WC()->cart->maybe_set_cart_cookies();
	}

	/**
	 * A short summary of the current cart, for cart-aware RAG context.
	 *
	 * @return array List of item names currently in the cart.
	 */
	public static function get_cart_context() {
		if ( ! self::ensure_cart_loaded() ) {
			return array();
		}

		$names = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['data'] ) && is_object( $item['data'] ) ) {
				$names[] = $item['data']->get_name();
			}
		}

		return $names;
	}
}
