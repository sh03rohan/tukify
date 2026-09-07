<?php
/**
 * Abandonment intervention.
 *
 * One proactive-nudge subsystem that consolidates what used to be two separate,
 * independently-configured features: exit intent and "proactive re-engagement".
 * Running both meant two cooldowns, two frequency caps, and two code paths that
 * could each open the chat, so a shopper could be nudged twice.
 *
 * There is now a single decision: three TRIGGERS (checkout idle, cart idle,
 * exit intent) feed one intervention, which fires at most once per session and
 * respects one frequency cap. The nudge opens the chat with a friction-aware
 * message and 2-3 quick-reply chips for the blockers that actually stall a
 * checkout — shipping cost, sizing, payment — each answered from real store
 * data (live cart, shipping zones, payment gateways) plus the knowledge base,
 * never from a canned string alone.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides when to intervene, and answers the blocker chips it offers.
 */
class Tuki_Intervention {

	/**
	 * Option flag recording that the legacy exit-intent / re-engagement settings
	 * have been folded into the intervention_* keys.
	 */
	const MIGRATED_OPTION = 'tuki_intervention_migrated';

	/**
	 * Blocker topics the quick-reply chips may cover.
	 */
	const TOPICS = array( 'shipping', 'sizing', 'payment' );

	/**
	 * Minimum KB similarity for a snippet to count as grounding an answer.
	 *
	 * Matches the bar Tuki_Chat::kb_response() uses, so a snippet that would not
	 * be cited in normal chat is not cited here either.
	 */
	const KB_MIN_SCORE = 0.3;

	/**
	 * Hooks the one-time legacy settings migration.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'migrate_legacy' ) );
	}

	/*
	 * Eligibility
	 */

	/**
	 * Whether the intervention is switched on at all.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Tuki_Settings::get( 'intervention_enabled' );
	}

	/**
	 * Whether the nudge must stay silent for the current user.
	 *
	 * Store staff work on their own storefront constantly — testing a checkout,
	 * checking a product page — and a chat window opening itself mid-task is pure
	 * noise. Anyone who can administer the site or the shop is exempt. This is
	 * decided server-side, so the trigger config is never even sent to them.
	 *
	 * @return bool
	 */
	public static function is_suppressed() {
		$suppressed = false;

		if ( is_user_logged_in() ) {
			foreach ( array( 'manage_options', 'manage_woocommerce', 'edit_shop_orders' ) as $capability ) {
				if ( current_user_can( $capability ) ) {
					$suppressed = true;
					break;
				}
			}
		}

		/**
		 * Filter whether the abandonment intervention is suppressed for this user.
		 *
		 * @param bool $suppressed True to stay silent.
		 */
		return (bool) apply_filters( 'tuki_intervention_suppressed', $suppressed );
	}

	/*
	 * Widget configuration
	 */

	/**
	 * The intervention payload handed to the widget.
	 *
	 * Everything the browser needs to decide *when* to fire lives here; the
	 * messages are already rendered (tokens resolved against the live cart) so
	 * the widget never has to reach back for them.
	 *
	 * @param string $context Current page context (checkout|cart|product|shop|other).
	 * @return array
	 */
	public static function config( $context ) {
		$settings = Tuki_Settings::get();

		// A suppressed user (store staff) gets an inert config: no triggers, no
		// messages. Nothing to switch on client-side.
		if ( ! self::is_enabled() || self::is_suppressed() ) {
			return array( 'enabled' => false );
		}

		$cart = self::cart_summary();

		$config = array(
			'enabled'        => true,
			'context'        => $context,
			// Per-trigger switches + thresholds.
			'checkout'       => (bool) $settings['intervention_checkout'],
			'checkoutIdle'   => (int) $settings['intervention_checkout_idle'],
			'cart'           => (bool) $settings['intervention_cart'],
			'cartIdle'       => (int) $settings['intervention_cart_idle'],
			'exit'           => (bool) $settings['intervention_exit'],
			'exitMobile'     => (bool) $settings['intervention_exit_mobile'],
			// Frequency cap, in hours, across visits for this browser.
			'frequencyHours' => (int) $settings['intervention_frequency_hours'],
			'messages'       => array(
				'checkout' => self::message( 'checkout', $cart ),
				'cart'     => self::message( 'cart', $cart ),
				'exit'     => self::message( 'exit', $cart ),
				'exitCart' => self::message( 'exit_cart', $cart ),
			),
			'chips'          => self::chips(),
			'chipsLabel'     => __( 'Common questions', 'tukify' ),
			'coupon'         => (string) $settings['intervention_coupon'],
		);

		/**
		 * Filter the abandonment-intervention config passed to the widget.
		 *
		 * Replaces the former `tuki_exit_intent` and `tuki_reengage` filters, which
		 * configured the two separate features this consolidates.
		 *
		 * @param array  $config  Intervention settings.
		 * @param string $context Current page context.
		 */
		return apply_filters( 'tuki_intervention', $config, $context );
	}

