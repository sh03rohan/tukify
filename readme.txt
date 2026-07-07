=== Tukify — AI Shopping Assistant for WooCommerce ===
Contributors: shrohan3
Tags: woocommerce, ai, chatbot, semantic search, recommendations
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI shopping assistant for WooCommerce: semantic product search and grounded, conversational recommendations, all on your own server.

== Description ==

Tukify adds an AI shopping assistant to your WooCommerce store. A shopper types in plain language ("a gift for my dad, he likes wine and hiking") and Tukify understands the intent, finds matching products with semantic search, and replies conversationally with product cards — image, price, stock, and add-to-cart — right inside a chat interface, without the shopper leaving the page.

Tukify is **bring-your-own-key**: you enter your own Google Gemini API key and Tukify never pays for inference. All AI requests are made server-side in PHP — your API key is never exposed to the browser. Your product embeddings are stored in your own site database, so your catalog data stays on your server.

= Key features =

* **Semantic product search** — matches by meaning, not just keywords ("warm clothes for winter" surfaces jackets and sweaters).
* **Conversational RAG chat** — grounded answers that only ever recommend real, in-stock products; it never invents items.
* **Clarifying questions** — asks one short follow-up (with tappable quick replies) when a request is too vague, instead of dumping mediocre results.
* **Natural-language filters** — understands price, colour, size, brand, stock, category and sorting ("cheapest wireless headphones under $200") and applies them as real WooCommerce queries.
* **Comparison mode** — side-by-side comparison of 2–3 products with a one-line trade-off summary.
* **Policy / FAQ answers** — answers shipping, returns and warranty questions from your own pages and a custom Q&A knowledge base.
* **Cart-aware upsell** — suggests genuinely complementary items based on the cart.
* **Exit-intent re-engagement** — a helpful, dismissible prompt when a shopper is about to leave (once per visit, with a cooldown).
* **Visual search** — a shopper can upload an image and Tukify finds similar products in your catalog.
* **Background indexing** — embeds your catalog in the background via Action Scheduler; only re-embeds changed products.
* **Presentation surfaces** — a global floating chat widget plus three Elementor widgets (Chat, Search, Recommendations), each rendered in a Shadow DOM so your theme's CSS can't break them.
* **Analytics dashboard** — top queries, zero-result queries, click-through and chat-to-sale.

= Privacy and data =

All AI calls are made server-side; your API key is never sent to the browser. Product embeddings are stored in your own site's database. Tukify only contacts an external service (Google Gemini) when you have configured an API key and a shopper or the store uses a feature that needs it. See the **External services** section below for exactly what is sent and when.

= Requirements =

* WooCommerce (active).
* A Google Gemini API key (a free key from Google AI Studio is enough for development and small stores).
* PHP 7.4+.
* Elementor is optional (only needed for the Elementor widgets).

== External services ==

