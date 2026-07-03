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

		return array(
			'restUrl'       => esc_url_raw( rest_url( 'tukify/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'floating'      => (bool) $settings['floating_widget'],
			'accent'        => $settings['accent_color'],
			'scheme'          => 'light' === $settings['color_scheme'] ? 'light' : 'dark',
			'css'             => $css,
			'maxImageBytes'   => 4194304,
			'upsellProactive' => (bool) $settings['upsell_enabled'] && (bool) $settings['upsell_proactive'],
			'exitIntent'      => $exit_intent,
			'strings'   => array(
				'title'       => $assistant,
				'subtitle'    => __( 'AI shopping assistant', 'tukify' ),
				'online'      => __( 'Online', 'tukify' ),
				'greeting'    => __( 'Hi! Tell me what you\'re looking for and I\'ll find it.', 'tukify' ),
				'placeholder' => __( 'Ask about products…', 'tukify' ),
				'send'        => __( 'Send', 'tukify' ),
				'open'        => __( 'Open shopping assistant', 'tukify' ),
				'close'       => __( 'Close', 'tukify' ),
				'addToCart'   => __( 'Add to cart', 'tukify' ),
				'adding'      => __( 'Adding…', 'tukify' ),
				'added'       => __( 'Added', 'tukify' ),
				'outOfStock'  => __( 'Out of stock', 'tukify' ),
				'inStock'     => __( 'In stock', 'tukify' ),
				'quantity'    => __( 'Quantity', 'tukify' ),
				'increase'    => __( 'Increase quantity', 'tukify' ),
				'decrease'    => __( 'Decrease quantity', 'tukify' ),
				'showMore'    => __( 'Show more', 'tukify' ),
				'loading'     => __( 'Loading…', 'tukify' ),
				'searching'   => __( 'Searching…', 'tukify' ),
				'noResults'   => __( 'No matching products.', 'tukify' ),
				'image'       => __( 'Upload an image', 'tukify' ),
				'imageSent'   => __( 'Sent an image', 'tukify' ),
				'imageSearching' => __( 'Looking for similar products…', 'tukify' ),
				'imageTooLarge'  => __( 'That image is too large — please use one under 4 MB.', 'tukify' ),
				'imageBadType'   => __( 'Please upload a JPG, PNG, or WebP image.', 'tukify' ),
				'error'       => __( 'Sorry, something went wrong. Please try again.', 'tukify' ),
				'empty'       => __( 'I couldn\'t find a close match — try describing it differently.', 'tukify' ),
				'orderNumber'          => __( 'Order number', 'tukify' ),
				'orderEmail'           => __( 'Billing email', 'tukify' ),
				'orderEmailOptional'   => __( 'Not needed if the order is on your account', 'tukify' ),
				'orderNumPlaceholder'  => __( 'e.g. 12345', 'tukify' ),
				'orderEmailPlaceholder' => __( 'you@example.com', 'tukify' ),
				'orderCheck'           => __( 'Check order', 'tukify' ),
				'orderChecking'        => __( 'Checking…', 'tukify' ),
				'orderNeedNumber'      => __( 'Please enter your order number.', 'tukify' ),
				/* translators: %s: order number. */
				'orderTitle'           => __( 'Order #%s', 'tukify' ),
				'orderDateLabel'       => __( 'Ordered', 'tukify' ),
				'orderTotalLabel'      => __( 'Total', 'tukify' ),
				'orderItemsLabel'      => __( 'Items', 'tukify' ),
				'orderTrackingLabel'   => __( 'Tracking', 'tukify' ),
			),
		);
	}
}