	/**
	 * The quick-reply chips offered with the nudge.
	 *
	 * Capped at three: past that the nudge stops reading as a helpful offer and
	 * starts reading as a menu.
	 *
	 * @return array List of [ 'topic' => string, 'label' => string ].
	 */
	public static function chips() {
		$enabled = (array) Tuki_Settings::get( 'intervention_chips' );
		$labels  = self::topic_labels();
		$chips   = array();

		foreach ( self::TOPICS as $topic ) {
			if ( ! in_array( $topic, $enabled, true ) ) {
				continue;
			}

			// Sizing help is only offered when the size advisor can actually answer.
			if ( 'sizing' === $topic && ! Tuki_Size::is_enabled() ) {
				continue;
			}

			$chips[] = array(
				'topic' => $topic,
				'label' => $labels[ $topic ],
			);
		}

		return array_slice( $chips, 0, 3 );
	}

	/**
	 * Human-readable chip labels, keyed by topic.
	 *
	 * @return array<string,string>
	 */
	public static function topic_labels() {
		return array(
			'shipping' => __( 'Shipping cost?', 'tukify' ),
			'sizing'   => __( 'Sizing help', 'tukify' ),
			'payment'  => __( 'Payment options', 'tukify' ),
		);
	}

	/*
	 * Messages
	 */

	/**
	 * Renders the nudge message for a trigger.
	 *
	 * The owner's template wins when set; otherwise a translated default is used.
	 * Templates may use {product}, {count} and {total} tokens. When a template
	 * needs {product} but the cart cannot supply one, the generic default for
	 * that trigger is used instead — so the shopper never sees a dangling token
	 * or an awkward empty phrase.
	 *
	 * @param string $reason One of checkout|cart|exit|exit_cart.
	 * @param array  $cart   Cart summary from cart_summary().
	 * @return string
	 */
	private static function message( $reason, array $cart ) {
		$settings = Tuki_Settings::get();

		$fields = array(
			'checkout'  => 'intervention_msg_checkout',
			'cart'      => 'intervention_msg_cart',
			'exit'      => 'intervention_msg_exit',
			'exit_cart' => 'intervention_msg_exit_cart',
		);

		$field    = isset( $fields[ $reason ] ) ? $fields[ $reason ] : 'intervention_msg_exit';
		$template = isset( $settings[ $field ] ) ? trim( (string) $settings[ $field ] ) : '';

		if ( '' === $template ) {
			$template = self::default_message( $reason, '' !== $cart['product'] );
		}

		// A template asking for a product name we do not have falls back to the
		// product-free default rather than rendering an empty gap.
		if ( '' === $cart['product'] && false !== strpos( $template, '{product}' ) ) {
			$template = self::default_message( $reason, false );
		}

		return strtr(
			$template,
			array(
				'{product}' => $cart['product'],
				'{count}'   => (string) $cart['count'],
				'{total}'   => $cart['total'],
			)
		);
	}

