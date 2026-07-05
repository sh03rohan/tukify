<?php
/**
 * Back-in-stock notifications.
 *
 * When a shopper is looking at an out-of-stock product in the chat, the widget
 * offers to email them when it returns. We store the bare minimum — product id,
 * email, an unsubscribe token, and timestamps — and email them when WooCommerce
 * reports the product back in stock.
 *
 * Privacy by design:
 *   - Only the email needed to notify is stored (no name, IP, or session).
 *   - Consent is required before we store anything (enforced at the REST layer).
 *   - Every email carries a one-click unsubscribe link, and the row is deleted
 *     the moment the notification is sent (or the shopper unsubscribes).
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores interest, listens for stock changes, and sends the "it's back" email.
 */
class Tuki_Stock_Notify {

	/**
	 * Cron hook that dispatches queued notifications for one product.
	 */
	const DISPATCH_HOOK = 'tuki_stock_dispatch';

	/**
	 * Query var carrying an unsubscribe token on the front end.
	 */
	const UNSUB_VAR = 'tuki_stock_unsub';

	/**
	 * Hooks stock-change listeners, the dispatcher, and the unsubscribe handler.
	 */
	public function __construct() {
		// A product's stock quantity or status changed — maybe it's back.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_status_change' ), 10, 3 );

		// Emails are sent off the critical path via a scheduled single event.
		add_action( self::DISPATCH_HOOK, array( $this, 'dispatch' ) );

		// Front-end one-click unsubscribe.
		add_action( 'template_redirect', array( $this, 'maybe_handle_unsubscribe' ) );
	}

	/**
	 * Whether the feature is usable: enabled in settings AND WooCommerce present.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Tuki_Settings::get( 'stock_notify_enabled' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Stores interest in a product's return to stock.
	 *
	 * Consent is verified by the caller (REST handler). Returns a small result
	 * array so the endpoint can respond appropriately.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $email      Raw email.
	 * @return array{ok:bool,message:string,already?:bool}
	 */
	public static function subscribe( $product_id, $email ) {
		$product_id = absint( $product_id );
		$email      = sanitize_email( (string) $email );

		if ( ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please enter a valid email address.', 'tukify' ),
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return array(
				'ok'      => false,
				'message' => __( 'That product could not be found.', 'tukify' ),
			);
		}

		// Already back? Nothing to wait for.
		if ( $product->is_in_stock() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Good news — this item is already back in stock.', 'tukify' ),
			);
		}

		$token = self::make_token();
		$saved = Tuki_DB::upsert_stock_notify( $product_id, $email, $token );

		if ( '' === $saved ) {
			return array(
				'ok'      => false,
				'message' => __( 'Sorry, we couldn\'t save that just now. Please try again.', 'tukify' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'You\'re on the list — we\'ll email you the moment it\'s back.', 'tukify' ),
		);
	}

	/**
	 * Stock quantity changed — schedule a dispatch if the product is now in stock.
	 *
	 * @param WC_Product $product Product whose stock changed.
	 * @return void
	 */
	public function on_stock_change( $product ) {
		if ( ! self::is_enabled() || ! is_object( $product ) || ! method_exists( $product, 'is_in_stock' ) ) {
			return;
		}

		if ( ! $product->is_in_stock() ) {
			return;
		}

		$this->schedule_for_product( $product );
	}

	/**
	 * Stock status changed — schedule a dispatch if the new status is in-stock.
	 *
	 * @param int             $product_id Product ID.
	 * @param string          $status     New stock status.
	 * @param WC_Product|null $product    Product object (may be null on older WC).
	 * @return void
	 */
	public function on_status_change( $product_id, $status = '', $product = null ) {
		if ( ! self::is_enabled() || 'instock' !== $status ) {
			return;
		}

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( absint( $product_id ) );
		}

