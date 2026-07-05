<?php
/**
 * Tukify settings view — full-width, tabbed layout.
 *
 * Rendered by Tuki_Admin::render_settings_page(). Persists via the Settings API
 * (options.php): every field lives inside one <form>, and tabs only toggle which
 * panel is visible (CSS display), so hidden tabs' values still submit. "Test
 * connection", "Search test" and "Reindex" run through AJAX (admin.js).
 *
 * Styled by admin-dark.css (dark "Midnight blue" theme) and driven by
 * admin-tabs.js (tab switching), both scoped to this screen. Field name=""
 * attributes and the AJAX element IDs/classes are a contract with
 * Tuki_Settings::sanitize() and admin.js — keep them stable across redesigns.
 *
 * @package Tukify
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tuki_settings   = Tuki_Settings::get();
$tuki_option     = Tuki_Settings::OPTION;
$tuki_gemini_key = $tuki_settings['gemini_api_key'];
$tuki_conn       = Tuki_Admin::connection_state();

$tuki_spark_svg   = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M12 3l1.8 4.9L18 9.6l-4.2 1.7L12 16l-1.8-4.7L6 9.6l4.2-1.7L12 3z" fill="currentColor"/><path d="M18.5 14l.9 2.3 2.3.9-2.3.9-.9 2.3-.9-2.3-2.3-.9 2.3-.9.9-2.3z" fill="currentColor" opacity="0.7"/></svg>';
$tuki_svg_allowed = array(
	'svg'  => array(
		'viewbox'     => true,
		'width'       => true,
		'height'      => true,
		'fill'        => true,
		'aria-hidden' => true,
	),
	'path' => array(
		'd'       => true,
		'fill'    => true,
		'opacity' => true,
	),
);

// Tab definitions: key => label. The first is active by default.
$tuki_tabs = array(
	'general'    => __( 'General', 'tukify' ),
	'appearance' => __( 'Appearance', 'tukify' ),
	'products'   => __( 'Products & Cart', 'tukify' ),
	'kb'         => __( 'Knowledge Base', 'tukify' ),
	'analytics'  => __( 'Logs / Analytics', 'tukify' ),
	'advanced'   => __( 'Advanced', 'tukify' ),
);
?>
<div class="wrap tkfy">
	<div class="tkfy-shell">

		<header class="tkfy-header">
			<div class="tkfy-brand">
				<span class="tkfy-brand-chip"><?php echo wp_kses( $tuki_spark_svg, $tuki_svg_allowed ); ?></span>
				<span class="tkfy-brand-text">
					<span class="tkfy-brand-title">Tukify</span>
					<span class="tkfy-brand-sub"><?php esc_html_e( 'Settings', 'tukify' ); ?></span>
				</span>
			</div>
			<span class="tkfy-pill tkfy-pill--<?php echo esc_attr( $tuki_conn['state'] ); ?>">
				<span class="tkfy-dot"></span><?php echo esc_html( $tuki_conn['label'] ); ?>
			</span>
		</header>

		<nav class="tkfy-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Tukify settings sections', 'tukify' ); ?>">
			<?php
			$tuki_first = true;
			foreach ( $tuki_tabs as $tuki_key => $tuki_label ) :
				?>
				<button
					type="button"
					class="tkfy-tab<?php echo $tuki_first ? ' is-active' : ''; ?>"
					role="tab"
					id="tkfy-tab-<?php echo esc_attr( $tuki_key ); ?>"
					data-tab="<?php echo esc_attr( $tuki_key ); ?>"
					aria-controls="tkfy-panel-<?php echo esc_attr( $tuki_key ); ?>"
					aria-selected="<?php echo $tuki_first ? 'true' : 'false'; ?>"
				><?php echo esc_html( $tuki_label ); ?></button>
				<?php
				$tuki_first = false;
			endforeach;
			?>
		</nav>

		<form method="post" action="options.php" class="tkfy-form">
			<?php settings_fields( Tuki_Settings::GROUP ); ?>

			<div class="tkfy-panels">

				<!-- ============================ GENERAL / SETUP ============================ -->
				<section class="tkfy-panel is-active" role="tabpanel" id="tkfy-panel-general" data-tab="general" aria-labelledby="tkfy-tab-general" tabindex="0">
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'AI provider', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'All AI calls run server-side; your key is never exposed to the frontend.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_provider"><?php esc_html_e( 'Provider', 'tukify' ); ?></label>
								<select class="tuki-input" id="tuki_provider" name="<?php echo esc_attr( $tuki_option ); ?>[provider]">
									<option value="gemini" <?php selected( $tuki_settings['provider'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini (recommended)', 'tukify' ); ?></option>
									<option value="openai" <?php selected( $tuki_settings['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'tukify' ); ?></option>
									<option value="anthropic" <?php selected( $tuki_settings['provider'], 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (chat only — no embeddings)', 'tukify' ); ?></option>
								</select>
								<p class="tkfy-hint"><?php esc_html_e( 'Anthropic has no embeddings endpoint; if selected for chat, embeddings still use Gemini or OpenAI.', 'tukify' ); ?></p>
							</div>

							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label" for="tuki_gemini_api_key"><?php esc_html_e( 'Gemini API key', 'tukify' ); ?></label>
								<div class="tkfy-inline">
									<input
										type="password"
										class="tuki-input"
										id="tuki_gemini_api_key"
										name="<?php echo esc_attr( $tuki_option ); ?>[gemini_api_key]"
										autocomplete="off"
										value=""
										placeholder="<?php echo esc_attr( '' === $tuki_gemini_key ? __( 'AIza…', 'tukify' ) : Tuki_Settings::mask_key( $tuki_gemini_key ) ); ?>"
									/>
									<button type="button" class="tuki-btn tuki-btn--ghost" id="tuki-test-connection"><?php esc_html_e( 'Test connection', 'tukify' ); ?></button>
								</div>
								<span id="tuki-test-result" class="tuki-status" role="status" aria-live="polite"></span>
								<p class="tkfy-hint">
									<?php
									if ( '' === $tuki_gemini_key ) {
										esc_html_e( 'Get a free key at aistudio.google.com — no billing required for the free tier.', 'tukify' );
									} else {
										esc_html_e( 'A key is saved. Leave blank to keep it, or paste a new key to replace it.', 'tukify' );
									}
									?>
								</p>
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Models', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Embedding and chat models used for retrieval and replies.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_embedding_model"><?php esc_html_e( 'Embedding model', 'tukify' ); ?></label>
								<select class="tuki-input" id="tuki_embedding_model" name="<?php echo esc_attr( $tuki_option ); ?>[embedding_model]">
									<option value="gemini-embedding-001" <?php selected( $tuki_settings['embedding_model'], 'gemini-embedding-001' ); ?>>gemini-embedding-001</option>
								</select>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_chat_model"><?php esc_html_e( 'Chat model', 'tukify' ); ?></label>
								<select class="tuki-input" id="tuki_chat_model" name="<?php echo esc_attr( $tuki_option ); ?>[chat_model]">
									<option value="gemini-2.5-flash" <?php selected( $tuki_settings['chat_model'], 'gemini-2.5-flash' ); ?>>gemini-2.5-flash</option>
									<option value="gemini-2.5-flash-lite" <?php selected( $tuki_settings['chat_model'], 'gemini-2.5-flash-lite' ); ?>>gemini-2.5-flash-lite (economy)</option>
								</select>
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Chatbot', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Enable the assistant on your storefront.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[floating_widget]" value="1" <?php checked( $tuki_settings['floating_widget'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Show a floating chat bubble on the storefront', 'tukify' ); ?></span>
								</label>
							</div>
						</div>
					</div>
				</section>

				<!-- ============================ APPEARANCE ============================ -->
				<section class="tkfy-panel" role="tabpanel" id="tkfy-panel-appearance" data-tab="appearance" aria-labelledby="tkfy-tab-appearance" tabindex="0" hidden>
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Widget appearance', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Global defaults for the storefront widget. Elementor widgets can override per-page.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_accent_color"><?php esc_html_e( 'Default accent color', 'tukify' ); ?></label>
								<input type="color" class="tuki-color" id="tuki_accent_color" name="<?php echo esc_attr( $tuki_option ); ?>[accent_color]" value="<?php echo esc_attr( $tuki_settings['accent_color'] ); ?>" />
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_color_scheme"><?php esc_html_e( 'Default color scheme', 'tukify' ); ?></label>
								<select class="tuki-input" id="tuki_color_scheme" name="<?php echo esc_attr( $tuki_option ); ?>[color_scheme]">
									<option value="dark" <?php selected( $tuki_settings['color_scheme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'tukify' ); ?></option>
									<option value="light" <?php selected( $tuki_settings['color_scheme'], 'light' ); ?>><?php esc_html_e( 'Light', 'tukify' ); ?></option>
								</select>
							</div>
							<p class="tkfy-note tkfy-field--wide"><?php esc_html_e( 'Welcome message, input placeholder and widget position are not configurable yet — they use built-in defaults.', 'tukify' ); ?></p>
						</div>
					</div>
				</section>

				<!-- ============================ PRODUCTS & CART ============================ -->
				<section class="tkfy-panel" role="tabpanel" id="tkfy-panel-products" data-tab="products" aria-labelledby="tkfy-tab-products" tabindex="0" hidden>
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Product search & browsing', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'How the assistant filters and lists products.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[hybrid_filtering]" value="1" <?php checked( $tuki_settings['hybrid_filtering'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Apply natural-language filters (price, colour, brand, stock) as real product queries', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_browse_batch_size"><?php esc_html_e( 'Browse batch size', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_browse_batch_size" name="<?php echo esc_attr( $tuki_option ); ?>[browse_batch_size]" value="<?php echo esc_attr( $tuki_settings['browse_batch_size'] ); ?>" min="4" max="24" />
								<p class="tkfy-hint"><?php esc_html_e( 'Products per batch for “show all” / category browse, with a Show more button. Default 10.', 'tukify' ); ?></p>
							</div>
							<p class="tkfy-note tkfy-field--wide"><?php esc_html_e( 'Add-to-cart, quantity selector and category search work automatically and have no options to configure.', 'tukify' ); ?></p>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Complementary products', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Cart-aware suggestions that lift average order value.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[upsell_enabled]" value="1" <?php checked( $tuki_settings['upsell_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Suggest complementary products based on the cart', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[upsell_proactive]" value="1" <?php checked( $tuki_settings['upsell_proactive'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Offer the suggestion proactively (once per visit)', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_upsell_max"><?php esc_html_e( 'Max suggestions', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_upsell_max" name="<?php echo esc_attr( $tuki_option ); ?>[upsell_max]" value="<?php echo esc_attr( $tuki_settings['upsell_max'] ); ?>" min="1" max="4" />
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'In-chat checkout', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Let shoppers review, address, ship, and pay without leaving the chat.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[checkout_in_chat]" value="1" <?php checked( $tuki_settings['checkout_in_chat'], 1 ); ?> <?php disabled( ! class_exists( 'WooCommerce' ) ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Enable in-chat checkout (creates real WooCommerce orders)', 'tukify' ); ?></span>
								</label>
								<p class="tkfy-hint">
									<?php esc_html_e( 'Uses WooCommerce\'s own cart, checkout, and order APIs — no pricing, tax, or payment logic is re-implemented. Offline gateways (Cash on Delivery, bank transfer, cheque) complete inside the chat; redirect gateways (e.g. PayPal) send the shopper to the gateway; gateways with on-page card fields (e.g. Stripe) hand off to WooCommerce\'s secure pay-for-order page. Anything unsupported falls back to the normal checkout page. Off by default.', 'tukify' ); ?>
								</p>
								<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
									<p class="tkfy-hint"><strong><?php esc_html_e( 'WooCommerce is not active, so in-chat checkout is unavailable.', 'tukify' ); ?></strong></p>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Back-in-stock alerts', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Let shoppers ask to be emailed when an out-of-stock item returns.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[stock_notify_enabled]" value="1" <?php checked( $tuki_settings['stock_notify_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Offer back-in-stock alerts on out-of-stock products in chat', 'tukify' ); ?></span>
								</label>
								<p class="tkfy-hint">
									<?php
									printf(
										/* translators: %s: link to the pending-notifications screen. */
										esc_html__( 'Only the email and product are stored, with consent, and removed once sent. See who is waiting under %s.', 'tukify' ),
										'<a href="' . esc_url( admin_url( 'admin.php?page=tukify-stock-notify' ) ) . '">' . esc_html__( 'Back in stock', 'tukify' ) . '</a>'
									);
									?>
								</p>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label" for="tuki_stock_notify_subject"><?php esc_html_e( 'Email subject', 'tukify' ); ?></label>
								<input type="text" class="tuki-input" id="tuki_stock_notify_subject" name="<?php echo esc_attr( $tuki_option ); ?>[stock_notify_subject]" value="<?php echo esc_attr( $tuki_settings['stock_notify_subject'] ); ?>" placeholder="<?php echo esc_attr( Tuki_Stock_Notify::default_subject() ); ?>" />
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label" for="tuki_stock_notify_body"><?php esc_html_e( 'Email body', 'tukify' ); ?></label>
								<textarea class="tuki-input" id="tuki_stock_notify_body" name="<?php echo esc_attr( $tuki_option ); ?>[stock_notify_body]" rows="6" placeholder="<?php echo esc_attr( Tuki_Stock_Notify::default_body() ); ?>"><?php echo esc_textarea( $tuki_settings['stock_notify_body'] ); ?></textarea>
								<p class="tkfy-hint"><?php esc_html_e( 'Placeholders: {product_name}, {product_url}, {site_name}, {unsubscribe_url}. Always include {unsubscribe_url}. Leave blank to use the defaults.', 'tukify' ); ?></p>
							</div>
						</div>
					</div>

					<?php
					// Category options + a reusable size-band row renderer (used for the
					// saved rows and the JS "add" template).
					$tuki_size_cats = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'hide_empty' => false,
						)
					);
					if ( is_wp_error( $tuki_size_cats ) ) {
						$tuki_size_cats = array();
					}

					$tuki_cat_options = function ( $selected ) use ( $tuki_size_cats ) {
						$html = '<option value="0">' . esc_html__( 'Select category…', 'tukify' ) . '</option>';
						foreach ( $tuki_size_cats as $tuki_term ) {
							$html .= sprintf(
								'<option value="%1$d"%2$s>%3$s</option>',
								(int) $tuki_term->term_id,
								selected( (int) $selected, (int) $tuki_term->term_id, false ),
								esc_html( $tuki_term->name )
							);
						}
						return $html;
					};

					$tuki_size_row = function ( $idx, $row ) use ( $tuki_option, $tuki_cat_options ) {
						$base = esc_attr( $tuki_option ) . '[size_charts][' . $idx . ']';
						$val  = function ( $row, $k ) {
							return isset( $row[ $k ] ) && '' !== $row[ $k ] && 0 != $row[ $k ] ? esc_attr( $row[ $k ] ) : ''; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
						};
						ob_start();
						?>
						<div class="tuki-size-row">
							<select class="tuki-input tuki-size-cat" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[category]"><?php echo $tuki_cat_options( isset( $row['category'] ) ? $row['category'] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
							<input class="tuki-input" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>" placeholder="<?php esc_attr_e( 'S', 'tukify' ); ?>" />
							<input class="tuki-input" type="number" step="any" min="0" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[h_min]" value="<?php echo $val( $row, 'h_min' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="<?php esc_attr_e( 'H min', 'tukify' ); ?>" />
							<input class="tuki-input" type="number" step="any" min="0" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[h_max]" value="<?php echo $val( $row, 'h_max' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="<?php esc_attr_e( 'H max', 'tukify' ); ?>" />
							<input class="tuki-input" type="number" step="any" min="0" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[w_min]" value="<?php echo $val( $row, 'w_min' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="<?php esc_attr_e( 'W min', 'tukify' ); ?>" />
							<input class="tuki-input" type="number" step="any" min="0" name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[w_max]" value="<?php echo $val( $row, 'w_max' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" placeholder="<?php esc_attr_e( 'W max', 'tukify' ); ?>" />
							<button type="button" class="tuki-btn tuki-btn--ghost tuki-size-remove"><?php esc_html_e( 'Remove', 'tukify' ); ?></button>
						</div>
						<?php
						return ob_get_clean();
					};

					$tuki_size_rows = array_values( (array) $tuki_settings['size_charts'] );
					?>
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Size & fit advisor', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Offer a short guided size recommendation in chat for products with size options.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[size_advisor_enabled]" value="1" <?php checked( $tuki_settings['size_advisor_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Offer a guided size & fit recommendation in chat', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_size_unit"><?php esc_html_e( 'Measurement unit', 'tukify' ); ?></label>
								<select class="tuki-input" id="tuki_size_unit" name="<?php echo esc_attr( $tuki_option ); ?>[size_unit]">
									<option value="metric" <?php selected( $tuki_settings['size_unit'], 'metric' ); ?>><?php esc_html_e( 'Metric (cm / kg)', 'tukify' ); ?></option>
									<option value="imperial" <?php selected( $tuki_settings['size_unit'], 'imperial' ); ?>><?php esc_html_e( 'Imperial (in / lb)', 'tukify' ); ?></option>
								</select>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_size_attribute"><?php esc_html_e( 'Size attribute name', 'tukify' ); ?></label>
								<input type="text" class="tuki-input" id="tuki_size_attribute" name="<?php echo esc_attr( $tuki_option ); ?>[size_attribute]" value="<?php echo esc_attr( $tuki_settings['size_attribute'] ); ?>" placeholder="size" />
								<p class="tkfy-hint"><?php esc_html_e( 'The product attribute that holds sizes, e.g. "size" or "pa_size".', 'tukify' ); ?></p>
							</div>

							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label"><?php esc_html_e( 'Size charts (per category)', 'tukify' ); ?></label>
								<div class="tuki-size-colhead">
									<span><?php esc_html_e( 'Category', 'tukify' ); ?></span>
									<span><?php esc_html_e( 'Size', 'tukify' ); ?></span>
									<span><?php esc_html_e( 'Height min', 'tukify' ); ?></span>
									<span><?php esc_html_e( 'Height max', 'tukify' ); ?></span>
									<span><?php esc_html_e( 'Weight min', 'tukify' ); ?></span>
									<span><?php esc_html_e( 'Weight max', 'tukify' ); ?></span>
									<span></span>
								</div>
								<div id="tuki-size-charts" data-next="<?php echo esc_attr( count( $tuki_size_rows ) ); ?>">
									<?php
									foreach ( $tuki_size_rows as $tuki_i => $tuki_row ) {
										echo $tuki_size_row( $tuki_i, $tuki_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* helpers.
									}
									?>
								</div>
								<script type="text/html" id="tuki-size-tpl"><?php echo $tuki_size_row( '__IDX__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* helpers. ?></script>
								<div class="tkfy-inline">
									<button type="button" class="tuki-btn tuki-btn--ghost" id="tuki-size-add"><?php esc_html_e( 'Add size band', 'tukify' ); ?></button>
								</div>
								<p class="tkfy-hint"><?php esc_html_e( 'Add one row per size band. The advisor scores the shopper\'s height and weight against these ranges (in the unit above) to suggest a size. Leave a range blank to ignore it.', 'tukify' ); ?></p>
							</div>
						</div>
					</div>
				</section>

				<!-- ============================ KNOWLEDGE BASE ============================ -->
				<section class="tkfy-panel" role="tabpanel" id="tkfy-panel-kb" data-tab="kb" aria-labelledby="tkfy-tab-kb" tabindex="0" hidden>
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Knowledge base (site content)', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Answer shipping, returns, and questions about your whole site from your own content. Reindex after changing sources.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[kb_enabled]" value="1" <?php checked( $tuki_settings['kb_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Answer policy / FAQ questions', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[kb_wc_policies]" value="1" <?php checked( $tuki_settings['kb_wc_policies'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Include WooCommerce policy pages (terms, privacy)', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[show_citations]" value="1" <?php checked( $tuki_settings['show_citations'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Show sources under knowledge-base answers', 'tukify' ); ?></span>
								</label>
								<p class="tkfy-hint"><?php esc_html_e( 'Adds small clickable chips linking to the pages the assistant used to answer. Only sources that actually grounded the answer are shown.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field">
								<span class="tkfy-label" id="tuki_kb_post_types_label"><?php esc_html_e( 'Content post types', 'tukify' ); ?></span>
								<div class="tkfy-checklist" role="group" aria-labelledby="tuki_kb_post_types_label">
									<?php
									$tuki_kb_types = array_map( 'sanitize_key', (array) $tuki_settings['kb_post_types'] );
									foreach ( Tuki_Settings::indexable_post_types() as $tuki_pt ) {
										$tuki_pt_obj = get_post_type_object( $tuki_pt );
										$tuki_pt_lbl = ( $tuki_pt_obj && isset( $tuki_pt_obj->labels->name ) ) ? $tuki_pt_obj->labels->name : $tuki_pt;
										printf(
											'<label class="tkfy-check-row"><input type="checkbox" class="tkfy-check-input" name="%1$s[kb_post_types][]" value="%2$s" %3$s /><span class="tkfy-check-box" aria-hidden="true"></span><span class="tkfy-check-text">%4$s</span></label>',
											esc_attr( $tuki_option ),
											esc_attr( $tuki_pt ),
											checked( in_array( $tuki_pt, $tuki_kb_types, true ), true, false ),
											esc_html( $tuki_pt_lbl )
										);
									}
									?>
								</div>
								<p class="tkfy-hint"><?php esc_html_e( 'Index every published item of these types so the assistant can answer questions about your whole site. Untick a type to exclude it. Reindex after changing this.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field">
								<span class="tkfy-label" id="tuki_kb_pages_label"><?php esc_html_e( 'Extra source pages', 'tukify' ); ?></span>
								<div class="tkfy-checklist" role="group" aria-labelledby="tuki_kb_pages_label">
									<?php
									$tuki_selected_pages = array_map( 'absint', (array) $tuki_settings['kb_pages'] );
									$tuki_pages          = get_pages( array( 'sort_column' => 'post_title' ) );

									if ( empty( $tuki_pages ) ) {
										echo '<p class="tkfy-checklist-empty">' . esc_html__( 'No published pages found.', 'tukify' ) . '</p>';
									} else {
										foreach ( $tuki_pages as $tuki_page ) {
											printf(
												'<label class="tkfy-check-row"><input type="checkbox" class="tkfy-check-input" name="%1$s[kb_pages][]" value="%2$d" %3$s /><span class="tkfy-check-box" aria-hidden="true"></span><span class="tkfy-check-text">%4$s</span></label>',
												esc_attr( $tuki_option ),
												(int) $tuki_page->ID,
												checked( in_array( (int) $tuki_page->ID, $tuki_selected_pages, true ), true, false ),
												esc_html( $tuki_page->post_title )
											);
										}
									}
									?>
								</div>
								<p class="tkfy-hint"><?php esc_html_e( 'Tick any pages to include (e.g. Shipping, Returns, FAQ).', 'tukify' ); ?></p>
							</div>

							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label"><?php esc_html_e( 'Custom Q&A', 'tukify' ); ?></label>
								<div id="tuki-kb-qa" data-option="<?php echo esc_attr( $tuki_option ); ?>" data-next="<?php echo esc_attr( count( (array) $tuki_settings['kb_qa'] ) ); ?>">
									<?php foreach ( (array) $tuki_settings['kb_qa'] as $tuki_i => $tuki_pair ) : ?>
										<div class="tuki-kb-row">
											<input type="text" class="tuki-input" name="<?php echo esc_attr( $tuki_option ); ?>[kb_qa][<?php echo esc_attr( $tuki_i ); ?>][q]" value="<?php echo esc_attr( $tuki_pair['q'] ); ?>" placeholder="<?php esc_attr_e( 'Question', 'tukify' ); ?>" />
											<textarea class="tuki-input" rows="2" name="<?php echo esc_attr( $tuki_option ); ?>[kb_qa][<?php echo esc_attr( $tuki_i ); ?>][a]" placeholder="<?php esc_attr_e( 'Answer', 'tukify' ); ?>"><?php echo esc_textarea( $tuki_pair['a'] ); ?></textarea>
											<button type="button" class="tuki-btn tuki-btn--ghost tuki-kb-remove"><?php esc_html_e( 'Remove', 'tukify' ); ?></button>
										</div>
									<?php endforeach; ?>
								</div>
								<button type="button" class="tuki-btn tuki-btn--ghost" id="tuki-kb-add"><?php esc_html_e( 'Add Q&A', 'tukify' ); ?></button>
							</div>

							<div class="tkfy-field tkfy-field--wide">
								<p class="tkfy-hint">
									<strong><?php esc_html_e( 'Entries indexed:', 'tukify' ); ?></strong>
									<span id="tuki-kb-count"><?php echo esc_html( number_format_i18n( Tuki_DB::count_kb() ) ); ?></span>
								</p>
								<div class="tkfy-inline">
									<button type="button" class="tuki-btn tuki-btn--ghost" id="tuki-kb-reindex"><?php esc_html_e( 'Reindex knowledge base', 'tukify' ); ?></button>
									<span id="tuki-kb-status" class="tuki-status" role="status" aria-live="polite"></span>
								</div>
								<p class="tkfy-hint"><?php esc_html_e( 'Save your changes first, then reindex to embed the latest content.', 'tukify' ); ?></p>
							</div>
						</div>
					</div>
				</section>

				<!-- ============================ LOGS / ANALYTICS ============================ -->
				<section class="tkfy-panel" role="tabpanel" id="tkfy-panel-analytics" data-tab="analytics" aria-labelledby="tkfy-tab-analytics" tabindex="0" hidden>
					<?php
					// -------- Demand insights (read-only display; GET range filter) --------
					$tuki_ranges = array(
						7   => __( 'Last 7 days', 'tukify' ),
						30  => __( 'Last 30 days', 'tukify' ),
						90  => __( 'Last 90 days', 'tukify' ),
						365 => __( 'Last year', 'tukify' ),
						0   => __( 'All time', 'tukify' ),
					);

					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
					$tuki_range = isset( $_GET['demand_range'] ) ? absint( wp_unslash( $_GET['demand_range'] ) ) : 30;
					if ( ! array_key_exists( $tuki_range, $tuki_ranges ) ) {
						$tuki_range = 30;
					}

					$tuki_now      = current_time( 'timestamp' );
					$tuki_win_days = ( 0 === $tuki_range ) ? 30 : $tuki_range;
					$tuki_since    = ( 0 === $tuki_range ) ? '1970-01-01 00:00:00' : gmdate( 'Y-m-d H:i:s', $tuki_now - ( $tuki_range * DAY_IN_SECONDS ) );
					$tuki_mid      = gmdate( 'Y-m-d H:i:s', $tuki_now - ( ( $tuki_win_days / 2 ) * DAY_IN_SECONDS ) );

					$tuki_summary  = Tuki_DB::demand_summary( $tuki_since );
					$tuki_zero     = Tuki_DB::demand_zero_matches( $tuki_since, 10 );
					$tuki_unans    = Tuki_DB::demand_unanswered( $tuki_since, 10 );
					$tuki_trending = Tuki_DB::demand_trending( $tuki_since, $tuki_mid, 10 );
					$tuki_demand_n = Tuki_DB::count_demand();
					$tuki_base_url = admin_url( 'admin.php?page=tukify-settings' );

					// Suggested action for a row, chosen from its recorded intents.
					$tuki_action = function ( $intents_csv ) {
						$product_intents = array( 'browse', 'category_browse', 'browse_all', 'compare', 'zero_result', 'filter' );
						$intents         = array_filter( array_map( 'trim', explode( ',', (string) $intents_csv ) ) );

						if ( empty( $intents ) || array_intersect( $intents, $product_intents ) ) {
							return array(
								'label' => __( 'Add product', 'tukify' ),
								'url'   => admin_url( 'post-new.php?post_type=product' ),
								'kind'  => 'product',
							);
						}

						return array(
							'label' => __( 'Write FAQ', 'tukify' ),
							'url'   => admin_url( 'admin.php?page=tukify-settings#tab-kb' ),
							'kind'  => 'faq',
						);
					};

					$tuki_pct = function ( $n, $total ) {
						return $total > 0 ? (int) round( $n / $total * 100 ) : 0;
					};
					?>

					<!-- Logging + retention settings -->
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Logs & analytics', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Track how shoppers use the assistant. Nothing personal or conversational is stored.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[analytics_enabled]" value="1" <?php checked( $tuki_settings['analytics_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Log queries, clicks, and chat-to-sale events', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[demand_logging]" value="1" <?php checked( $tuki_settings['demand_logging'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Log demand insights (each query + its outcome, anonymized)', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_demand_retention"><?php esc_html_e( 'Auto-purge logs older than (days)', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_demand_retention" name="<?php echo esc_attr( $tuki_option ); ?>[demand_retention_days]" value="<?php echo esc_attr( $tuki_settings['demand_retention_days'] ); ?>" min="0" max="3650" />
								<p class="tkfy-hint"><?php esc_html_e( '0 keeps logs forever. A daily job removes rows older than this. Default 90.', 'tukify' ); ?></p>
							</div>
							<p class="tkfy-note tkfy-field--wide">
								<?php
								printf(
									/* translators: %s: link to the Tukify dashboard. */
									esc_html__( 'View top queries, zero-result searches, and conversion on the %s.', 'tukify' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=tukify' ) ) . '">' . esc_html__( 'Tukify dashboard', 'tukify' ) . '</a>'
								);
								?>
							</p>
						</div>
					</div>

					<!-- Demand insights -->
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Demand insights', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'What shoppers ask for — and what you might be missing.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-demand-filter tkfy-field--wide" role="group" aria-label="<?php esc_attr_e( 'Date range', 'tukify' ); ?>">
								<?php
								foreach ( $tuki_ranges as $tuki_days => $tuki_label ) :
									$tuki_url = esc_url( add_query_arg( 'demand_range', $tuki_days, $tuki_base_url ) . '#tab-analytics' );
									?>
									<a class="tkfy-range-link<?php echo (int) $tuki_days === $tuki_range ? ' is-active' : ''; ?>" href="<?php echo $tuki_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url above. ?>"><?php echo esc_html( $tuki_label ); ?></a>
								<?php endforeach; ?>
							</div>

							<?php if ( $tuki_demand_n <= 0 ) : ?>
								<div class="tkfy-empty tkfy-field--wide">
									<p><?php esc_html_e( 'No demand data yet. Once shoppers use the chat, unmet demand and unanswered questions will show up here.', 'tukify' ); ?></p>
								</div>
							<?php else : ?>
								<div class="tkfy-demand-summary tkfy-field--wide">
									<div class="tkfy-tile">
										<span class="tkfy-tile-value"><?php echo esc_html( number_format_i18n( $tuki_summary['total'] ) ); ?></span>
										<span class="tkfy-tile-label"><?php esc_html_e( 'Queries in range', 'tukify' ); ?></span>
									</div>
									<div class="tkfy-tile">
										<span class="tkfy-tile-value tkfy-tile-value--warn"><?php echo esc_html( number_format_i18n( $tuki_summary['zero_match'] ) ); ?></span>
										<span class="tkfy-tile-label">
											<?php
											/* translators: %d: percentage of queries with zero matches. */
											printf( esc_html__( 'Zero matches (%d%%)', 'tukify' ), (int) $tuki_pct( $tuki_summary['zero_match'], $tuki_summary['total'] ) );
											?>
										</span>
									</div>
									<div class="tkfy-tile">
										<span class="tkfy-tile-value tkfy-tile-value--warn"><?php echo esc_html( number_format_i18n( $tuki_summary['unanswered'] ) ); ?></span>
										<span class="tkfy-tile-label">
											<?php
											/* translators: %d: percentage of queries not answered confidently. */
											printf( esc_html__( 'Unanswered (%d%%)', 'tukify' ), (int) $tuki_pct( $tuki_summary['unanswered'], $tuki_summary['total'] ) );
											?>
										</span>
									</div>
								</div>

								<!-- Unmet demand: zero product matches -->
								<div class="tkfy-field--wide">
									<h3 class="tkfy-demand-title"><?php esc_html_e( 'Unmet demand — searches with zero product matches', 'tukify' ); ?></h3>
									<?php if ( empty( $tuki_zero ) ) : ?>
										<p class="tkfy-hint"><?php esc_html_e( 'None in this range — every search matched something. 🎉', 'tukify' ); ?></p>
									<?php else : ?>
										<div class="tkfy-demand-list" role="table">
											<div class="tkfy-demand-row tkfy-demand-row--head" role="row">
												<span role="columnheader"><?php esc_html_e( 'Search', 'tukify' ); ?></span>
												<span role="columnheader"><?php esc_html_e( 'Count', 'tukify' ); ?></span>
												<span role="columnheader"><?php esc_html_e( 'Suggested action', 'tukify' ); ?></span>
											</div>
											<?php
											foreach ( $tuki_zero as $tuki_row ) :
												$tuki_act = $tuki_action( isset( $tuki_row['intents'] ) ? $tuki_row['intents'] : '' );
												?>
												<div class="tkfy-demand-row" role="row">
													<span class="tkfy-demand-q" role="cell"><?php echo esc_html( $tuki_row['query_text'] ); ?></span>
													<span class="tkfy-demand-n" role="cell"><?php echo esc_html( number_format_i18n( (int) $tuki_row['hits'] ) ); ?></span>
													<span role="cell">
														<a class="tkfy-demand-action tkfy-demand-action--<?php echo esc_attr( $tuki_act['kind'] ); ?>" href="<?php echo esc_url( $tuki_act['url'] ); ?>"><?php echo esc_html( $tuki_act['label'] ); ?></a>
													</span>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>

								<!-- Questions the bot could not answer confidently -->
								<div class="tkfy-field--wide">
									<h3 class="tkfy-demand-title"><?php esc_html_e( 'Questions the bot could not answer confidently', 'tukify' ); ?></h3>
									<?php if ( empty( $tuki_unans ) ) : ?>
										<p class="tkfy-hint"><?php esc_html_e( 'None in this range — the assistant answered everything it was asked.', 'tukify' ); ?></p>
									<?php else : ?>
										<div class="tkfy-demand-list" role="table">
											<div class="tkfy-demand-row tkfy-demand-row--head" role="row">
												<span role="columnheader"><?php esc_html_e( 'Question', 'tukify' ); ?></span>
												<span role="columnheader"><?php esc_html_e( 'Count', 'tukify' ); ?></span>
												<span role="columnheader"><?php esc_html_e( 'Suggested action', 'tukify' ); ?></span>
											</div>
											<?php
											foreach ( $tuki_unans as $tuki_row ) :
												$tuki_act = $tuki_action( isset( $tuki_row['intents'] ) ? $tuki_row['intents'] : '' );
												?>
												<div class="tkfy-demand-row" role="row">
													<span class="tkfy-demand-q" role="cell"><?php echo esc_html( $tuki_row['query_text'] ); ?></span>
													<span class="tkfy-demand-n" role="cell"><?php echo esc_html( number_format_i18n( (int) $tuki_row['hits'] ) ); ?></span>
													<span role="cell">
														<a class="tkfy-demand-action tkfy-demand-action--<?php echo esc_attr( $tuki_act['kind'] ); ?>" href="<?php echo esc_url( $tuki_act['url'] ); ?>"><?php echo esc_html( $tuki_act['label'] ); ?></a>
													</span>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>

								<!-- Trending terms -->
								<div class="tkfy-field--wide">
									<h3 class="tkfy-demand-title"><?php esc_html_e( 'Trending search terms', 'tukify' ); ?></h3>
									<?php if ( empty( $tuki_trending ) ) : ?>
										<p class="tkfy-hint"><?php esc_html_e( 'No searches in this range yet.', 'tukify' ); ?></p>
									<?php else : ?>
										<div class="tkfy-demand-list" role="table">
											<div class="tkfy-demand-row tkfy-demand-row--head" role="row">
												<span role="columnheader"><?php esc_html_e( 'Term', 'tukify' ); ?></span>
												<span role="columnheader"><?php esc_html_e( 'Count', 'tukify' ); ?></span>
												<span role="columnheader"><?php esc_html_e( 'Trend', 'tukify' ); ?></span>
											</div>
											<?php
											foreach ( $tuki_trending as $tuki_row ) :
												$tuki_recent = (int) $tuki_row['recent'];
												$tuki_prior  = (int) $tuki_row['prior'];
												if ( 0 === $tuki_prior && $tuki_recent > 0 ) {
													$tuki_trend = 'new';
													$tuki_tsym  = '●';
													$tuki_tlbl  = __( 'New', 'tukify' );
												} elseif ( $tuki_recent > $tuki_prior ) {
													$tuki_trend = 'up';
													$tuki_tsym  = '▲';
													$tuki_tlbl  = __( 'Rising', 'tukify' );
												} elseif ( $tuki_recent < $tuki_prior ) {
													$tuki_trend = 'down';
													$tuki_tsym  = '▼';
													$tuki_tlbl  = __( 'Cooling', 'tukify' );
												} else {
													$tuki_trend = 'flat';
													$tuki_tsym  = '–';
													$tuki_tlbl  = __( 'Steady', 'tukify' );
												}
												?>
												<div class="tkfy-demand-row" role="row">
													<span class="tkfy-demand-q" role="cell"><?php echo esc_html( $tuki_row['query_text'] ); ?></span>
													<span class="tkfy-demand-n" role="cell"><?php echo esc_html( number_format_i18n( (int) $tuki_row['hits'] ) ); ?></span>
													<span role="cell">
														<span class="tkfy-trend tkfy-trend--<?php echo esc_attr( $tuki_trend ); ?>"><?php echo esc_html( $tuki_tsym . ' ' . $tuki_tlbl ); ?></span>
													</span>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<!-- ============================ ADVANCED ============================ -->
				<section class="tkfy-panel" role="tabpanel" id="tkfy-panel-advanced" data-tab="advanced" aria-labelledby="tkfy-tab-advanced" tabindex="0" hidden>
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Retrieval & limits', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'How many products to retrieve and how hard guests can hit the API.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_retrieval_count"><?php esc_html_e( 'Products retrieved (N)', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_retrieval_count" name="<?php echo esc_attr( $tuki_option ); ?>[retrieval_count]" value="<?php echo esc_attr( $tuki_settings['retrieval_count'] ); ?>" min="1" max="20" />
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_relevance_threshold"><?php esc_html_e( 'Relevance threshold', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_relevance_threshold" name="<?php echo esc_attr( $tuki_option ); ?>[relevance_threshold]" value="<?php echo esc_attr( $tuki_settings['relevance_threshold'] ); ?>" min="0" max="1" step="0.05" />
								<p class="tkfy-hint"><?php esc_html_e( 'Minimum similarity (0–1) for a product to be shown. Below this, the assistant offers the closest alternatives instead. Default 0.6.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_guest_rate_limit"><?php esc_html_e( 'Guest rate limit (per minute)', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_guest_rate_limit" name="<?php echo esc_attr( $tuki_option ); ?>[guest_rate_limit]" value="<?php echo esc_attr( $tuki_settings['guest_rate_limit'] ); ?>" min="1" max="1000" />
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Conversation', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'How the assistant handles vague requests.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[clarify_enabled]" value="1" <?php checked( $tuki_settings['clarify_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Ask clarifying questions when a request is too vague', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_clarify_max"><?php esc_html_e( 'Max questions per conversation', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_clarify_max" name="<?php echo esc_attr( $tuki_option ); ?>[clarify_max]" value="<?php echo esc_attr( $tuki_settings['clarify_max'] ); ?>" min="1" max="3" />
								<p class="tkfy-hint"><?php esc_html_e( 'The assistant never asks more than this many clarifying questions in one chat. Default 2.', 'tukify' ); ?></p>
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Exit intent', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Re-engage a shopper who is about to leave.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[exit_intent_enabled]" value="1" <?php checked( $tuki_settings['exit_intent_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Open the chat when a shopper is about to leave (exit intent)', 'tukify' ); ?></span>
								</label>
								<p class="tkfy-hint"><?php esc_html_e( 'Fires once per visit, respects a cooldown, and never after the shopper has used the chat. Needs the floating widget enabled.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[exit_intent_mobile]" value="1" <?php checked( $tuki_settings['exit_intent_mobile'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Also detect exit intent on mobile', 'tukify' ); ?></span>
								</label>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_exit_cooldown"><?php esc_html_e( 'Cooldown (hours)', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_exit_cooldown" name="<?php echo esc_attr( $tuki_option ); ?>[exit_intent_cooldown]" value="<?php echo esc_attr( $tuki_settings['exit_intent_cooldown'] ); ?>" min="0" max="720" />
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label" for="tuki_exit_msg_browsing"><?php esc_html_e( 'Message (browsing)', 'tukify' ); ?></label>
								<input type="text" class="tuki-input" id="tuki_exit_msg_browsing" name="<?php echo esc_attr( $tuki_option ); ?>[exit_intent_msg_browsing]" value="<?php echo esc_attr( $tuki_settings['exit_intent_msg_browsing'] ); ?>" placeholder="<?php esc_attr_e( 'Need help finding something? I can help you choose.', 'tukify' ); ?>" />
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label" for="tuki_exit_msg_cart"><?php esc_html_e( 'Message (items in cart)', 'tukify' ); ?></label>
								<input type="text" class="tuki-input" id="tuki_exit_msg_cart" name="<?php echo esc_attr( $tuki_option ); ?>[exit_intent_msg_cart]" value="<?php echo esc_attr( $tuki_settings['exit_intent_msg_cart'] ); ?>" placeholder="<?php esc_attr_e( 'Want me to help you finish up? I can answer any questions.', 'tukify' ); ?>" />
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Proactive re-engagement', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Nudge a shopper who has stalled — idle on checkout, or sitting with items in the cart.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[reengage_enabled]" value="1" <?php checked( $tuki_settings['reengage_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Open the chat when a shopper goes idle on a selected page', 'tukify' ); ?></span>
								</label>
								<p class="tkfy-hint"><?php esc_html_e( 'Fires at most once per session, never while the chat is open or after it has been used, and is always dismissible. Needs the floating widget enabled.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_reengage_idle"><?php esc_html_e( 'Idle threshold (seconds)', 'tukify' ); ?></label>
								<input type="number" class="tuki-input tuki-input--sm" id="tuki_reengage_idle" name="<?php echo esc_attr( $tuki_option ); ?>[reengage_idle_seconds]" value="<?php echo esc_attr( $tuki_settings['reengage_idle_seconds'] ); ?>" min="5" max="600" />
								<p class="tkfy-hint"><?php esc_html_e( 'How long without interaction before the nudge appears. Default 45.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field">
								<span class="tkfy-label" id="tuki_reengage_pages_label"><?php esc_html_e( 'Show on these pages', 'tukify' ); ?></span>
								<div class="tkfy-checklist" role="group" aria-labelledby="tuki_reengage_pages_label">
									<?php
									$tuki_reengage_pages = array_map( 'sanitize_key', (array) $tuki_settings['reengage_pages'] );
									$tuki_page_choices   = array(
										'checkout' => __( 'Checkout page', 'tukify' ),
										'cart'     => __( 'Cart page', 'tukify' ),
										'product'  => __( 'Product pages', 'tukify' ),
										'shop'     => __( 'Shop / category pages', 'tukify' ),
										'any'      => __( 'Any page', 'tukify' ),
									);
									foreach ( $tuki_page_choices as $tuki_pc_value => $tuki_pc_label ) {
										printf(
											'<label class="tkfy-check-row"><input type="checkbox" class="tkfy-check-input" name="%1$s[reengage_pages][]" value="%2$s" %3$s /><span class="tkfy-check-box" aria-hidden="true"></span><span class="tkfy-check-text">%4$s</span></label>',
											esc_attr( $tuki_option ),
											esc_attr( $tuki_pc_value ),
											checked( in_array( $tuki_pc_value, $tuki_reengage_pages, true ), true, false ),
											esc_html( $tuki_pc_label )
										);
									}
									?>
								</div>
								<p class="tkfy-hint"><?php esc_html_e( 'On checkout and cart, lingering itself is the signal. On product, shop, or any page, the nudge only fires when there are already items in the cart.', 'tukify' ); ?></p>
							</div>
							<div class="tkfy-field tkfy-field--wide">
								<label class="tkfy-label" for="tuki_reengage_message"><?php esc_html_e( 'Message', 'tukify' ); ?></label>
								<input type="text" class="tuki-input" id="tuki_reengage_message" name="<?php echo esc_attr( $tuki_option ); ?>[reengage_message]" value="<?php echo esc_attr( $tuki_settings['reengage_message'] ); ?>" placeholder="<?php esc_attr_e( 'Still deciding? I can answer any questions or help you check out.', 'tukify' ); ?>" />
							</div>
							<div class="tkfy-field">
								<label class="tkfy-label" for="tuki_reengage_coupon"><?php esc_html_e( 'Coupon code (optional)', 'tukify' ); ?></label>
								<input type="text" class="tuki-input tuki-input--sm" id="tuki_reengage_coupon" name="<?php echo esc_attr( $tuki_option ); ?>[reengage_coupon]" value="<?php echo esc_attr( $tuki_settings['reengage_coupon'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. SAVE10', 'tukify' ); ?>" />
								<p class="tkfy-hint"><?php esc_html_e( 'Shown as a copy-able code with the nudge. Leave blank to just offer help. Create the coupon under WooCommerce → Coupons first.', 'tukify' ); ?></p>
							</div>
						</div>
					</div>

					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Search test', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Run a semantic search against your indexed catalog to sanity-check ranking.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<div class="tkfy-inline">
									<input type="text" class="tuki-input" id="tuki-search-query" placeholder="<?php esc_attr_e( 'warm clothes for winter', 'tukify' ); ?>" />
									<button type="button" class="tuki-btn tuki-btn--ghost" id="tuki-search-run"><?php esc_html_e( 'Search', 'tukify' ); ?></button>
								</div>
								<span id="tuki-search-status" class="tuki-status" role="status" aria-live="polite"></span>
								<ol id="tuki-search-results" class="tuki-search-results"></ol>
							</div>
						</div>
					</div>
				</section>

			</div><!-- .tkfy-panels -->

			<div class="tkfy-actions">
				<button type="submit" class="tuki-btn tuki-btn--accent"><?php esc_html_e( 'Save changes', 'tukify' ); ?></button>
				<span class="tkfy-actions-hint"><?php esc_html_e( 'Saves every tab.', 'tukify' ); ?></span>
			</div>
		</form>

	</div><!-- .tkfy-shell -->
</div>
