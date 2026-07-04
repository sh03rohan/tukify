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
					<div class="tkfy-card">
						<div class="tkfy-card-head">
							<h2 class="tkfy-card-title"><?php esc_html_e( 'Logs & analytics', 'tukify' ); ?></h2>
							<p class="tkfy-card-cap"><?php esc_html_e( 'Track how shoppers use the assistant.', 'tukify' ); ?></p>
						</div>
						<div class="tkfy-card-body">
							<div class="tkfy-field tkfy-field--wide">
								<label class="tuki-switch">
									<input type="checkbox" name="<?php echo esc_attr( $tuki_option ); ?>[analytics_enabled]" value="1" <?php checked( $tuki_settings['analytics_enabled'], 1 ); ?> />
									<span class="tuki-switch-slider"></span>
									<span class="tuki-switch-text"><?php esc_html_e( 'Log queries, clicks, and chat-to-sale events', 'tukify' ); ?></span>
								</label>
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