	/**
	 * The shipped default message for a trigger.
	 *
	 * Every default names the three friction points the chips answer, so the
	 * offer is concrete rather than a vague "can I help?".
	 *
	 * @param string $reason      One of checkout|cart|exit|exit_cart.
	 * @param bool   $has_product Whether a cart product name is available.
	 * @return string
	 */
	private static function default_message( $reason, $has_product ) {
		switch ( $reason ) {
			case 'checkout':
				return $has_product
					? __( 'Need a hand finishing up? I can help with shipping costs, sizing, or payment options — just ask!', 'tukify' )
					: __( 'Need a hand? I can help with shipping costs, sizing, or payment options — just ask!', 'tukify' );

			case 'cart':
				return $has_product
					? __( 'Still deciding on {product}? I can help with shipping costs, sizing, or payment options — just ask!', 'tukify' )
					: __( 'Still deciding? I can help with shipping costs, sizing, or payment options — just ask!', 'tukify' );

			case 'exit_cart':
				return $has_product
					? __( 'Before you go — I can help with shipping costs, sizing, or payment options for {product}. Just ask!', 'tukify' )
					: __( 'Before you go — I can help with shipping costs, sizing, or payment options. Just ask!', 'tukify' );

			default:
				return __( 'Need help finding something? I can help you choose — just ask!', 'tukify' );
		}
	}

	/**
	 * A small summary of the live cart for message tokens.
	 *
	 * @return array{product:string,count:int,total:string}
	 */
	private static function cart_summary() {
		$summary = array(
			'product' => '',
			'count'   => 0,
			'total'   => '',
		);

		if ( ! class_exists( 'Tuki_Cart' ) ) {
			return $summary;
		}

		$names = Tuki_Cart::get_cart_context();

		if ( ! empty( $names ) ) {
			// One name is enough to make the nudge feel specific; listing the whole
			// cart back at the shopper reads like a receipt, not a conversation.
			$summary['product'] = self::shorten( (string) $names[0], 40 );
		}

		$summary['count'] = Tuki_Cart::cart_count();
		$summary['total'] = Tuki_Cart::cart_total_text();

		return $summary;
	}

