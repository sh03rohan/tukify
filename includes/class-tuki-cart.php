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
	 * Converts WooCommerce price HTML into a plain-text string for the chat UI.
	 *
	 * WooCommerce price helpers (wc_price(), get_price_html(),
	 * get_formatted_order_total(), get_cart_subtotal(), …) return HTML in which
	 * the currency symbol is an HTML entity (e.g. "&#36;"). The chat frontend
	 * renders these via textContent, which does NOT decode entities, so a raw
	 * value shows as "&#36;76.00". Stripping tags alone is not enough — we must
	 * also decode the entity so the literal symbol ("$76.00") is displayed.
	 *
	 * @param string $price_html WooCommerce price HTML (already safe/escaped).
	 * @return string Plain-text price with a literal currency symbol.
	 */
	public static function price_text( $price_html ) {
		return html_entity_decode( wp_strip_all_tags( (string) $price_html ), ENT_QUOTES );
	}

	/**
	 * Live number of items in the cart (WooCommerce session — works for guests
	 * and logged-in users). Used to drive the persistent checkout bar.
	 *
	 * @return int
	 */
	public static function cart_count() {
		if ( ! self::ensure_cart_loaded() || ! WC()->cart ) {
			return 0;
		}

		return (int) WC()->cart->get_cart_contents_count();
	}

	/**
	 * Live cart total as plain text (currency symbol decoded, no markup), for the
	 * checkout bar label.
	 *
	 * @return string
	 */
	public static function cart_total_text() {
		if ( ! self::ensure_cart_loaded() || ! WC()->cart ) {
			return '';
		}

		return self::price_text( WC()->cart->get_cart_total() );
	}

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

		// Only SIMPLE products can be added straight to the cart by id. Variable,
		// grouped and external products need the shopper to pick a variation / act
		// on the product page, so those get a "view product" link instead of a
		// direct add button (a parent-id add would otherwise fail server-side).
		$purchasable   = $product->is_purchasable() && $in_stock;
		$can_add       = $purchasable && $product->is_type( 'simple' );
		$needs_options = $purchasable && ! $product->is_type( 'simple' );

		return array(
			'id'            => $product->get_id(),
			'title'         => $product->get_name(),
			'price'         => self::price_text( $product->get_price_html() ),
			'price_raw'     => (float) $product->get_price(),
			'currency'      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'stock'         => $in_stock,
			'stock_status'  => $product->get_stock_status(),
			'max_qty'       => $max_qty,
			'add_to_cart'   => $can_add,
			'needs_options' => $needs_options,
			'image'         => $image ? $image : '',
			'url'           => get_permalink( $product->get_id() ),
		);
	}

	/**
	 * Builds the fuller payload for the in-chat product detail popup.
	 *
	 * Extends the card payload with a larger image + gallery, a short description,
	 * display attributes, and — for variable products — the variation selectors and
	 * per-variation price/stock/image so the popup can update as options change.
	 * All prices go through price_text() so the currency renders correctly.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null Detail payload, or null if the product is missing/unpublished.
	 */
	public static function product_detail( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( absint( $product_id ) );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return null;
		}

		$detail = self::product_card( $product );

		if ( ! is_array( $detail ) ) {
			return null;
		}

		// Gallery: main image first, then the gallery images, at a larger size.
		$gallery   = array();
		$image_ids = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );

		foreach ( array_unique( array_filter( array_map( 'absint', $image_ids ) ) ) as $img_id ) {
			$large = wp_get_attachment_image_url( $img_id, 'large' );
			$thumb = wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' );

			if ( $large ) {
				$gallery[] = array(
					'full'  => $large,
					'thumb' => $thumb ? $thumb : $large,
				);
			}
		}

		if ( empty( $gallery ) && '' !== (string) $detail['image'] ) {
			$gallery[] = array(
				'full'  => $detail['image'],
				'thumb' => $detail['image'],
			);
		}

		$detail['gallery']     = $gallery;
		$detail['description'] = self::product_description( $product );
		$detail['type']        = $product->get_type();

		// Everything else worth showing so the popup is self-contained (no need to
		// leave for the product page): category, SKU, then the product attributes.
		// Variation attributes are excluded — they become the selectors instead.
		$detail['attributes']           = self::info_rows( $product );
		$detail['variation_attributes'] = array();
		$detail['variations']           = array();

		if ( $product->is_type( 'variable' ) ) {
			$detail['variation_attributes'] = self::variation_selectors( $product );
			$detail['variations']           = self::variations( $product );
		}

		return $detail;
	}

	/**
	 * Plain-text product description for the popup.
	 *
	 * Prefers the full (long) description so the popup shows everything, and falls
	 * back to the short description when there is no long one.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function product_description( $product ) {
		$text = (string) $product->get_description();

		if ( '' === trim( $text ) ) {
			$text = (string) $product->get_short_description();
		}

		// Resolve the content the way the storefront does — WooCommerce's own
		// filter runs do_shortcode()/wpautop(), so a description built with a
		// page builder or shortcodes becomes real text instead of stripping to
		// nothing. Turn paragraph/line breaks into newlines before removing tags so
		// the plain text keeps its structure (the popup renders it pre-wrap).
		$text = (string) apply_filters( 'woocommerce_short_description', $text );
		$text = str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = trim( preg_replace( "/[ \t]*\n{3,}/", "\n\n", $text ) );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 2000 ) {
			$text = rtrim( mb_substr( $text, 0, 1999 ) ) . '…';
		}

		return $text;
	}

	/**
	 * All the label/value info rows shown in the popup: category and SKU (so the
	 * popup is self-contained), followed by the product's own attributes. Only
	 * rows the product actually has are returned.
	 *
	 * @param WC_Product $product Product.
	 * @return array List of [ 'label' => string, 'value' => string ].
	 */
	private static function info_rows( $product ) {
		$rows = array();

		$terms = get_the_terms( $product->get_id(), 'product_cat' );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$names = array_filter( wp_list_pluck( $terms, 'name' ) );

			if ( ! empty( $names ) ) {
				$rows[] = array(
					'label' => _n( 'Category', 'Categories', count( $names ), 'tukify' ),
					'value' => implode( ', ', $names ),
				);
			}
		}

		$sku = (string) $product->get_sku();

		if ( '' !== $sku ) {
			$rows[] = array(
				'label' => __( 'SKU', 'tukify' ),
				'value' => $sku,
			);
		}

		return array_merge( $rows, self::display_attributes( $product ) );
	}

	/**
	 * Informational (non-variation) attributes for display.
	 *
	 * @param WC_Product $product Product.
	 * @return array List of [ 'label' => string, 'value' => string ].
	 */
	private static function display_attributes( $product ) {
		$out = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || $attribute->get_variation() ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}

			$values = array_filter( array_map( 'trim', (array) $values ) );

			if ( empty( $values ) ) {
				continue;
			}

			$out[] = array(
				'label' => wc_attribute_label( $attribute->get_name(), $product ),
				'value' => implode( ', ', $values ),
			);
		}

		return $out;
	}

	/**
	 * Variation selectors: one entry per variation attribute with its options.
	 *
	 * @param WC_Product_Variable $product Product.
	 * @return array List of [ 'key' => 'attribute_...', 'label' => string, 'options' => [ [value,label] ] ].
	 */
	private static function variation_selectors( $product ) {
		$out = array();

		foreach ( $product->get_variation_attributes() as $name => $options ) {
			$rows = array();

			foreach ( (array) $options as $option ) {
				$label = $option;

				if ( taxonomy_exists( $name ) ) {
					$term = get_term_by( 'slug', $option, $name );

					if ( $term ) {
						$label = $term->name;
					}
				}

				$rows[] = array(
					'value' => (string) $option,
					'label' => (string) $label,
				);
			}

			$out[] = array(
				'key'     => 'attribute_' . sanitize_title( $name ),
				'label'   => wc_attribute_label( $name, $product ),
				'options' => $rows,
			);
		}

		return $out;
	}

	/**
	 * Available variations, compacted for the popup.
	 *
	 * @param WC_Product_Variable $product Product.
	 * @return array
	 */
	private static function variations( $product ) {
		$out = array();

		foreach ( $product->get_available_variations() as $variation ) {
			$vid = isset( $variation['variation_id'] ) ? absint( $variation['variation_id'] ) : 0;

			if ( ! $vid ) {
				continue;
			}

			$vp        = wc_get_product( $vid );
			$max       = ( isset( $variation['max_qty'] ) && '' !== $variation['max_qty'] ) ? (int) $variation['max_qty'] : 0;
			$in_stock  = ! empty( $variation['is_in_stock'] );

			$out[] = array(
				'variation_id' => $vid,
				// Map of attribute_key => selected value (lowercased slug, '' = any).
				'attributes'   => isset( $variation['attributes'] ) ? array_map( 'strval', (array) $variation['attributes'] ) : array(),
				'price'        => $vp ? self::price_text( $vp->get_price_html() ) : '',
				'price_raw'    => $vp ? (float) $vp->get_price() : 0,
				'stock'        => $in_stock,
				'add_to_cart'  => ! empty( $variation['is_purchasable'] ) && $in_stock,
				'max_qty'      => $max,
				'image'        => isset( $variation['image']['src'] ) ? (string) $variation['image']['src'] : '',
			);
		}

		return $out;
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
			// A category filter genuinely needs a taxonomy query; there is no
			// non-tax_query way to browse a product_cat.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
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
			++$n;
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