Tukify connects to the **Google Gemini API** (Google's Generative Language API) to provide semantic search, conversational answers, natural-language understanding, and image-based (visual) search. This is required for the plugin's AI features to work, and it only happens when you have entered your own Google Gemini API key in the plugin settings.

**What data is sent, and when:**

* **Catalog indexing** — when you index or re-index your products, the text of each product (title, short description, categories and key attributes) is sent to Google to generate a numeric embedding. This runs in the background and only for products that have changed.
* **Knowledge base indexing** — if you enable the policy/FAQ knowledge base, the content of the pages you select and any custom Q&A you enter is sent to Google to generate embeddings.
* **Search and chat** — when a shopper searches or chats, their message, a short recent conversation history, and the retrieved product context are sent to Google to embed the query and generate a grounded reply.
* **Visual search** — if a shopper uploads an image, that image is sent to Google to identify the product type before matching your catalog.

Data is sent to Google's servers. Tukify does not send data to any other third party. All requests are made from your server (PHP); the API key is never exposed to the browser.

* Google Gemini API terms of service: https://ai.google.dev/terms
* Google privacy policy: https://policies.google.com/privacy

Note: on Google's free tier, inputs may be used by Google to improve their models. If you handle real customer data, use a paid Google Gemini key (which is not used for model training) and review Google's terms.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/tukify` or install it from the Plugins screen.
2. Activate the plugin. WooCommerce must be installed and active.
3. Get a Google Gemini API key from Google AI Studio (aistudio.google.com).
4. Go to **Tukify → Settings**, paste your key, and click **Test connection**.
5. On the **Tukify** dashboard, click **Reindex all products** to embed your catalog (runs in the background).
6. Enable the floating widget in Settings, and/or drop the Tukify Elementor widgets onto a page.

== Frequently Asked Questions ==

= Does Tukify need a paid AI subscription? =

No. You bring your own Google Gemini API key. Google's free tier covers development and most small stores. Tukify never pays for inference on your behalf.

= Is my API key safe? =

Yes. The key is stored in your site's options, shown masked in the admin, and used only server-side. It is never sent to the browser or embedded in any front-end code.

= Where is my catalog data stored? =

Product embeddings are stored in a custom table in your own WordPress database. Tukify only sends product text to Google to generate those embeddings (see External services).

= Does it work without Elementor? =

Yes. The global floating chat widget works on any theme. Elementor is only required for the three optional Elementor widgets.

= Will it invent products that aren't in my store? =

No. Every answer is grounded in real retrieval — the assistant can only recommend products that exist in your catalog, and policy answers come only from your own content.

= What happens to my data if I delete the plugin? =

By default, deleting Tukify removes all of its data: its settings, its custom tables (product embeddings, knowledge base, analytics, back-in-stock subscribers, usage counters) and any cached data. If you'd rather keep everything for a later reinstall, enable "Keep Tukify's data when the plugin is deleted" under Settings → Advanced before deleting. Deactivating the plugin never removes any data.

== Screenshots ==

1. The chat widget answering a natural-language request with product cards.
2. The dark admin dashboard: connection status, metrics, reindex progress and top searches.
3. The settings screen (provider, models, knowledge base, conversion).
4. Side-by-side product comparison in chat.
5. The three Tukify Elementor widgets.

== Changelog ==

= 1.4.1 =
* Performance: added an (event_type, created_at) index for analytics range queries and a daily purge so the events table stays bounded.
* Performance: the semantic-search scan now reads embeddings in batches to cap peak memory on larger catalogs.

= 1.4.0 =
* Added API usage tracking with a per-day tokens/requests chart and estimated cost in Logs / Analytics.
* Added response caching (query embeddings + knowledge-base answers) with a TTL, enable/disable, and a clear button; nothing user-personal is ever cached.

= 1.3.0 =
* Added back-in-stock notifications: shoppers can ask to be emailed when an out-of-stock product returns, with a consent step, an admin list, an email template, and one-click unsubscribe.

= 1.2.0 =
* Added a quantity stepper on chat product cards.
* Added site-wide RAG over posts, pages, and products, with source citations under answers.
* Added secure order-status lookup, a size & fit advisor, "shop the look" multi-item visual search, demand insights, proactive re-engagement, and an opt-in in-chat checkout.
* Redesigned the admin dashboard.

= 1.1.0 =
* Added clarifying questions, natural-language filters, comparison mode, policy/FAQ knowledge base, cart-aware upsell, and exit-intent re-engagement.
* Added image-based (visual) search.
* Fixed cart accumulation when adding to cart from the chat widget.
* WooCommerce HPOS compatibility declared.

= 1.0.0 =
* Initial release: semantic search, RAG chat, background indexing, cart integration, analytics dashboard, floating widget and Elementor widgets.

== Upgrade Notice ==

= 1.1.0 =
Adds conversational filters, comparison, policy/FAQ answers, upsell and exit-intent, plus visual search and a cart fix. Reindex is not required.
