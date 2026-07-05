<?php
/**
 * In-chat checkout engine.
 *
 * Lets a shopper review their cart, confirm an address, pick a shipping method,
 * and place an order from inside the chat — WITHOUT re-implementing any pricing,
 * tax, shipping, or payment logic. All money math and order creation go through
 * WooCommerce's own APIs:
 *
 *   - WC()->cart / WC()->customer / WC()->shipping()  → totals, rates, address.
 *   - WC()->checkout()->create_order()                → the real order (taxes,
 *                                                        line items, shipping,
 *                                                        coupons — all WC's).
 *   - $gateway->process_payment()                     → the real payment.
 *
 * Payment is never handled here directly and raw card data never touches this
 * code. Gateways that render their own payment fields (card inputs, e.g. Stripe)
 * are handed off to WooCommerce's official "pay for order" page instead, where
 * the gateway renders its own secure UI. Redirect gateways (PayPal, etc.) return
 * their own redirect from process_payment(). Only offline gateways (COD, BACS,
 * cheque) actually complete inside the chat.
 *
 * The whole feature is behind an opt-in flag and defaults off.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates the in-chat checkout using WooCommerce's own flow.
 */
class Tuki_Checkout {

	/**
	 * Whether in-chat checkout is available: opt-in flag on AND WooCommerce active.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Tuki_Settings::get( 'checkout_in_chat' )
			&& function_exists( 'WC' )
			&& function_exists( 'wc_get_checkout_url' );
	}

	/**
	 * The URL of the normal checkout page — the universal graceful fallback.
	 *
	 * @return string
	 */
	public static function fallback_url() {
		return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
	}

	/**
	 * Builds the current checkout state: cart review, prefilled address, shipping
	 * options, payment options, and the flags the UI needs to decide what to show.
	 *
	 * @return array
	 */
	public static function state() {
		if ( ! Tuki_Cart::ensure_cart_loaded() || ! WC()->cart ) {
			return array(
				'ok'           => false,
				'reason'       => 'no_cart',
				'fallback_url' => self::fallback_url(),
			);
		}

		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			return array(
				'ok'           => false,
				'reason'       => 'empty_cart',
				'fallback_url' => self::fallback_url(),
			);
		}

		// Recalculate through WooCommerce so totals/shipping reflect the latest cart.
		$cart->calculate_shipping();
		$cart->calculate_totals();

		$needs_shipping = $cart->needs_shipping() && $cart->show_shipping();

