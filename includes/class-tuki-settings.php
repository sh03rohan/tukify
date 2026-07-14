<?php
/**
 * Settings storage + registration.
 *
 * Owns the single `tuki_settings` option: defaults, retrieval, sanitization,
 * and Settings API registration. Also acts as the provider factory since it
 * holds the configuration.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Tukify's global configuration.
 */
class Tuki_Settings {

	/**
	 * Option name that stores the full settings array.
	 */
	const OPTION = 'tuki_settings';

	/**
	 * Settings API group / page slug.
	 */
	const GROUP = 'tuki_settings_group';

	/**
	 * Registers the option with the Settings API.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Returns the built-in default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'chat_provider'              => 'gemini',
			'embedding_provider'         => 'gemini',
			'gemini_api_key'             => '',
			'openai_api_key'             => '',
			'anthropic_api_key'          => '',
			'xai_api_key'                => '',
			'chat_model_gemini'          => 'gemini-2.5-flash',
			'chat_model_openai'          => 'gpt-4o-mini',
			'chat_model_anthropic'       => 'claude-3-5-haiku-latest',
			'chat_model_grok'            => 'grok-2-latest',
			'embedding_model_gemini'     => 'gemini-embedding-001',
			'embedding_model_openai'     => 'text-embedding-3-small',
			'retrieval_count'            => 5,
			'relevance_threshold'        => 0.6,
			'browse_batch_size'          => 10,
			'clarify_enabled'            => 1,
			'clarify_max'                => 2,
			'hybrid_filtering'           => 1,
			'kb_enabled'                 => 1,
			'kb_wc_policies'             => 1,
			'kb_post_types'              => array( 'post', 'page', 'product' ),
			'kb_pages'                   => array(),
			'kb_qa'                      => array(),
			'show_citations'             => 1,
			'upsell_enabled'             => 1,
			'upsell_max'                 => 3,
			'upsell_proactive'           => 1,
			'size_advisor_enabled'       => 1,
			'size_unit'                  => 'metric',
			'size_attribute'             => 'size',
			'size_charts'                => array(),
			'exit_intent_enabled'        => 1,
			'exit_intent_mobile'         => 1,
			'exit_intent_cooldown'       => 24,
			'exit_intent_msg_browsing'   => '',
			'exit_intent_msg_cart'       => '',
			'reengage_enabled'           => 0,
			'reengage_idle_seconds'      => 45,
			'reengage_pages'             => array( 'checkout', 'cart' ),
			'reengage_message'           => '',
			'reengage_coupon'            => '',
			'checkout_in_chat'           => 0,
			'stock_notify_enabled'       => 1,
			'stock_notify_subject'       => '',
			'stock_notify_body'          => '',
			'guest_rate_limit'           => 20,
			'demand_logging'             => 1,
			'demand_retention_days'      => 90,
			'cache_enabled'              => 1,
			'cache_ttl_hours'            => 24,
			'price_chat_input'           => 0.30,
			'price_chat_output'          => 2.50,
			'price_embedding'            => 0.15,
			'accent_color'               => '#7C6FF0',
			'bubble_bg_color'            => '#7C6FF0',
			'color_scheme'               => 'dark',
			'floating_widget'            => 0,
			'analytics_enabled'          => 1,
			'preserve_data_on_uninstall' => 0,
		);
	}

	/**
	 * Returns the merged settings (saved values over defaults).
	 *
	 * @param string|null $key Optional single key to return.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$merged = wp_parse_args( $saved, self::defaults() );

		if ( null !== $key ) {
			return isset( $merged[ $key ] ) ? $merged[ $key ] : null;
		}

		return $merged;
	}

	/**
	 * Masks an API key for display: keeps only the last four characters.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return '';
		}

		return '••••••••' . substr( $key, -4 );
	}

	/**
	 * Registers the option and its sanitizer.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitizes submitted settings.
	 *
	 * API-key fields left blank keep their previously saved value, so the masked
	 * display never overwrites a real key with an empty string.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = self::get();
		$out      = self::defaults();

		$registry = self::providers_registry();

		// Chat provider: any registered provider. Embedding provider: only the two
		// that actually have an embeddings endpoint.
		$out['chat_provider'] = isset( $registry[ $input['chat_provider'] ?? '' ] ) ? $input['chat_provider'] : 'gemini';

		$embedding_providers       = self::embedding_providers();
		$out['embedding_provider'] = in_array( ( $input['embedding_provider'] ?? '' ), array_keys( $embedding_providers ), true )
			? $input['embedding_provider']
			: 'gemini';

		// API keys: one per provider; a blank field keeps the saved key.
		foreach ( $registry as $meta ) {
			$key_field         = $meta['key'];
			$value             = isset( $input[ $key_field ] ) ? trim( sanitize_text_field( $input[ $key_field ] ) ) : '';
			$out[ $key_field ] = ( '' === $value ) ? $existing[ $key_field ] : $value;
		}

		// Per-provider chat models — whitelisted against the registry.
		foreach ( $registry as $provider => $meta ) {
			$field         = 'chat_model_' . $provider;
			$submitted     = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
			$out[ $field ] = isset( $meta['chat_models'][ $submitted ] ) ? $submitted : $meta['default_chat'];
		}

		// Per-provider embedding models (only for embedding-capable providers).
		foreach ( $embedding_providers as $provider => $meta ) {
			$field         = 'embedding_model_' . $provider;
			$submitted     = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
			$out[ $field ] = isset( $meta['embedding_models'][ $submitted ] ) ? $submitted : $meta['default_embedding'];
		}

		$out['retrieval_count'] = min( 20, max( 1, absint( $input['retrieval_count'] ?? 5 ) ) );

		$threshold                  = isset( $input['relevance_threshold'] ) ? (float) $input['relevance_threshold'] : 0.6;
		$out['relevance_threshold'] = min( 1, max( 0, $threshold ) );

		$out['browse_batch_size'] = min( 24, max( 4, absint( $input['browse_batch_size'] ?? 10 ) ) );

		$out['clarify_enabled']  = empty( $input['clarify_enabled'] ) ? 0 : 1;
		$out['clarify_max']      = min( 3, max( 1, absint( $input['clarify_max'] ?? 2 ) ) );
		$out['hybrid_filtering'] = empty( $input['hybrid_filtering'] ) ? 0 : 1;

		$out['kb_enabled']     = empty( $input['kb_enabled'] ) ? 0 : 1;
		$out['kb_wc_policies'] = empty( $input['kb_wc_policies'] ) ? 0 : 1;
		$out['show_citations'] = empty( $input['show_citations'] ) ? 0 : 1;

		// Whitelist submitted post types against the site's public post types so
		// only real, indexable content types can be enabled.
		$out['kb_post_types'] = array();
		if ( isset( $input['kb_post_types'] ) && is_array( $input['kb_post_types'] ) ) {
			$allowed              = self::indexable_post_types();
			$out['kb_post_types'] = array_values(
				array_intersect( $allowed, array_map( 'sanitize_key', $input['kb_post_types'] ) )
			);
		}

		$out['kb_pages'] = array();
		if ( isset( $input['kb_pages'] ) && is_array( $input['kb_pages'] ) ) {
			$out['kb_pages'] = array_values( array_unique( array_filter( array_map( 'absint', $input['kb_pages'] ) ) ) );
		}

		$out['kb_qa'] = array();
		if ( isset( $input['kb_qa'] ) && is_array( $input['kb_qa'] ) ) {
			foreach ( $input['kb_qa'] as $pair ) {
				$question = isset( $pair['q'] ) ? sanitize_text_field( $pair['q'] ) : '';
				$answer   = isset( $pair['a'] ) ? sanitize_textarea_field( $pair['a'] ) : '';

				if ( '' !== trim( $question ) && '' !== trim( $answer ) ) {
					$out['kb_qa'][] = array(
						'q' => $question,
						'a' => $answer,
					);
				}
			}
		}

		$out['upsell_enabled']   = empty( $input['upsell_enabled'] ) ? 0 : 1;
		$out['upsell_max']       = min( 4, max( 1, absint( $input['upsell_max'] ?? 3 ) ) );
		$out['upsell_proactive'] = empty( $input['upsell_proactive'] ) ? 0 : 1;

		$out['size_advisor_enabled'] = empty( $input['size_advisor_enabled'] ) ? 0 : 1;
		$out['size_unit']            = in_array( ( $input['size_unit'] ?? '' ), array( 'metric', 'imperial' ), true ) ? $input['size_unit'] : 'metric';
		$out['size_attribute']       = sanitize_text_field( $input['size_attribute'] ?? 'size' );
		if ( '' === trim( $out['size_attribute'] ) ) {
			$out['size_attribute'] = 'size';
		}

		$out['size_charts'] = array();
		if ( isset( $input['size_charts'] ) && is_array( $input['size_charts'] ) ) {
			foreach ( $input['size_charts'] as $size_row ) {
				if ( ! is_array( $size_row ) ) {
					continue;
				}

				$size_cat   = isset( $size_row['category'] ) ? absint( $size_row['category'] ) : 0;
				$size_label = isset( $size_row['label'] ) ? sanitize_text_field( $size_row['label'] ) : '';

				if ( $size_cat <= 0 || '' === trim( $size_label ) ) {
					continue;
				}

				$out['size_charts'][] = array(
					'category' => $size_cat,
					'label'    => $size_label,
					'h_min'    => isset( $size_row['h_min'] ) ? max( 0, (float) $size_row['h_min'] ) : 0,
					'h_max'    => isset( $size_row['h_max'] ) ? max( 0, (float) $size_row['h_max'] ) : 0,
					'w_min'    => isset( $size_row['w_min'] ) ? max( 0, (float) $size_row['w_min'] ) : 0,
					'w_max'    => isset( $size_row['w_max'] ) ? max( 0, (float) $size_row['w_max'] ) : 0,
				);
			}
		}

		$out['exit_intent_enabled']      = empty( $input['exit_intent_enabled'] ) ? 0 : 1;
		$out['exit_intent_mobile']       = empty( $input['exit_intent_mobile'] ) ? 0 : 1;
		$out['exit_intent_cooldown']     = min( 720, max( 0, absint( $input['exit_intent_cooldown'] ?? 24 ) ) );
		$out['exit_intent_msg_browsing'] = isset( $input['exit_intent_msg_browsing'] ) ? sanitize_text_field( $input['exit_intent_msg_browsing'] ) : '';
		$out['exit_intent_msg_cart']     = isset( $input['exit_intent_msg_cart'] ) ? sanitize_text_field( $input['exit_intent_msg_cart'] ) : '';

		// Proactive re-engagement (idle/dwell nudge). Distinct from exit intent:
		// fires after inactivity on selected pages, once per session.
		$out['reengage_enabled']      = empty( $input['reengage_enabled'] ) ? 0 : 1;
		$out['reengage_idle_seconds'] = min( 600, max( 5, absint( $input['reengage_idle_seconds'] ?? 45 ) ) );

		// Whitelist page contexts against the ones the widget understands.
		$out['reengage_pages'] = array();
		if ( isset( $input['reengage_pages'] ) && is_array( $input['reengage_pages'] ) ) {
			$allowed_pages         = array( 'checkout', 'cart', 'product', 'shop', 'any' );
			$out['reengage_pages'] = array_values(
				array_intersect( $allowed_pages, array_map( 'sanitize_key', $input['reengage_pages'] ) )
			);
		}

		$out['reengage_message'] = isset( $input['reengage_message'] ) ? sanitize_text_field( $input['reengage_message'] ) : '';

		$reengage_coupon        = isset( $input['reengage_coupon'] ) ? sanitize_text_field( $input['reengage_coupon'] ) : '';
		$out['reengage_coupon'] = function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $reengage_coupon ) : $reengage_coupon;

		// In-chat checkout is a sensitive flow (creates real orders), so it is an
		// explicit opt-in and defaults off.
		$out['checkout_in_chat'] = empty( $input['checkout_in_chat'] ) ? 0 : 1;

		// Back-in-stock notifications. The email template keeps its {tokens}; only
		// tags/scripts are stripped so the tokens survive sanitization.
		$out['stock_notify_enabled'] = empty( $input['stock_notify_enabled'] ) ? 0 : 1;
		$out['stock_notify_subject'] = isset( $input['stock_notify_subject'] ) ? sanitize_text_field( $input['stock_notify_subject'] ) : '';
		$out['stock_notify_body']    = isset( $input['stock_notify_body'] ) ? sanitize_textarea_field( $input['stock_notify_body'] ) : '';

		$out['guest_rate_limit'] = min( 1000, max( 1, absint( $input['guest_rate_limit'] ?? 20 ) ) );

		$out['demand_logging']        = empty( $input['demand_logging'] ) ? 0 : 1;
		$out['demand_retention_days'] = min( 3650, max( 0, absint( $input['demand_retention_days'] ?? 90 ) ) );

		// Caching + cost estimation.
		$out['cache_enabled']   = empty( $input['cache_enabled'] ) ? 0 : 1;
		$out['cache_ttl_hours'] = min( 720, max( 1, absint( $input['cache_ttl_hours'] ?? 24 ) ) );

		foreach ( array( 'price_chat_input', 'price_chat_output', 'price_embedding' ) as $price_field ) {
			$out[ $price_field ] = isset( $input[ $price_field ] ) ? max( 0, (float) $input[ $price_field ] ) : $out[ $price_field ];
		}
		$out['accent_color']      = sanitize_hex_color( $input['accent_color'] ?? '' );
		$out['bubble_bg_color']   = sanitize_hex_color( $input['bubble_bg_color'] ?? '' );
		$out['color_scheme']      = in_array( ( $input['color_scheme'] ?? '' ), array( 'dark', 'light' ), true ) ? $input['color_scheme'] : 'dark';
		$out['floating_widget']   = empty( $input['floating_widget'] ) ? 0 : 1;
		$out['analytics_enabled'] = empty( $input['analytics_enabled'] ) ? 0 : 1;

		$out['preserve_data_on_uninstall'] = empty( $input['preserve_data_on_uninstall'] ) ? 0 : 1;

		// Fall back to the defaults when a colour was cleared or invalid.
		if ( empty( $out['accent_color'] ) ) {
			$out['accent_color'] = '#7C6FF0';
		}
		if ( empty( $out['bubble_bg_color'] ) ) {
			$out['bubble_bg_color'] = '#7C6FF0';
		}

		return $out;
	}

	/**
	 * URL of the Tukify logo SVG shipped with the plugin.
	 *
	 * The file has a transparent background, so it renders cleanly inside the
	 * round chat launcher/avatar and the admin header chips.
	 *
	 * @return string
	 */
	public static function logo_url() {
		return TUKI_PLUGIN_URL . 'images/logo.svg';
	}