	/**
	 * Truncates a string on a word boundary, adding an ellipsis.
	 *
	 * @param string $text  Input.
	 * @param int    $chars Maximum length.
	 * @return string
	 */
	private static function shorten( $text, $chars ) {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( '' === $text || mb_strlen( $text ) <= $chars ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $chars );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > (int) ( $chars / 2 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return $cut . '…';
	}

	/*
	 * Chip answers — grounded in real store data
	 */

	/**
	 * Answers one blocker chip.
	 *
	 * Each answer is assembled from live WooCommerce state (cart, shipping zones,
	 * payment gateways) and grounded further with knowledge-base snippets, so it
	 * reflects this store rather than a generic template. No AI call is made:
	 * these are deterministic facts, and a model could only paraphrase them less
	 * accurately.
	 *
	 * @param string $topic One of shipping|sizing|payment.
	 * @return array{reply:string,sources:array,size_form?:array}
	 */
	public static function answer( $topic ) {
		$topic = sanitize_key( $topic );

		switch ( $topic ) {
			case 'shipping':
				return self::answer_shipping();

			case 'sizing':
				return self::answer_sizing();

			case 'payment':
				return self::answer_payment();
		}

		return array(
			'reply'   => __( 'Ask me anything about your order and I will help.', 'tukify' ),
			'sources' => array(),
		);
	}

	/**
	 * Shipping cost, from the live cart and the store's shipping zones.
	 *
	 * @return array
	 */
	private static function answer_shipping() {
		$lines = array();

		if ( class_exists( 'Tuki_Cart' ) && Tuki_Cart::ensure_cart_loaded() && WC()->cart ) {
			$cart = WC()->cart;

			if ( method_exists( $cart, 'needs_shipping' ) && ! $cart->needs_shipping() ) {
				$lines[] = __( 'Nothing in your cart needs shipping, so there is no delivery charge.', 'tukify' );
			} else {
				$cart->calculate_totals();

				$rate = self::cheapest_rate();

				if ( null !== $rate ) {
					$lines[] = ( 0.0 === $rate['cost'] )
						/* translators: %s: shipping method name. */
						? sprintf( __( 'Shipping is free on this order (%s).', 'tukify' ), $rate['label'] )
						/* translators: 1: shipping cost, 2: shipping method name. */
						: sprintf( __( 'Shipping on your current cart is %1$s (%2$s).', 'tukify' ), $rate['cost_text'], $rate['label'] );
				} else {
					$lines[] = __( 'Shipping is calculated once you enter your delivery address at checkout.', 'tukify' );
				}

				$threshold = self::free_shipping_threshold();

				if ( $threshold > 0 ) {
					$subtotal = (float) $cart->get_subtotal();
					$short    = $threshold - $subtotal;

					$lines[] = ( $short > 0 )
						/* translators: 1: remaining amount, 2: free-shipping threshold. */
						? sprintf( __( 'Add %1$s more and shipping becomes free (orders over %2$s ship free).', 'tukify' ), self::price( $short ), self::price( $threshold ) )
						/* translators: %s: free-shipping threshold. */
						: sprintf( __( 'Your order already qualifies for free shipping (orders over %s ship free).', 'tukify' ), self::price( $threshold ) );
				}
			}
		}

		if ( empty( $lines ) ) {
			$lines[] = __( 'Shipping is calculated at checkout once you enter your delivery address.', 'tukify' );
		}

		return self::ground( $lines, __( 'shipping cost delivery time', 'tukify' ) );
	}

	/**
	 * Sizing help — hands off to the existing size advisor when the cart holds a
	 * product with size variations, so the shopper gets a real recommendation
	 * rather than a link to a chart.
	 *
	 * @return array
	 */
	private static function answer_sizing() {
		$product = self::first_sizeable_cart_product();

		if ( $product ) {
			$payload = Tuki_Size::form_payload(
				$product->get_id(),
				/* translators: %s: product name. */
				sprintf( __( 'Happy to help you get the size right for %s. Answer these and I will recommend one.', 'tukify' ), $product->get_name() )
			);

			$grounded = self::ground( array( $payload['reply'] ), __( 'size guide fit measurements', 'tukify' ) );

			return array(
				'reply'     => $grounded['reply'],
				'sources'   => $grounded['sources'],
				'size_form' => $payload['size_form'],
			);
		}

		return self::ground(
			array( __( 'Tell me the product you are looking at and your usual size, and I will check the fit for you.', 'tukify' ) ),
			__( 'size guide fit measurements', 'tukify' )
		);
	}

	/**
	 * The payment methods this store actually accepts right now.
	 *
	 * @return array
	 */
	private static function answer_payment() {
		$titles = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
				$title = wp_strip_all_tags( (string) $gateway->get_title() );

				if ( '' !== trim( $title ) ) {
					$titles[] = $title;
				}
			}
		}

		$titles = array_values( array_unique( $titles ) );

		$lines = empty( $titles )
			? array( __( 'You will see every payment method this store accepts on the checkout page.', 'tukify' ) )
			: array(
				/* translators: %s: comma-separated list of payment method names. */
				sprintf( __( 'You can pay with %s.', 'tukify' ), self::list_text( $titles ) ),
				__( 'If a payment is declined, it is usually the card issuer rather than the store — trying another method normally works.', 'tukify' ),
			);