		if ( $product ) {
			$this->schedule_for_product( $product );
		}
	}

	/**
	 * Schedules a dispatch for a product (and its parent, for variations) when
	 * there are pending subscribers — deduped so one request queues at most once.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	private function schedule_for_product( $product ) {
		$ids = array( (int) $product->get_id() );

		if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
			$ids[] = (int) $product->get_parent_id();
		}

		foreach ( array_unique( $ids ) as $id ) {
			if ( empty( Tuki_DB::stock_notify_pending( $id, 1 ) ) ) {
				continue;
			}

			$args = array( $id );

			if ( ! wp_next_scheduled( self::DISPATCH_HOOK, $args ) ) {
				// A short delay lets the stock write settle before we read it back.
				wp_schedule_single_event( time() + 30, self::DISPATCH_HOOK, $args );
			}
		}
	}

	/**
	 * Sends the "back in stock" email to everyone waiting on a product, then
	 * removes each row once sent. Re-verifies the product is actually in stock so
	 * a flip back to out-of-stock before the cron runs sends nothing.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function dispatch( $product_id ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$product = wc_get_product( absint( $product_id ) );

		if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_in_stock() ) {
			return;
		}

		$rows = Tuki_DB::stock_notify_pending( $product_id );

		if ( empty( $rows ) ) {
			return;
		}

		$subject_tpl = self::subject_template();
		$body_tpl    = self::body_template();
		$name        = $product->get_name();
		$url         = get_permalink( $product->get_id() );
		$site        = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		foreach ( $rows as $row ) {
			$replace = array(
				'{product_name}'    => $name,
				'{product_url}'     => $url,
				'{site_name}'       => $site,
				'{unsubscribe_url}' => self::unsubscribe_url( $row['token'] ),
			);

			$subject = strtr( $subject_tpl, $replace );
			$body    = strtr( $body_tpl, $replace );

			$sent = wp_mail( $row['email'], $subject, $body );

			// Delete on success so we never resend and never keep the email longer
			// than needed. A failed send is left pending for the next stock change.
			if ( $sent ) {
				Tuki_DB::delete_stock_notify( (int) $row['id'] );
			}
		}
	}

	/**
	 * Handles a front-end unsubscribe link click.
	 *
	 * @return void
	 */
	public function maybe_handle_unsubscribe() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability here; it is unguessable and single-purpose.
		$token = isset( $_GET[ self::UNSUB_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::UNSUB_VAR ] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		$row = Tuki_DB::stock_notify_by_token( $token );

		if ( $row ) {
			Tuki_DB::delete_stock_notify( (int) $row['id'] );
		}

		// Always show the same confirmation (whether or not the token existed) so a
		// token cannot be probed for validity.
		wp_die(
			esc_html__( 'You have been unsubscribed. You will no longer receive back-in-stock emails for this item.', 'tukify' ),
			esc_html__( 'Unsubscribed', 'tukify' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Builds an unsubscribe URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function unsubscribe_url( $token ) {
		return add_query_arg( self::UNSUB_VAR, rawurlencode( (string) $token ), home_url( '/' ) );
	}

	/**
	 * The subject template (owner setting, or a sensible default).
	 *
	 * @return string
	 */
	public static function subject_template() {
		$subject = (string) Tuki_Settings::get( 'stock_notify_subject' );

		return '' !== trim( $subject ) ? $subject : self::default_subject();
	}

	/**
	 * The body template (owner setting, or a sensible default).
	 *
	 * @return string
	 */
	public static function body_template() {
		$body = (string) Tuki_Settings::get( 'stock_notify_body' );

		return '' !== trim( $body ) ? $body : self::default_body();
	}

	/**
	 * Default subject template.
	 *
	 * @return string
	 */
	public static function default_subject() {
		return __( '{product_name} is back in stock', 'tukify' );
	}

	/**
	 * Default body template.
	 *
	 * @return string
	 */
	public static function default_body() {
		return __(
			"Good news! {product_name} is available again at {site_name}.\n\nView it here: {product_url}\n\nYou're receiving this because you asked to be notified. To unsubscribe, visit: {unsubscribe_url}",
			'tukify'
		);
	}

	/**
	 * Generates a random, URL-safe unsubscribe token.
	 *
	 * @return string
	 */
	private static function make_token() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 40, false, false );
		}

		return md5( uniqid( (string) wp_rand(), true ) );
	}
}