		return array(
			'ok'             => true,
			'must_login'     => self::must_login(),
			'needs_shipping' => (bool) $needs_shipping,
			'needs_payment'  => (bool) $cart->needs_payment(),
			'review'         => self::cart_review(),
			'address'        => self::prefill_address(),
			'countries'      => self::allowed_countries(),
			'shipping'       => $needs_shipping ? self::shipping_options() : array(),
			'payment'        => $cart->needs_payment() ? self::payment_options() : array(),
			'fallback_url'   => self::fallback_url(),
		);
	}

	/**
	 * Applies an address + chosen shipping method, recalculates through WooCommerce,
	 * and returns the refreshed state (so the UI always shows WC's own numbers).
	 *
	 * @param array $data Raw request data.
	 * @return array
	 */
	public static function update( $data ) {
		if ( ! Tuki_Cart::ensure_cart_loaded() || ! WC()->cart || WC()->cart->is_empty() ) {
			return array(
				'ok'           => false,
				'reason'       => 'empty_cart',
				'fallback_url' => self::fallback_url(),
			);
		}

		self::apply_address( $data );
		self::apply_shipping_method( $data );

		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();
		Tuki_Cart::persist_cart();

		return self::state();
	}

	/**
	 * Places the order using WooCommerce's own order + payment pipeline.
	 *
	 * Returns one of:
	 *   - array( 'result' => 'error',    'errors' => [...] )          Validation failed.
	 *   - array( 'result' => 'placed',   'order' => [...], 'view_url' ) Completed in chat (offline gateway).
	 *   - array( 'result' => 'redirect', 'redirect' => url )           Gateway redirect (e.g. PayPal).
	 *   - array( 'result' => 'handoff',  'redirect' => order-pay url )  Gateway needs its own UI (card fields).
	 *   - array( 'result' => 'fallback', 'redirect' => checkout url )   Anything unsupported.
	 *
	 * @param array $data Raw request data.
	 * @return array
	 */
	public static function place( $data ) {
		if ( ! self::is_enabled() ) {
			return self::fallback( 'disabled' );
		}

		if ( ! Tuki_Cart::ensure_cart_loaded() || ! WC()->cart || WC()->cart->is_empty() ) {
			return self::fallback( 'empty_cart' );
		}

		if ( self::must_login() ) {
			return self::fallback( 'must_login' );
		}

		// Apply the shopper's latest address + shipping choice, then recalc.
		self::apply_address( $data );
		self::apply_shipping_method( $data );
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();

		// Let WooCommerce validate the cart itself (stock, coupons, min/max, etc.).
		$cart_errors = self::cart_errors();
		$field_errors = self::validate_address( $data );

		$cart = WC()->cart;
		$needs_shipping = $cart->needs_shipping() && $cart->show_shipping();

		if ( $needs_shipping && '' === self::current_shipping_method() ) {
			$field_errors['shipping_method'] = __( 'Please choose a shipping method.', 'tukify' );
		}

		$payment_method = isset( $data['payment_method'] ) ? wc_clean( wp_unslash( $data['payment_method'] ) ) : '';
		$available      = self::available_gateways();

		if ( $cart->needs_payment() ) {
			if ( '' === $payment_method || ! isset( $available[ $payment_method ] ) ) {
				$field_errors['payment_method'] = __( 'Please choose a payment method.', 'tukify' );
			}
		} else {
			$payment_method = '';
		}

		if ( ! empty( $cart_errors ) ) {
			// Cart-level problems (out of stock, etc.) — safest to send them to the
			// full checkout page where WooCommerce surfaces the notices in detail.
			return self::fallback( 'cart_error', $cart_errors );
		}

		if ( ! empty( $field_errors ) ) {
			return array(
				'result' => 'error',
				'errors' => $field_errors,
			);
		}

		// Persist the confirmed address on the customer before creating the order.
		Tuki_Cart::persist_cart();

		// --- Create the real order via WooCommerce (all pricing/tax is WC's). ---
		try {
			$checkout   = WC()->checkout();
			$order_data = self::order_data( $data, $payment_method );
			$order_id   = $checkout->create_order( $order_data );
		} catch ( Exception $e ) {
			return self::fallback( 'create_failed' );
		}

		if ( is_wp_error( $order_id ) || ! $order_id ) {
			return self::fallback( 'create_failed' );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return self::fallback( 'create_failed' );
		}

		// Authoritatively set the validated addresses via CRUD (HPOS-safe) so the
		// order is correct regardless of WC version field-mapping quirks.
		$order->set_address( self::address_array( $data, 'billing' ), 'billing' );
		if ( $needs_shipping ) {
			$order->set_address( self::address_array( $data, 'shipping' ), 'shipping' );
		}
		$order->set_created_via( 'tukify_chat' );
		$order->save();

		// Mirror WooCommerce: remember the order awaiting payment (so retries reuse
		// it) and fire the processed hook plugins expect.
		WC()->session->set( 'order_awaiting_payment', $order_id );
		do_action( 'woocommerce_checkout_order_processed', $order_id, $order_data, $order );

		// --- Payment: always through WooCommerce, never handled here. ---
		if ( ! $order->needs_payment() ) {
			// Free order — no gateway involved.
			$order->payment_complete();
			self::finish_cart();

			return array(
				'result'   => 'placed',
				'order'    => self::order_summary( $order ),
				'view_url' => $order->get_checkout_order_received_url(),
			);
		}

		$gateway = isset( $available[ $payment_method ] ) ? $available[ $payment_method ] : null;

		// Gateways that render their own payment fields (card inputs) or that we
		// otherwise can't safely drive headlessly are handed off to WooCommerce's
		// official order-pay page, where the gateway shows its own secure UI.
		if ( ! $gateway || ! self::gateway_supported( $gateway ) ) {
			return array(
				'result'   => 'handoff',
				'reason'   => 'gateway_ui_required',
				'redirect' => $order->get_checkout_payment_url(),
				'order'    => self::order_summary( $order ),
			);
		}

		// Offline / redirect gateways: let the gateway process the payment.
		try {
			$result = $gateway->process_payment( $order_id );
		} catch ( Exception $e ) {
			return array(
				'result'   => 'handoff',
				'reason'   => 'payment_retry',
				'redirect' => $order->get_checkout_payment_url(),
				'order'    => self::order_summary( $order ),
			);
		}

		if ( ! is_array( $result ) || ! isset( $result['result'] ) || 'success' !== $result['result'] ) {
			// Payment couldn't be set up — send them to the order-pay page to retry.
			return array(
				'result'   => 'handoff',
				'reason'   => 'payment_retry',
				'redirect' => $order->get_checkout_payment_url(),
				'order'    => self::order_summary( $order ),
			);
		}

		// Success. Empty the cart exactly as WooCommerce's own checkout does.
		self::finish_cart();

		$redirect = isset( $result['redirect'] ) ? $result['redirect'] : $order->get_checkout_order_received_url();

		// A redirect to another host (e.g. PayPal) must send the browser there. An
		// internal redirect (order-received) means the order is done, so we can
		// confirm it right inside the chat and just offer the link.
		if ( self::is_external_url( $redirect ) ) {
			return array(
				'result'   => 'redirect',
				'redirect' => $redirect,
			);
		}

		return array(
			'result'   => 'placed',
			'order'    => self::order_summary( $order ),
			'view_url' => $redirect,
		);
	}

	/* ---------------------------------------------------------------------
	 * Cart review + address + options
	 * ------------------------------------------------------------------- */

	/**
	 * Read-only cart review: line items + WooCommerce's own formatted totals.
	 *
	 * @return array
	 */
	private static function cart_review() {
		$cart  = WC()->cart;
		$items = array();

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}

			$product   = $item['data'];
			$image_id  = $product->get_image_id();
			$image     = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

			$items[] = array(
				'name'  => $product->get_name(),
				'qty'   => (int) $item['quantity'],
				'total' => wp_strip_all_tags( $cart->get_product_subtotal( $product, $item['quantity'] ) ),
				'image' => $image ? $image : '',
			);
		}

		$totals = array(
			array(
				'label' => __( 'Subtotal', 'tukify' ),
				'value' => wp_strip_all_tags( $cart->get_cart_subtotal() ),
			),
		);

		if ( $cart->get_cart_discount_total() > 0 ) {
			$totals[] = array(
				'label' => __( 'Discount', 'tukify' ),
				'value' => '-' . wp_strip_all_tags( wc_price( $cart->get_cart_discount_total() ) ),
			);
		}

		if ( $cart->needs_shipping() && $cart->show_shipping() ) {
			$totals[] = array(
				'label' => __( 'Shipping', 'tukify' ),
				'value' => wp_strip_all_tags( $cart->get_cart_shipping_total() ),
			);
		}

		if ( wc_tax_enabled() && $cart->get_total_tax() > 0 ) {
			$totals[] = array(
				'label' => __( 'Tax', 'tukify' ),
				'value' => wp_strip_all_tags( wc_price( $cart->get_total_tax() ) ),
			);
		}

		return array(
			'items'  => $items,
			'totals' => $totals,
			'total'  => wp_strip_all_tags( $cart->get_total() ),
		);
	}

	/**
	 * Prefills the address form from the current customer (logged-in or session).
	 *
	 * @return array
	 */
	private static function prefill_address() {
		$customer = WC()->customer;

		if ( ! $customer ) {
			return array();
		}

		return array(
			'first_name' => $customer->get_billing_first_name(),
			'last_name'  => $customer->get_billing_last_name(),
			'address_1'  => $customer->get_billing_address_1(),
			'address_2'  => $customer->get_billing_address_2(),
			'city'       => $customer->get_billing_city(),
			'state'      => $customer->get_billing_state(),
			'postcode'   => $customer->get_billing_postcode(),
			'country'    => $customer->get_billing_country() ? $customer->get_billing_country() : WC()->countries->get_base_country(),
			'email'      => $customer->get_billing_email(),
			'phone'      => $customer->get_billing_phone(),
		);
	}

	/**
	 * The countries WooCommerce is configured to sell to, as value/label pairs.
	 *
	 * @return array
	 */
	private static function allowed_countries() {
		$out = array();

		foreach ( WC()->countries->get_allowed_countries() as $code => $name ) {
			$out[] = array(
				'code' => $code,
				'name' => html_entity_decode( wp_strip_all_tags( $name ) ),
			);
		}

		return $out;
	}

	/**
	 * Available shipping rates for the first package (covers the common case).
	 *
	 * @return array
	 */
	private static function shipping_options() {
		$packages = WC()->shipping()->get_packages();
		$chosen   = self::current_shipping_method();
		$rates    = array();

		if ( ! empty( $packages[0]['rates'] ) ) {
			foreach ( $packages[0]['rates'] as $rate_id => $rate ) {
				$rates[] = array(
					'id'    => $rate_id,
					'label' => wp_strip_all_tags( $rate->get_label() ),
					'cost'  => wp_strip_all_tags( wc_price( (float) $rate->get_cost() + array_sum( (array) $rate->get_taxes() ) ) ),
				);
			}
		}

		return array(
			'rates'  => $rates,
			'chosen' => $chosen,
		);
	}

	/**
	 * Payment gateways available for this cart, tagged with whether the chat can
	 * complete them directly or must hand off to WooCommerce's payment page.
	 *
	 * @return array
	 */
	private static function payment_options() {
		$out = array();

		foreach ( self::available_gateways() as $id => $gateway ) {
			$out[] = array(
				'id'          => $id,
				'title'       => wp_strip_all_tags( $gateway->get_title() ),
				'description' => wp_kses_post( $gateway->get_description() ),
				'in_chat'     => self::gateway_supported( $gateway ),
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Mutators (all go through WooCommerce)
	 * ------------------------------------------------------------------- */

	/**
	 * Sets the customer's billing + shipping address from request data.
	 *
	 * @param array $data Raw request data.
	 * @return void
	 */
	private static function apply_address( $data ) {
		$customer = WC()->customer;

		if ( ! $customer ) {
			return;
		}

		$a = self::address_array( $data, 'billing' );

		$customer->set_billing_first_name( $a['first_name'] );
		$customer->set_billing_last_name( $a['last_name'] );
		$customer->set_billing_address_1( $a['address_1'] );
		$customer->set_billing_address_2( $a['address_2'] );
		$customer->set_billing_city( $a['city'] );
		$customer->set_billing_state( $a['state'] );
		$customer->set_billing_postcode( $a['postcode'] );
		$customer->set_billing_country( $a['country'] );
		$customer->set_billing_email( $a['email'] );
		$customer->set_billing_phone( $a['phone'] );

		// Ship to the same address (in-chat checkout keeps a single address).
		$customer->set_shipping_first_name( $a['first_name'] );
		$customer->set_shipping_last_name( $a['last_name'] );
		$customer->set_shipping_address_1( $a['address_1'] );
		$customer->set_shipping_address_2( $a['address_2'] );
		$customer->set_shipping_city( $a['city'] );
		$customer->set_shipping_state( $a['state'] );
		$customer->set_shipping_postcode( $a['postcode'] );
		$customer->set_shipping_country( $a['country'] );

		$customer->save();
	}

	/**
	 * Records the chosen shipping method in the session for every package that
	 * offers it (WooCommerce reads this when it builds the order).
	 *
	 * @param array $data Raw request data.
	 * @return void
	 */
	private static function apply_shipping_method( $data ) {
		if ( empty( $data['shipping_method'] ) ) {
			return;
		}

		$selected = wc_clean( wp_unslash( $data['shipping_method'] ) );
		$packages = WC()->shipping()->get_packages();
		$chosen   = (array) WC()->session->get( 'chosen_shipping_methods' );

		foreach ( $packages as $i => $package ) {
			if ( isset( $package['rates'][ $selected ] ) ) {
				$chosen[ $i ] = $selected;
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen );
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the shopper must log in first (guest checkout is disabled).
	 *
	 * @return bool
	 */
	private static function must_login() {
		if ( is_user_logged_in() ) {
			return false;
		}

		$checkout = WC()->checkout();

		return method_exists( $checkout, 'is_registration_required' )
			? (bool) $checkout->is_registration_required()
			: ( 'yes' !== get_option( 'woocommerce_enable_guest_checkout' ) );
	}

	/**
	 * Validates required address fields (locale-aware for postcode/state), the
	 * email, and the selling-country restriction. Returns field => message.
	 *
	 * @param array $data Raw request data.
	 * @return array
	 */
	private static function validate_address( $data ) {
		$a      = self::address_array( $data, 'billing' );
		$errors = array();

		foreach ( array( 'first_name', 'last_name', 'address_1', 'city' ) as $field ) {
			if ( '' === $a[ $field ] ) {
				$errors[ $field ] = __( 'This field is required.', 'tukify' );
			}
		}

		if ( '' === $a['email'] || ! is_email( $a['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'tukify' );
		}

		$allowed = WC()->countries->get_allowed_countries();

		if ( '' === $a['country'] || ! isset( $allowed[ $a['country'] ] ) ) {
			$errors['country'] = __( 'Please choose a country we ship to.', 'tukify' );
		} else {
			$locale = WC()->countries->get_country_locale();
			$rules  = isset( $locale[ $a['country'] ] ) ? $locale[ $a['country'] ] : array();

			$postcode_required = ! isset( $rules['postcode']['required'] ) || false !== $rules['postcode']['required'];
			$state_required    = ! isset( $rules['state']['required'] ) || false !== $rules['state']['required'];

			if ( $postcode_required && '' === $a['postcode'] ) {
				$errors['postcode'] = __( 'This field is required.', 'tukify' );
			} elseif ( '' !== $a['postcode'] && class_exists( 'WC_Validation' ) && ! WC_Validation::is_postcode( $a['postcode'], $a['country'] ) ) {
				$errors['postcode'] = __( 'Please enter a valid postcode.', 'tukify' );
			}

			$states = WC()->countries->get_states( $a['country'] );

			if ( $state_required && ! empty( $states ) && '' === $a['state'] ) {
				$errors['state'] = __( 'This field is required.', 'tukify' );
			}
		}

		return $errors;
	}

	/**
	 * Collects WooCommerce's own cart validation notices (stock, coupons, etc.).
	 *
	 * @return array List of error message strings.
	 */
	private static function cart_errors() {
		if ( ! function_exists( 'wc_clear_notices' ) ) {
			return array();
		}

		wc_clear_notices();
		WC()->cart->check_cart_items();

		$notices = wc_get_notices( 'error' );
		wc_clear_notices();

		$out = array();

		foreach ( (array) $notices as $notice ) {
			$text = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : $notice;
			$out[] = wp_strip_all_tags( (string) $text );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Gateway support + helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Available payment gateways for the current cart.
	 *
	 * @return array id => WC_Payment_Gateway
	 */
	private static function available_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		return (array) WC()->payment_gateways()->get_available_payment_gateways();
	}

	/**
	 * Whether a gateway can be completed from inside the chat.
	 *
	 * A gateway that renders its own payment fields (card inputs) needs its secure
	 * UI, so it is NOT driven headlessly — we hand off to the order-pay page. Only
	 * gateways without on-page fields (offline gateways, and redirect gateways such
	 * as PayPal Standard) are processed in-chat. Owners can override per gateway.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @return bool
	 */
	private static function gateway_supported( $gateway ) {
		$supported = empty( $gateway->has_fields );

		/**
		 * Filter whether a gateway may be completed inside the chat.
		 *
		 * @param bool               $supported Default: true when the gateway has no on-page fields.
		 * @param WC_Payment_Gateway $gateway   The gateway.
		 */
		return (bool) apply_filters( 'tuki_checkout_gateway_supported', $supported, $gateway );
	}

	/**
	 * The currently chosen shipping method id for the first package, or ''.
	 *
	 * @return string
	 */
	private static function current_shipping_method() {
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods' );

		return isset( $chosen[0] ) ? (string) $chosen[0] : '';
	}

	/**
	 * Sanitized address array pulled from request data for the given type.
	 *
	 * @param array  $data Raw request data.
	 * @param string $type 'billing' or 'shipping'.
	 * @return array
	 */
	private static function address_array( $data, $type ) {
		$get = function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) ? wc_clean( wp_unslash( $data[ $key ] ) ) : '';
		};

		$address = array(
			'first_name' => $get( 'first_name' ),
			'last_name'  => $get( 'last_name' ),
			'company'    => $get( 'company' ),
			'address_1'  => $get( 'address_1' ),
			'address_2'  => $get( 'address_2' ),
			'city'       => $get( 'city' ),
			'state'      => $get( 'state' ),
			'postcode'   => $get( 'postcode' ),
			'country'    => strtoupper( $get( 'country' ) ),
		);

		if ( 'billing' === $type ) {
			$address['email'] = sanitize_email( isset( $data['email'] ) ? wp_unslash( $data['email'] ) : '' );
			$address['phone'] = $get( 'phone' );
		}

		return $address;
	}

	/**
	 * Builds the data array passed to WC()->checkout()->create_order().
	 *
	 * @param array  $data           Raw request data.
	 * @param string $payment_method Chosen gateway id ('' for free orders).
	 * @return array
	 */
	private static function order_data( $data, $payment_method ) {
		$order_data = array(
			'payment_method' => $payment_method,
			'order_comments' => isset( $data['note'] ) ? sanitize_textarea_field( wp_unslash( $data['note'] ) ) : '',
		);

		foreach ( self::address_array( $data, 'billing' ) as $key => $value ) {
			$order_data[ 'billing_' . $key ]  = $value;
			$order_data[ 'shipping_' . $key ] = $value;
		}

		return $order_data;
	}

	/**
	 * A non-sensitive summary of a placed order for the confirmation card.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function order_summary( $order ) {
		return array(
			'number' => $order->get_order_number(),
			'status' => wc_get_order_status_name( $order->get_status() ),
			'total'  => wp_strip_all_tags( $order->get_formatted_order_total() ),
		);
	}

	/**
	 * Empties the cart + persists, exactly as WooCommerce's own checkout does on
	 * a successful order.
	 *
	 * @return void
	 */
	private static function finish_cart() {
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		WC()->session->set( 'order_awaiting_payment', null );
		Tuki_Cart::persist_cart();
	}

	/**
	 * Whether a redirect URL points to a different host than this site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_external_url( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( empty( $host ) ) {
			return false; // Relative URL — internal.
		}

		return strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Standard fallback response: send the shopper to the real checkout page.
	 *
	 * @param string $reason  Machine reason code.
	 * @param array  $details Optional detail messages.
	 * @return array
	 */
	private static function fallback( $reason, $details = array() ) {
		return array(
			'result'   => 'fallback',
			'reason'   => $reason,
			'redirect' => self::fallback_url(),
			'details'  => array_values( (array) $details ),
		);
	}
}