		return self::ground( $lines, __( 'payment methods accepted refunds', 'tukify' ) );
	}

	/**
	 * The cheapest shipping rate currently available for the cart.
	 *
	 * @return array{label:string,cost:float,cost_text:string}|null
	 */
	private static function cheapest_rate() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping ) {
			return null;
		}

		$best = null;

		foreach ( WC()->shipping()->get_packages() as $package ) {
			if ( empty( $package['rates'] ) ) {
				continue;
			}

			foreach ( $package['rates'] as $rate ) {
				$cost = (float) $rate->get_cost();

				if ( null === $best || $cost < $best['cost'] ) {
					$best = array(
						'label'     => wp_strip_all_tags( (string) $rate->get_label() ),
						'cost'      => $cost,
						'cost_text' => self::price( $cost ),
					);
				}
			}
		}

		return $best;
	}

	/**
	 * The lowest free-shipping order threshold configured across all zones.
	 *
	 * @return float Zero when no free-shipping method has a minimum amount.
	 */
	private static function free_shipping_threshold() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0.0;
		}

		$zone_ids = array( 0 );

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			if ( isset( $zone_data['id'] ) ) {
				$zone_ids[] = (int) $zone_data['id'];
			}
		}

		$minimums = array();

		foreach ( $zone_ids as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );

			if ( ! $zone ) {
				continue;
			}

			// true = enabled methods only.
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' !== $method->id || empty( $method->min_amount ) ) {
					continue;
				}

				$minimums[] = (float) $method->min_amount;
			}
		}

		return empty( $minimums ) ? 0.0 : min( $minimums );
	}

	/**
	 * The first cart product the size advisor can actually advise on.
	 *
	 * @return WC_Product|null
	 */
	private static function first_sizeable_cart_product() {
		if ( ! Tuki_Size::is_enabled() || ! class_exists( 'Tuki_Cart' ) || ! Tuki_Cart::ensure_cart_loaded() || ! WC()->cart ) {
			return null;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['product_id'] ) || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}

			// Always advise on the parent: a variation already has one size chosen,
			// and the advisor needs the full set of options to pick between.
			$product = wc_get_product( (int) $item['product_id'] );

			if ( $product && Tuki_Size::has_sizes( $product ) ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Appends knowledge-base grounding to a deterministic answer.
	 *
	 * The store's own policy pages carry the detail (delivery windows, refund
	 * terms) that WooCommerce settings cannot express, so the top KB snippet is
	 * cited alongside the hard numbers.
	 *
	 * @param array  $lines    Answer lines already assembled from store data.
	 * @param string $kb_query Query used to look for supporting policy content.
	 * @return array{reply:string,sources:array}
	 */
	private static function ground( array $lines, $kb_query ) {
		$sources = array();

		if ( class_exists( 'Tuki_KB' ) && Tuki_KB::is_available() ) {
			try {
				$kb       = new Tuki_KB();
				$snippets = $kb->search( $kb_query, 2 );
			} catch ( Exception $e ) {
				// Grounding is a bonus, never a dependency: a provider hiccup must not
				// cost the shopper the factual answer we already have.
				$snippets = array();
			}

			$seen = array();

			foreach ( $snippets as $snippet ) {
				if ( ! isset( $snippet['score'] ) || (float) $snippet['score'] < self::KB_MIN_SCORE || empty( $snippet['url'] ) ) {
					continue;
				}

				$key = strtolower( trim( (string) $snippet['url'] ) );

				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$sources[] = array(
					'title' => ( '' !== trim( (string) $snippet['title'] ) ) ? (string) $snippet['title'] : (string) $snippet['url'],
					'url'   => (string) $snippet['url'],
				);
			}
		}

		// The owner can hide citations globally; honour that here too.
		if ( ! Tuki_Settings::get( 'show_citations' ) ) {
			$sources = array();
		}

		return array(
			'reply'   => implode( ' ', array_filter( $lines ) ),
			'sources' => $sources,
		);
	}

	/**
	 * Formats an amount as plain-text currency (entities decoded).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function price( $amount ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}

		return Tuki_Cart::price_text( wc_price( (float) $amount ) );
	}

	/**
	 * Joins a list into readable prose ("a, b and c").
	 *
	 * @param array $items Items.
	 * @return string
	 */
	private static function list_text( array $items ) {
		$items = array_values( array_filter( $items ) );
		$count = count( $items );

		if ( $count <= 1 ) {
			return $count ? $items[0] : '';
		}

		$last = array_pop( $items );

		/* translators: 1: comma-separated list, 2: final item. */
		return sprintf( __( '%1$s and %2$s', 'tukify' ), implode( ', ', $items ), $last );
	}

	/*
	 * Migration
	 */

	/**
	 * Folds the legacy exit-intent and re-engagement settings into intervention_*.
	 *
	 * Runs once. An existing store keeps the behaviour it configured: its exit
	 * cooldown becomes the single frequency cap, and whichever pages it had
	 * selected for re-engagement decide which idle triggers stay on.
	 *
	 * @return void
	 */
	public static function migrate_legacy() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$saved = get_option( Tuki_Settings::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// Nothing saved yet (fresh install): the defaults already are the new
		// scheme, so just mark the migration done.
		if ( empty( $saved ) ) {
			update_option( self::MIGRATED_OPTION, 1, false );
			return;
		}

		$legacy_pages = isset( $saved['reengage_pages'] ) ? (array) $saved['reengage_pages'] : array();
		$any_page     = in_array( 'any', $legacy_pages, true );
		$reengage_on  = ! empty( $saved['reengage_enabled'] );
		$legacy_idle  = isset( $saved['reengage_idle_seconds'] ) ? absint( $saved['reengage_idle_seconds'] ) : 0;

		$map = array(
			// Exit intent carries over directly.
			'intervention_exit'            => isset( $saved['exit_intent_enabled'] ) ? ( empty( $saved['exit_intent_enabled'] ) ? 0 : 1 ) : 1,
			'intervention_exit_mobile'     => isset( $saved['exit_intent_mobile'] ) ? ( empty( $saved['exit_intent_mobile'] ) ? 0 : 1 ) : 1,
			// The old exit cooldown becomes the one frequency cap.
			'intervention_frequency_hours' => isset( $saved['exit_intent_cooldown'] ) ? absint( $saved['exit_intent_cooldown'] ) : 24,
			'intervention_msg_exit'        => isset( $saved['exit_intent_msg_browsing'] ) ? (string) $saved['exit_intent_msg_browsing'] : '',
			'intervention_msg_exit_cart'   => isset( $saved['exit_intent_msg_cart'] ) ? (string) $saved['exit_intent_msg_cart'] : '',
			// Re-engagement's page list becomes the two idle triggers.
			'intervention_checkout'        => ( $reengage_on && ( $any_page || in_array( 'checkout', $legacy_pages, true ) ) ) ? 1 : 0,
			'intervention_cart'            => ( $reengage_on && ( $any_page || in_array( 'cart', $legacy_pages, true ) ) ) ? 1 : 0,
			'intervention_msg_checkout'    => isset( $saved['reengage_message'] ) ? (string) $saved['reengage_message'] : '',
			'intervention_msg_cart'        => isset( $saved['reengage_message'] ) ? (string) $saved['reengage_message'] : '',
			'intervention_coupon'          => isset( $saved['reengage_coupon'] ) ? (string) $saved['reengage_coupon'] : '',
		);

		// One idle threshold became two; seed both from the old value so timing
		// does not change unexpectedly, then let the owner tune them apart.
		if ( $legacy_idle > 0 ) {
			$map['intervention_checkout_idle'] = $legacy_idle;
			$map['intervention_cart_idle']     = $legacy_idle;
		}

		// A store that had exit intent on but never enabled re-engagement keeps the
		// feature switched on overall; one that had disabled both stays quiet.
		$map['intervention_enabled'] = ( ! empty( $map['intervention_exit'] ) || $reengage_on ) ? 1 : 0;

		foreach ( $map as $key => $value ) {
			// Never clobber a value the owner has already set under the new scheme.
			if ( ! array_key_exists( $key, $saved ) ) {
				$saved[ $key ] = $value;
			}
		}

		// Drop the legacy keys so the two schemes cannot drift apart.
		foreach ( array( 'exit_intent_enabled', 'exit_intent_mobile', 'exit_intent_cooldown', 'exit_intent_msg_browsing', 'exit_intent_msg_cart', 'reengage_enabled', 'reengage_idle_seconds', 'reengage_pages', 'reengage_message', 'reengage_coupon' ) as $legacy_key ) {
			unset( $saved[ $legacy_key ] );
		}

		update_option( Tuki_Settings::OPTION, $saved );
		update_option( self::MIGRATED_OPTION, 1, false );
	}
}
