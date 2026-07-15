<?php
/**
 * Frontend: global floating widget + asset enqueue.
 *
 * The widget renders inside a Shadow DOM (built by tukify-widget.js) so the
 * store theme's CSS cannot leak in and break it, and vice-versa. The stylesheet
 * is passed inline in the config and injected into the shadow root by the JS.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues and configures the storefront widget.
 */
class Tuki_Frontend {

	/**
	 * Script handle.
	 */
	const HANDLE = 'tuki-widget';

	/**
	 * Hooks the frontend enqueue.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * On the storefront, register the script and enqueue it if the floating
	 * widget is enabled. Elementor widgets enqueue it on demand via enqueue().
	 */
	public function maybe_enqueue() {
		if ( is_admin() ) {
			return;
		}

		self::register();

		if ( Tuki_Settings::get( 'floating_widget' ) ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * Registers the widget script + localizes its config (idempotent).
	 *
	 * @return void
	 */
	public static function register() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		// Use the file mtime as the version so asset changes always cache-bust.
		$script_path = TUKI_PLUGIN_DIR . 'public/tukify-widget.js';
		$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : TUKI_VERSION;

		wp_register_script(
			self::HANDLE,
			TUKI_PLUGIN_URL . 'public/tukify-widget.js',
			array(),
			$version,
			true
		);

		wp_localize_script( self::HANDLE, 'tukifyConfig', self::config() );
	}

	/**
	 * Registers (if needed) and enqueues the widget script.
	 *
	 * Used by the Elementor widgets and the editor preview.
	 *
	 * @return void
	 */
	public static function enqueue() {
		self::register();
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Backwards-compatible alias.
	 *
	 * @return void
	 */
	public static function enqueue_widget() {
		self::enqueue();
	}

	/**
	 * Builds the config object passed to the widget.
	 *
	 * @return array
	 */
	private static function config() {
		$settings = Tuki_Settings::get();

		$css      = '';
		$css_path = TUKI_PLUGIN_DIR . 'public/tukify-widget.css';

		if ( is_readable( $css_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$css = (string) file_get_contents( $css_path );
		}

		$assistant = apply_filters( 'tuki_assistant_name', __( 'Tukify', 'tukify' ) );

		/**
		 * Exit-intent config. Filterable so an owner can attach a coupon offer, etc.
		 *
		 * @param array $exit Exit-intent settings passed to the widget.
		 */
		$exit_intent = apply_filters(
			'tuki_exit_intent',
			array(
				'enabled'       => (bool) $settings['exit_intent_enabled'],
				'mobile'        => (bool) $settings['exit_intent_mobile'],
				'cooldownHours' => (int) $settings['exit_intent_cooldown'],
				'msgBrowsing'   => '' !== $settings['exit_intent_msg_browsing']
					? $settings['exit_intent_msg_browsing']
					: __( 'Need help finding something? I can help you choose.', 'tukify' ),
				'msgCart'       => '' !== $settings['exit_intent_msg_cart']
					? $settings['exit_intent_msg_cart']
					: __( 'Want me to help you finish up? I can answer any questions.', 'tukify' ),
			)
		);

		/**
		 * Proactive re-engagement config. Fires after the shopper goes idle on a
		 * selected page (e.g. lingering on checkout, or with items in the cart).
		 * The current page context is resolved server-side so the widget never has
		 * to guess it. Filterable so an owner can vary the offer per context.
		 *
		 * @param array $reengage Re-engagement settings passed to the widget.
		 */
		$reengage = apply_filters(
			'tuki_reengage',
			array(
				'enabled'     => (bool) $settings['reengage_enabled'],
				'idleSeconds' => (int) $settings['reengage_idle_seconds'],
				'pages'       => array_values( (array) $settings['reengage_pages'] ),
				'context'     => self::page_context(),
				'message'     => '' !== $settings['reengage_message']
					? $settings['reengage_message']
					: __( 'Still deciding? I can answer any questions or help you check out.', 'tukify' ),
				'coupon'      => (string) $settings['reengage_coupon'],
			)
		);

		$checkout_enabled = class_exists( 'Tuki_Checkout' ) && Tuki_Checkout::is_enabled();

		return array(
			'restUrl'         => esc_url_raw( rest_url( 'tukify/v1/' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'floating'        => (bool) $settings['floating_widget'],
			'accent'          => $settings['accent_color'],
			'bubbleBg'        => $settings['bubble_bg_color'],
			'logo'            => esc_url_raw( Tuki_Settings::logo_url() ),
			'scheme'          => 'light' === $settings['color_scheme'] ? 'light' : 'dark',
			'css'             => $css,
			'maxImageBytes'   => 4194304,
			'upsellProactive' => (bool) $settings['upsell_enabled'] && (bool) $settings['upsell_proactive'],
			'exitIntent'      => $exit_intent,
			'reengage'        => $reengage,
			'checkout'        => array(
				// Only advertise in-chat checkout when the flag is on AND WooCommerce
				// is present; the widget hides the affordance otherwise.
				'enabled' => $checkout_enabled,
				// Dedicated nonce for the order-placing endpoint (belt-and-braces on
				// top of the REST cookie nonce).
				'nonce'   => wp_create_nonce( 'tuki_checkout' ),
				// Live cart state so the checkout bar renders correctly on first
				// paint (no dependency on chat history or a round trip).
				'count'   => $checkout_enabled ? Tuki_Cart::cart_count() : 0,
				'total'   => $checkout_enabled ? Tuki_Cart::cart_total_text() : '',
			),
			'stockNotify'     => array(
				'enabled' => class_exists( 'Tuki_Stock_Notify' ) && Tuki_Stock_Notify::is_enabled(),
			),
			'strings'         => array(
				'title'                 => $assistant,
				'subtitle'              => __( 'AI shopping assistant', 'tukify' ),
				'online'                => __( 'Online', 'tukify' ),
				'greeting'              => __( 'Hi! Tell me what you\'re looking for and I\'ll find it.', 'tukify' ),
				'placeholder'           => __( 'Ask about products…', 'tukify' ),
				'send'                  => __( 'Send', 'tukify' ),
				'open'                  => __( 'Open shopping assistant', 'tukify' ),
				'close'                 => __( 'Close', 'tukify' ),
				'addToCart'             => __( 'Add to cart', 'tukify' ),
				'adding'                => __( 'Adding…', 'tukify' ),
				'added'                 => __( 'Added', 'tukify' ),
				'outOfStock'            => __( 'Out of stock', 'tukify' ),
				'inStock'               => __( 'In stock', 'tukify' ),
				'viewProduct'           => __( 'View product', 'tukify' ),
				'quantity'              => __( 'Quantity', 'tukify' ),
				'increase'              => __( 'Increase quantity', 'tukify' ),
				'decrease'              => __( 'Decrease quantity', 'tukify' ),
				'showMore'              => __( 'Show more', 'tukify' ),
				'loading'               => __( 'Loading…', 'tukify' ),
				'searching'             => __( 'Searching…', 'tukify' ),
				'noResults'             => __( 'No matching products.', 'tukify' ),
				'image'                 => __( 'Upload an image', 'tukify' ),
				'imageSent'             => __( 'Sent an image', 'tukify' ),
				'imageSearching'        => __( 'Looking for similar products…', 'tukify' ),
				'lookClosest'           => __( 'Closest matches', 'tukify' ),
				'lookNone'              => __( 'No close match found for this item.', 'tukify' ),
				'lookItem'              => __( 'Item', 'tukify' ),
				'sizeIntro'             => __( 'Find your size', 'tukify' ),
				'sizeHeight'            => __( 'Height', 'tukify' ),
				'sizeWeight'            => __( 'Weight', 'tukify' ),
				'sizeCm'                => __( 'cm', 'tukify' ),
				'sizeIn'                => __( 'in', 'tukify' ),
				'sizeKg'                => __( 'kg', 'tukify' ),
				'sizeLb'                => __( 'lb', 'tukify' ),
				'sizeBrand'             => __( 'Your usual size in another brand (optional)', 'tukify' ),
				'sizeBrandPh'           => __( 'e.g. M, or 32', 'tukify' ),
				'sizeFit'               => __( 'Preferred fit', 'tukify' ),
				'sizeFitSlim'           => __( 'Slim / snug', 'tukify' ),
				'sizeFitRegular'        => __( 'Regular', 'tukify' ),
				'sizeFitLoose'          => __( 'Loose / relaxed', 'tukify' ),
				'sizeSubmit'            => __( 'Recommend my size', 'tukify' ),
				'sizeChecking'          => __( 'Checking…', 'tukify' ),
				'sizeRecommended'       => __( 'Recommended size', 'tukify' ),
				'imageTooLarge'         => __( 'That image is too large — please use one under 4 MB.', 'tukify' ),
				'imageBadType'          => __( 'Please upload a JPG, PNG, or WebP image.', 'tukify' ),
				'error'                 => __( 'Sorry, something went wrong. Please try again.', 'tukify' ),
				'empty'                 => __( 'I couldn\'t find a close match — try describing it differently.', 'tukify' ),
				'orderNumber'           => __( 'Order number', 'tukify' ),
				'orderEmail'            => __( 'Billing email', 'tukify' ),
				'orderEmailOptional'    => __( 'Not needed if the order is on your account', 'tukify' ),
				'orderNumPlaceholder'   => __( 'e.g. 12345', 'tukify' ),
				'orderEmailPlaceholder' => __( 'you@example.com', 'tukify' ),
				'orderCheck'            => __( 'Check order', 'tukify' ),
				'orderChecking'         => __( 'Checking…', 'tukify' ),
				'orderNeedNumber'       => __( 'Please enter your order number.', 'tukify' ),
				/* translators: %s: order number. */
				'orderTitle'            => __( 'Order #%s', 'tukify' ),
				'orderDateLabel'        => __( 'Ordered', 'tukify' ),
				'orderTotalLabel'       => __( 'Total', 'tukify' ),
				'orderItemsLabel'       => __( 'Items', 'tukify' ),
				'orderTrackingLabel'    => __( 'Tracking', 'tukify' ),
				'couponLabel'           => __( 'Here\'s a code for you', 'tukify' ),
				'couponCopy'            => __( 'Copy code', 'tukify' ),
				'couponCopied'          => __( 'Copied', 'tukify' ),
				'checkoutStart'         => __( 'Check out here', 'tukify' ),
				'checkoutTitle'         => __( 'Checkout', 'tukify' ),
				'checkoutReview'        => __( 'Your cart', 'tukify' ),
				'checkoutTotal'         => __( 'Total', 'tukify' ),
				'checkoutDetails'       => __( 'Shipping details', 'tukify' ),
				'checkoutFirstName'     => __( 'First name', 'tukify' ),
				'checkoutLastName'      => __( 'Last name', 'tukify' ),
				'checkoutEmail'         => __( 'Email', 'tukify' ),
				'checkoutPhone'         => __( 'Phone (optional)', 'tukify' ),
				'checkoutAddress1'      => __( 'Address', 'tukify' ),
				'checkoutAddress2'      => __( 'Apartment, suite, etc. (optional)', 'tukify' ),
				'checkoutCity'          => __( 'Town / City', 'tukify' ),
				'checkoutState'         => __( 'State / County', 'tukify' ),
				'checkoutPostcode'      => __( 'Postcode / ZIP', 'tukify' ),
				'checkoutCountry'       => __( 'Country', 'tukify' ),
				'checkoutContinue'      => __( 'Continue', 'tukify' ),
				'checkoutShipping'      => __( 'Shipping method', 'tukify' ),
				'checkoutPayment'       => __( 'Payment method', 'tukify' ),
				'checkoutPayNote'       => __( 'You\'ll finish payment securely on the next screen.', 'tukify' ),
				'checkoutPlace'         => __( 'Place order', 'tukify' ),
				'checkoutPlacing'       => __( 'Placing your order…', 'tukify' ),
				'checkoutRedirecting'   => __( 'Redirecting you to complete payment…', 'tukify' ),
				'checkoutPlaced'        => __( 'Order placed — thank you!', 'tukify' ),
				'checkoutViewOrder'     => __( 'View your order', 'tukify' ),
				'checkoutFallback'      => __( 'Let\'s finish on the secure checkout page.', 'tukify' ),
				'checkoutFallbackBtn'   => __( 'Go to checkout', 'tukify' ),
				'checkoutMustLogin'     => __( 'Please log in or use the full checkout page to continue.', 'tukify' ),
				'checkoutError'         => __( 'Please fix the highlighted fields.', 'tukify' ),
				'notifyStart'           => __( 'Notify me when it\'s back', 'tukify' ),
				'notifyEmailPh'         => __( 'you@example.com', 'tukify' ),
				/* translators: %s: store name. */
				'notifyConsent'         => __( 'Email me once when this item is back in stock. I can unsubscribe anytime.', 'tukify' ),
				'notifySubmit'          => __( 'Notify me', 'tukify' ),
				'notifySending'         => __( 'Saving…', 'tukify' ),
				'notifyNeedEmail'       => __( 'Please enter your email address.', 'tukify' ),
				'notifyNeedConsent'     => __( 'Please tick the box to continue.', 'tukify' ),
				'notifyDone'            => __( 'Done! We\'ll email you when it\'s back.', 'tukify' ),
				'sourcesLabel'          => __( 'Sources', 'tukify' ),
			),
		);
	}

	/**
	 * Resolves the current storefront page to a coarse context the widget uses
	 * to decide whether proactive re-engagement is allowed to fire here.
	 *
	 * @return string One of: checkout, cart, product, shop, other.
	 */
	private static function page_context() {
		// The order-received/thank-you page is a checkout endpoint but the shopper
		// has already converted, so it must never count as "lingering on checkout".
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return 'other';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		if (
			( function_exists( 'is_shop' ) && is_shop() ) ||
			( function_exists( 'is_product_category' ) && is_product_category() ) ||
			( function_exists( 'is_product_tag' ) && is_product_tag() )
		) {
			return 'shop';
		}

		return 'other';
	}
}
