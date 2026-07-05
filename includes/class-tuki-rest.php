<?php
/**
 * REST API endpoints.
 *
 * Namespace: tukify/v1. All AI work happens here, server-side and
 * user-triggered — never on page load. Guests are IP rate-limited; logged-in
 * users authenticate via the standard REST cookie nonce.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles Tukify's REST routes.
 */
class Tuki_Rest {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'tukify/v1';

	/**
	 * Failed order-lookups from one actor before further attempts are blocked,
	 * and the window (seconds) the counter lives for. Throttles brute-forcing of
	 * order numbers.
	 */
	const ORDER_LOOKUP_MAX_FAILURES = 5;
	const ORDER_LOOKUP_WINDOW       = 900; // 15 minutes.

	/**
	 * Hooks route registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers /chat, /search, and /add-to-cart.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'message'       => array(
						'required' => true,
						'type'     => 'string',
					),
					'history'       => array(
						'required' => false,
						'type'     => 'array',
					),
					'clarify_count' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'context_ids'   => array(
						'required' => false,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'query' => array(
						'required' => true,
						'type'     => 'string',
					),
					'n'     => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/visual-search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_visual_search' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'image' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/browse',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_browse' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'mode'     => array(
						'required' => false,
						'type'     => 'string',
					),
					'category' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'page'     => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/upsell',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_upsell' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/recommendations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_recommendations' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'product_id'  => array(
						'required' => false,
						'type'     => 'integer',
					),
					'category_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'count'       => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'type'       => array(
						'required' => true,
						'type'     => 'string',
					),
					'product_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/add-to-cart',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_add_to_cart' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'product_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'quantity'   => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/order-status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_order_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'order_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'email'    => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/size-advice',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_size_advice' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'product_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'height'     => array(
						'required' => false,
						'type'     => 'number',
					),
					'weight'     => array(
						'required' => false,
						'type'     => 'number',
					),
					'fit'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'brand_size' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: logged-in users pass; guests are rate-limited by IP.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( ! $this->guest_rate_ok() ) {
			return new WP_Error(
				'tuki_rate_limited',
				__( 'Too many requests. Please slow down and try again shortly.', 'tukify' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * POST /chat — grounded RAG reply plus product cards.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chat( WP_REST_Request $request ) {
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( '' === trim( $message ) ) {
			return new WP_Error( 'tuki_bad_request', __( 'A message is required.', 'tukify' ), array( 'status' => 400 ) );
		}

		$history       = $this->sanitize_history( $request->get_param( 'history' ) );
		$clarify_count = absint( $request->get_param( 'clarify_count' ) );
		$context_ids   = $request->get_param( 'context_ids' );
		$context_ids   = is_array( $context_ids ) ? array_map( 'absint', $context_ids ) : array();

		try {
			$chat   = new Tuki_Chat();
			$result = $chat->respond( $message, $history, $clarify_count, $context_ids );
		} catch ( Exception $e ) {
			return new WP_Error( 'tuki_chat_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		// Demand insights: log the query + outcome (anonymized, gated by setting).
		Tuki_Analytics::record_demand( $message, is_array( $result ) ? $result : array() );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /search — semantic search returning product cards.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_search( WP_REST_Request $request ) {
		$query = sanitize_text_field( (string) $request->get_param( 'query' ) );

		if ( '' === trim( $query ) ) {
			return new WP_Error( 'tuki_bad_request', __( 'A query is required.', 'tukify' ), array( 'status' => 400 ) );
		}

		$n = $request->get_param( 'n' );
		$n = $n ? absint( $n ) : null;

		try {
			$search  = new Tuki_Search();
			$results = $search->query( $query, $n );
		} catch ( Exception $e ) {
			return new WP_Error( 'tuki_search_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		Tuki_Analytics::record_query( $query, $results );

		return rest_ensure_response( array( 'products' => Tuki_Cart::cards_from_results( $results ) ) );
	}

	/**
	 * Largest accepted decoded image size (4 MB).
	 */
	const MAX_IMAGE_BYTES = 4194304;