	/**
	 * The public post types that may be indexed into the knowledge base.
	 *
	 * Excludes attachments (media) and any non-public types. Used by the settings
	 * whitelist, the KB indexer, and the admin selector so they never disagree.
	 *
	 * @return array List of post-type slugs.
	 */
	public static function indexable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types['attachment'] );

		return array_values( $types );
	}

	/*
	 * AI provider registry + factory
	 */

	/**
	 * The AI provider catalog: label, implementation class, key field,
	 * capabilities, and the selectable chat/embedding models per provider.
	 *
	 * This is the single place to add a provider or update its model list.
	 *
	 * @return array
	 */
	public static function providers_registry() {
		$registry = array(
			'gemini'    => array(
				'label'             => __( 'Google Gemini', 'tukify' ),
				'class'             => 'Tuki_Provider_Gemini',
				'key'               => 'gemini_api_key',
				'caps'              => array( 'chat', 'embeddings', 'vision' ),
				'chat_models'       => array(
					'gemini-2.5-flash'      => 'gemini-2.5-flash',
					'gemini-2.5-flash-lite' => 'gemini-2.5-flash-lite (economy)',
					'gemini-2.5-pro'        => 'gemini-2.5-pro',
				),
				'embedding_models'  => array(
					'gemini-embedding-001' => 'gemini-embedding-001',
				),
				'default_chat'      => 'gemini-2.5-flash',
				'default_embedding' => 'gemini-embedding-001',
				'key_hint'          => 'aistudio.google.com',
			),
			'openai'    => array(
				'label'             => __( 'OpenAI (ChatGPT)', 'tukify' ),
				'class'             => 'Tuki_Provider_OpenAI',
				'key'               => 'openai_api_key',
				'caps'              => array( 'chat', 'embeddings', 'vision' ),
				'chat_models'       => array(
					'gpt-4o-mini'  => 'gpt-4o-mini (fast)',
					'gpt-4o'       => 'gpt-4o',
					'gpt-4.1-mini' => 'gpt-4.1-mini',
					'gpt-4.1'      => 'gpt-4.1',
				),
				'embedding_models'  => array(
					'text-embedding-3-small' => 'text-embedding-3-small',
					'text-embedding-3-large' => 'text-embedding-3-large',
				),
				'default_chat'      => 'gpt-4o-mini',
				'default_embedding' => 'text-embedding-3-small',
				'key_hint'          => 'platform.openai.com',
			),
			'anthropic' => array(
				'label'        => __( 'Anthropic (Claude)', 'tukify' ),
				'class'        => 'Tuki_Provider_Anthropic',
				'key'          => 'anthropic_api_key',
				'caps'         => array( 'chat', 'vision' ),
				'chat_models'  => array(
					'claude-3-5-haiku-latest'  => 'claude-3-5-haiku (fast)',
					'claude-3-5-sonnet-latest' => 'claude-3-5-sonnet',
					'claude-sonnet-4-5'        => 'claude-sonnet-4-5',
				),
				'default_chat' => 'claude-3-5-haiku-latest',
				'key_hint'     => 'console.anthropic.com',
			),
			'grok'      => array(
				'label'        => __( 'xAI (Grok)', 'tukify' ),
				'class'        => 'Tuki_Provider_Grok',
				'key'          => 'xai_api_key',
				'caps'         => array( 'chat', 'vision' ),
				'chat_models'  => array(
					'grok-2-latest'      => 'grok-2-latest',
					'grok-2-vision-1212' => 'grok-2-vision-1212 (image input)',
					'grok-3'             => 'grok-3',
				),
				'default_chat' => 'grok-2-latest',
				'key_hint'     => 'console.x.ai',
			),
		);

		/**
		 * Filter the AI provider registry (add providers or edit model lists).
		 *
		 * @param array $registry Provider definitions.
		 */
		return apply_filters( 'tuki_providers_registry', $registry );
	}

	/**
	 * Registry entries that support embeddings (Gemini, OpenAI).
	 *
	 * @return array
	 */
	public static function embedding_providers() {
		$out = array();

		foreach ( self::providers_registry() as $id => $meta ) {
			if ( in_array( 'embeddings', $meta['caps'], true ) ) {
				$out[ $id ] = $meta;
			}
		}

		return $out;
	}

	/**
	 * Whether a provider supports a capability, per the registry.
	 *
	 * @param string $provider   Provider id.
	 * @param string $capability 'chat' | 'embeddings' | 'vision'.
	 * @return bool
	 */
	public static function provider_supports( $provider, $capability ) {
		$registry = self::providers_registry();

		return isset( $registry[ $provider ] ) && in_array( $capability, $registry[ $provider ]['caps'], true );
	}

	/**
	 * The selected chat provider id.
	 *
	 * @return string
	 */
	public static function chat_provider() {
		$provider = (string) self::get( 'chat_provider' );
		$registry = self::providers_registry();

		return isset( $registry[ $provider ] ) ? $provider : 'gemini';
	}

	/**
	 * The selected embedding provider id (always embeddings-capable).
	 *
	 * @return string
	 */
	public static function embedding_provider() {
		$provider = (string) self::get( 'embedding_provider' );

		return self::provider_supports( $provider, 'embeddings' ) ? $provider : 'gemini';
	}

	/**
	 * The active embedding model (for the selected embedding provider).
	 *
	 * @return string
	 */
	public static function embedding_model() {
		$provider = self::embedding_provider();

		return (string) self::get( 'embedding_model_' . $provider );
	}

	/**
	 * A signature identifying the current embedding space (provider + model).
	 * Vectors built under a different signature are dimensionally incompatible.
	 *
	 * @return string
	 */
	public static function embedding_signature() {
		return self::embedding_provider() . ':' . self::embedding_model();
	}

	/**
	 * Instantiates the selected CHAT provider.
	 *
	 * @return Tuki_AI_Provider|null
	 */
	public static function make_chat_provider() {
		return self::instantiate( self::chat_provider() );
	}

	/**
	 * Instantiates the selected EMBEDDING provider (Gemini or OpenAI).
	 *
	 * @return Tuki_AI_Provider|null
	 */
	public static function make_embedding_provider() {
		return self::instantiate( self::embedding_provider() );
	}

	/**
	 * Instantiates a vision-capable provider: the chat provider if it supports
	 * vision, otherwise a configured fallback (embedding provider, then Gemini),
	 * or null if none can do vision.
	 *
	 * @return Tuki_AI_Provider|null
	 */
	public static function make_vision_provider() {
		$chat = self::make_chat_provider();

		if ( $chat && $chat->supports( 'vision' ) ) {
			return $chat;
		}

		foreach ( array( self::embedding_provider(), 'gemini', 'openai' ) as $fallback ) {
			$provider = self::instantiate( $fallback );

			if ( $provider && $provider->supports( 'vision' ) && '' !== self::provider_key( $fallback ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Instantiates an arbitrary provider by id, with an optional key override
	 * (used by "Test connection" to try a not-yet-saved key).
	 *
	 * @param string      $provider     Provider id.
	 * @param string|null $key_override Optional key to use instead of the saved one.
	 * @return Tuki_AI_Provider|null
	 */
	public static function instantiate( $provider, $key_override = null ) {
		$registry = self::providers_registry();

		if ( ! isset( $registry[ $provider ] ) || ! class_exists( $registry[ $provider ]['class'] ) ) {
			return null;
		}

		$meta = $registry[ $provider ];
		$key  = ( null !== $key_override && '' !== trim( (string) $key_override ) )
			? trim( (string) $key_override )
			: self::provider_key( $provider );

		$chat_model      = (string) self::get( 'chat_model_' . $provider );
		$embedding_model = in_array( 'embeddings', $meta['caps'], true ) ? (string) self::get( 'embedding_model_' . $provider ) : '';

		return new $meta['class']( $key, $chat_model, $embedding_model );
	}

	/**
	 * The stored API key for a provider.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function provider_key( $provider ) {
		$registry = self::providers_registry();

		return isset( $registry[ $provider ] ) ? (string) self::get( $registry[ $provider ]['key'] ) : '';
	}
}