	/**
	 * POST /visual-search — identify a product from an uploaded image, then match the catalog.
	 *
	 * The image is sent to the provider server-side; the API key never reaches the client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_visual_search( WP_REST_Request $request ) {
		$raw = (string) $request->get_param( 'image' );

		if ( '' === trim( $raw ) ) {
			return new WP_Error( 'tuki_bad_request', __( 'An image is required.', 'tukify' ), array( 'status' => 400 ) );
		}

		// Accept a data URL ("data:image/jpeg;base64,….") or bare base64 + mime.
		$mime = '';

		if ( preg_match( '#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $raw, $match ) ) {
			$mime   = strtolower( $match[1] );
			$base64 = $match[2];
		} else {
			$base64 = $raw;
			$mime   = strtolower( sanitize_text_field( (string) $request->get_param( 'mime' ) ) );
		}

		$allowed = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/webp' );

		if ( ! in_array( $mime, $allowed, true ) ) {
			return new WP_Error( 'tuki_bad_image', __( 'Please upload a JPG, PNG, or WebP image.', 'tukify' ), array( 'status' => 400 ) );
		}

		$base64  = preg_replace( '/\s+/', '', $base64 );
		// Decoding an uploaded image payload (validated as an image below), not code.
		$decoded = base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded || '' === $decoded ) {
			return new WP_Error( 'tuki_bad_image', __( 'That image could not be read. Please try another.', 'tukify' ), array( 'status' => 400 ) );
		}

		if ( strlen( $decoded ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'tuki_image_too_large', __( 'That image is too large — please use one under 4 MB.', 'tukify' ), array( 'status' => 413 ) );
		}

		// Normalize jpg → jpeg for the provider.
		if ( 'image/jpg' === $mime ) {
			$mime = 'image/jpeg';
		}

		try {
			$chat   = new Tuki_Chat();
			$result = $chat->visual_search( $base64, $mime );
		} catch ( Exception $e ) {
			return new WP_Error( 'tuki_visual_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /upsell — cart-aware complementary suggestions (no AI call).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_upsell( WP_REST_Request $request ) {
		unset( $request );

		if ( ! function_exists( 'wc_get_product' ) || ! Tuki_Settings::get( 'upsell_enabled' ) ) {
			return rest_ensure_response( array( 'products' => array() ) );
		}

		$count = min( 4, max( 1, (int) Tuki_Settings::get( 'upsell_max' ) ) );
		$cards = Tuki_Cart::upsell( $count );

		if ( ! empty( $cards ) ) {
			Tuki_Analytics::record_event( 'upsell_shown' );
		}

		return rest_ensure_response(
			array(
				'products' => $cards,
				'message'  => ! empty( $cards ) ? __( 'These pair well with what\'s in your cart:', 'tukify' ) : '',
			)
		);
	}

	/**
	 * POST /recommendations — cart/context-aware "you might like" (no AI call).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_recommendations( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'tuki_no_wc', __( 'The catalog is unavailable right now.', 'tukify' ), array( 'status' => 500 ) );
		}

		$count = absint( $request->get_param( 'count' ) );
		$count = $count ? min( 12, max( 1, $count ) ) : 4;

		$cards = Tuki_Cart::recommendations(
			array(
				'product_id'  => absint( $request->get_param( 'product_id' ) ),
				'category_id' => absint( $request->get_param( 'category_id' ) ),
			),
			$count
		);

		return rest_ensure_response( array( 'products' => $cards ) );
	}

	/**
	 * POST /browse — paginated catalog browse for the "Show more" control.
	 *
	 * No AI call, so it never touches the provider quota.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_browse( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'tuki_no_wc', __( 'The catalog is unavailable right now.', 'tukify' ), array( 'status' => 500 ) );
		}

		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );

		if ( ! in_array( $mode, array( 'all', 'category' ), true ) ) {
			$mode = 'all';
		}

		$category = absint( $request->get_param( 'category' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 24, max( 4, (int) Tuki_Settings::get( 'browse_batch_size' ) ) );

		$result = Tuki_Cart::browse(
			array(
				'mode'     => $mode,
				'category' => $category,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		return rest_ensure_response(
			array(
				'products' => $result['products'],
				'page'     => $result['page'],
				'has_more' => $result['has_more'],
				'total'    => $result['total'],
			)
		);
	}

	/**
	 * POST /event — logs a lightweight client-side interaction (e.g. product_click).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_event( WP_REST_Request $request ) {
		$type       = sanitize_key( (string) $request->get_param( 'type' ) );
		$product_id = absint( $request->get_param( 'product_id' ) );

		if ( 'product_click' === $type && $product_id ) {
			Tuki_Analytics::record_click( $product_id );
		} elseif ( 'exit_intent_shown' === $type ) {
			Tuki_Analytics::record_event( 'exit_intent_shown' );
		} elseif ( 'reengage_shown' === $type ) {
			Tuki_Analytics::record_event( 'reengage_shown' );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /add-to-cart — add a product with a real-time stock check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_add_to_cart( WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$quantity   = max( 1, absint( $request->get_param( 'quantity' ) ) );

		if ( ! $product_id ) {
			return new WP_Error( 'tuki_bad_request', __( 'A product ID is required.', 'tukify' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wc_get_product' ) || ! Tuki_Cart::ensure_cart_loaded() ) {
			return new WP_Error( 'tuki_no_cart', __( 'The cart is unavailable right now.', 'tukify' ), array( 'status' => 500 ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'tuki_not_found', __( 'That product could not be found.', 'tukify' ), array( 'status' => 404 ) );
		}

		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new WP_Error( 'tuki_out_of_stock', __( 'Sorry, that product is out of stock.', 'tukify' ), array( 'status' => 409 ) );
		}

		if ( $product->managing_stock() && ! $product->has_enough_stock( $quantity ) ) {
			return new WP_Error( 'tuki_insufficient_stock', __( 'Not enough stock for that quantity.', 'tukify' ), array( 'status' => 409 ) );
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity );

		if ( ! $added ) {
			return new WP_Error( 'tuki_add_failed', __( 'Could not add that product to the cart.', 'tukify' ), array( 'status' => 500 ) );
		}

		// Save the cart into the session (+ set the session cookie) so it persists
		// and accumulates across REST requests, exactly like the storefront.
		Tuki_Cart::persist_cart();

		Tuki_Analytics::record_add_to_cart( $product_id );

		return rest_ensure_response(
			array(
				'success'    => true,
				'product_id' => $product_id,
				'cart_count' => WC()->cart->get_cart_contents_count(),
				'cart_total' => wp_strip_all_tags( WC()->cart->get_cart_total() ),
			)
		);
	}

	/**
	 * POST /order-status — returns an order's status ONLY after verifying the
	 * requester owns it.
	 *
	 * Ownership is proven one of two ways: the logged-in user is the order's
	 * customer, or the supplied billing email matches the order's. Any other
	 * case reveals nothing. "No such order" and "verification failed" return the
	 * same generic message so order numbers cannot be enumerated by guessing, and
	 * repeated failures from one actor are throttled. HPOS-safe: uses wc_get_order
	 * and CRUD getters only, never direct post meta.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_order_status( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'tuki_no_wc', __( 'Order lookup is unavailable right now.', 'tukify' ), array( 'status' => 500 ) );
		}

		$actor = $this->order_lookup_actor();

		// Block brute-force guessing before doing any work.
		if ( $this->order_lookup_blocked( $actor ) ) {
			return new WP_Error(
				'tuki_order_throttled',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'tukify' ),
				array( 'status' => 429 )
			);
		}

		$order_id = absint( $request->get_param( 'order_id' ) );
		$email    = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );

		if ( ! $order_id ) {
			return new WP_Error( 'tuki_order_bad_request', __( 'Please enter your order number.', 'tukify' ), array( 'status' => 400 ) );
		}

		$order = wc_get_order( $order_id );

		// Same response whether the order does not exist or ownership failed, so a
		// guesser cannot distinguish real order numbers from fake ones.
		if ( ! $order || ! $this->order_ownership_ok( $order, $email ) ) {
			$this->order_lookup_register_failure( $actor );

			return new WP_Error(
				'tuki_order_unverified',
				__( 'We couldn\'t find an order matching those details. Please check your order number and the billing email used on the order.', 'tukify' ),
				array( 'status' => 404 )
			);
		}

		// Verified — clear the failure counter and return a non-sensitive summary.
		$this->order_lookup_reset( $actor );

		$created = $order->get_date_created();

		$data = array(
			'number'     => $order->get_order_number(),
			'status'     => wc_get_order_status_name( $order->get_status() ),
			'date'       => $created ? wc_format_datetime( $created ) : '',
			'total'      => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'item_count' => (int) $order->get_item_count(),
		);

		// Optional tracking number, only when present. get_meta() is HPOS-safe.
		$tracking = $order->get_meta( '_tracking_number' );

		if ( ! empty( $tracking ) ) {
			$data['tracking'] = sanitize_text_field( (string) $tracking );
		}

		return rest_ensure_response( array( 'order' => $data ) );
	}

	/**
	 * POST /size-advice — recommends a size for a product from its category size
	 * chart and the shopper's answers. Deterministic (no AI), neutral wording.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_size_advice( WP_REST_Request $request ) {
		if ( ! Tuki_Size::is_enabled() ) {
			return new WP_Error( 'tuki_size_disabled', __( 'The size advisor is turned off.', 'tukify' ), array( 'status' => 403 ) );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'tuki_no_wc', __( 'Size advice is unavailable right now.', 'tukify' ), array( 'status' => 500 ) );
		}

		$product = wc_get_product( absint( $request->get_param( 'product_id' ) ) );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'tuki_not_found', __( 'That product could not be found.', 'tukify' ), array( 'status' => 404 ) );
		}

		$answers = array(
			'height'     => max( 0, (float) $request->get_param( 'height' ) ),
			'weight'     => max( 0, (float) $request->get_param( 'weight' ) ),
			'fit'        => sanitize_key( (string) $request->get_param( 'fit' ) ),
			'brand_size' => sanitize_text_field( (string) $request->get_param( 'brand_size' ) ),
		);

		return rest_ensure_response( Tuki_Size::recommend( $product, $answers ) );
	}

	/**
	 * Whether the current requester is allowed to see the given order.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $email Lowercased, sanitized billing email supplied by the requester.
	 * @return bool
	 */
	private function order_ownership_ok( $order, $email ) {
		// 1) The logged-in user is the order's customer.
		$customer_id = (int) $order->get_customer_id();

		if ( is_user_logged_in() && $customer_id > 0 && get_current_user_id() === $customer_id ) {
			return true;
		}

		// 2) The supplied billing email matches the order's (case-insensitive,
		// timing-safe comparison so response time cannot leak how close a guess was).
		$order_email = strtolower( (string) $order->get_billing_email() );

		if ( '' !== $email && '' !== $order_email && hash_equals( $order_email, $email ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Identifies the actor for order-lookup throttling: the user id when logged
	 * in, otherwise the client IP.
	 *
	 * @return string
	 */
	private function order_lookup_actor() {
		if ( is_user_logged_in() ) {
			return 'u:' . get_current_user_id();
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		return 'ip:' . $ip;
	}

	/**
	 * Transient key for an actor's failed-lookup counter.
	 *
	 * @param string $actor Actor identifier.
	 * @return string
	 */
	private function order_lookup_key( $actor ) {
		return 'tuki_ordfail_' . md5( $actor );
	}

	/**
	 * Whether the actor has hit the failed-lookup limit.
	 *
	 * @param string $actor Actor identifier.
	 * @return bool
	 */
	private function order_lookup_blocked( $actor ) {
		return (int) get_transient( $this->order_lookup_key( $actor ) ) >= self::ORDER_LOOKUP_MAX_FAILURES;
	}

	/**
	 * Records a failed lookup for the actor (extends the throttle window).
	 *
	 * @param string $actor Actor identifier.
	 * @return void
	 */
	private function order_lookup_register_failure( $actor ) {
		$key   = $this->order_lookup_key( $actor );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::ORDER_LOOKUP_WINDOW );
	}

	/**
	 * Clears an actor's failed-lookup counter after a successful verification.
	 *
	 * @param string $actor Actor identifier.
	 * @return void
	 */
	private function order_lookup_reset( $actor ) {
		delete_transient( $this->order_lookup_key( $actor ) );
	}

	/**
	 * Sanitizes and trims incoming chat history.
	 *
	 * @param mixed $raw Raw history param.
	 * @return array Last 10 clean messages.
	 */
	private function sanitize_history( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$content = isset( $item['content'] ) ? sanitize_textarea_field( (string) $item['content'] ) : '';

			if ( '' === $content ) {
				continue;
			}

			$out[] = array(
				'role'    => ( isset( $item['role'] ) && 'assistant' === $item['role'] ) ? 'assistant' : 'user',
				'content' => $content,
			);
		}

		return array_slice( $out, -10 );
	}

	/**
	 * Per-IP guest rate limiter (requests per minute from settings).
	 *
	 * @return bool True if the request is within the limit.
	 */
	private function guest_rate_ok() {
		$limit = (int) Tuki_Settings::get( 'guest_rate_limit' );

		if ( $limit <= 0 ) {
			return true;
		}

		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$key   = 'tuki_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
