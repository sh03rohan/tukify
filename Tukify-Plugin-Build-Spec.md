# Tukify — AI Shopping Assistant for WooCommerce
### Complete Production Build Specification

> **Goal:** A fully working, production-grade WooCommerce plugin — not an MVP, not a demo. Every feature listed here must actually function end to end.
>
> **How to use this file:** Put this file inside the plugin folder, open the folder in VS Code, open Claude Code, and build **phase by phase** (Phase 0 → 8). "Phase by phase" is *how you reach a complete, working plugin* — each phase is a fully-finished slice, tested on a live local WordPress site before moving on. Do **not** ask Claude Code to build all phases in one shot; that produces code that looks done but breaks in ways nobody can debug. Finish a phase, test it, then continue.

---

## 0. Product summary

Tukify adds an AI shopping assistant to any WooCommerce store. A shopper types in natural language ("gift for my dad, he likes wine and hiking") and Tukify understands the *intent*, finds matching products with **semantic search**, and replies conversationally with product cards (image, price, stock, add-to-cart) — inside a chat interface, without the shopper leaving the page.

**Key product decisions (already made — do not change):**

- **Name:** Tukify. Text domain `tukify`. Code prefix `tuki_` (functions/hooks) and `TUKI_` / `Tuki_` (constants/classes).
- **AI provider:** Google **Gemini** is the default (its free tier covers all development and most small stores). **BYOK (Bring Your Own API Key):** the store owner enters their own key; Tukify never pays for inference. OpenAI and Anthropic are optional alternative providers behind the same interface.
- **Configuration → Settings page** (WP admin). **Anything placed/edited on a page → Elementor widgets** (drag-and-drop, styled visually in the Elementor panel).
- **Visual style:** clean, minimal, modern, dark by default, fully customizable.
- **Data stays on the store's own server** (embeddings in the site DB). This is a real privacy selling point.

---

## 1. Architecture overview

Three layers plus two presentation surfaces.

```
                    ┌─────────────────────────────────────┐
                    │  WP Admin Settings (global config)   │
                    │  API key · models · indexing · limits│
                    └─────────────────────────────────────┘
Product saved/updated
      │
      ▼
[Indexing layer]  → product text → Gemini embedding → store vector in DB
      │
      ▼
Shopper interacts via a PRESENTATION SURFACE:
   • Elementor widget on a page (chat / search / recommendations), OR
   • Global floating chat bubble (enabled in settings)
      │
      ▼
[Retrieval layer] → embed query → cosine similarity search → top-N products
      │
      ▼
[Conversation layer / RAG] → query + retrieved products → Gemini chat → grounded reply
      │
      ▼
Reply text + product cards (image, price, stock, add-to-cart) rendered in the surface
```

**RAG (Retrieval Augmented Generation):** always retrieve real products first, then let the model answer grounded in them. The model must never invent products that aren't in the catalog.

---

## 2. Local development environment (set up first)

You will develop live on a local WordPress site so testing is instant.

### LocalWP

1. Install **LocalWP** (free) from localwp.com.
2. New site → PHP 8.1+, MySQL 8.0, latest WordPress.
3. In wp-admin: install **WooCommerce**, run its setup wizard.
4. Install **Elementor** (free version is enough to register and use custom widgets).
5. Import sample products (WooCommerce ships a `sample_products.csv`). Import **at least 30–50 products across several categories** — you cannot properly test semantic search or recommendations with fewer.

### Plugin location

```
~/Local Sites/<your-site>/app/public/wp-content/plugins/tukify/
```
Open the `tukify/` folder in VS Code. Every PHP file you save is instantly live — refresh wp-admin or the storefront to test. No build step for PHP. (The frontend JS widget may use a small build; see Phase 6.)

### Enable debugging

In `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
Run `tail -f wp-content/debug.log` in a terminal to watch errors live. Use `error_log()` for debug output during development (strip or gate these before release).

### Gemini API key

1. Go to **aistudio.google.com** → **Get API key** → **Create API key** (new project is fine, no billing needed).
2. Copy the key (starts with `AIza…`). You'll paste it into Tukify's Settings (Phase 1).
3. **Never commit the key** to git. Keep it only in WP settings.

Free-tier facts (verify current numbers in AI Studio — they change): embeddings + Flash chat models are free with no card; generous daily/per-minute limits far above testing needs; limits are per Google Cloud project, not per key; free-tier inputs may be used by Google to improve models (fine for test data — note this in Tukify's docs so store owners with real customer data know to use a paid key).

### VS Code extensions

PHP Intelephense, WordPress Snippets, and optionally PHP_CodeSniffer with the WordPress standard.

---

## 3. File structure

```
tukify/
├── tukify.php                         # Main file: header + bootstrap
├── uninstall.php                      # Remove tables + options on uninstall
├── includes/
│   ├── class-tuki-plugin.php          # Singleton orchestrator, loads everything
│   ├── class-tuki-db.php              # Custom table create/query (dbDelta)
│   ├── class-tuki-settings.php        # Admin settings page + option storage
│   ├── class-tuki-indexer.php         # Product → embedding → DB (background jobs)
│   ├── class-tuki-embeddings.php      # Embedding orchestration + caching
│   ├── class-tuki-search.php          # Cosine similarity retrieval (pluggable backend)
│   ├── class-tuki-chat.php            # RAG: build prompt, call LLM, format reply + cards
│   ├── class-tuki-rest.php            # REST API endpoints (chat, search, add-to-cart)
│   ├── class-tuki-cart.php            # Cart context + add-to-cart helpers
│   ├── class-tuki-analytics.php       # Log queries, clicks, chat-to-sale
│   └── providers/
│       ├── interface-tuki-provider.php
│       ├── class-tuki-gemini.php      # default
│       ├── class-tuki-openai.php      # optional
│       └── class-tuki-anthropic.php   # optional (chat only; no embeddings)
├── admin/
│   ├── class-tuki-admin.php           # Menus, assets, reindex UI
│   ├── settings-page.php
│   ├── admin.css
│   └── admin.js
├── elementor/
│   ├── class-tuki-elementor.php       # Registers widgets + category
│   └── widgets/
│       ├── class-tuki-widget-chat.php
│       ├── class-tuki-widget-search.php
│       └── class-tuki-widget-recommendations.php
├── public/
│   ├── class-tuki-frontend.php        # Global floating widget + asset enqueue
│   ├── tukify-widget.js               # Chat/search UI (Shadow DOM)
│   └── tukify-widget.css
├── assets/
│   └── icons, logo, etc.
└── languages/
    └── tukify.pot
```

Everything prefixed `tuki_` / `TUKI_` / `Tuki_` to avoid collisions.

---

## 4. Database

One custom table for embeddings, created on activation via `dbDelta()`.

```
Table: {prefix}_tuki_embeddings
─────────────────────────────────────────────
id            BIGINT  PRIMARY KEY AUTO_INCREMENT
product_id    BIGINT  (indexed)
content_hash  VARCHAR(64)   # hash of indexed text; skip re-embed if unchanged
embedding     LONGTEXT      # JSON array of floats
model         VARCHAR(100)  # embedding model used (so you can re-embed on model change)
dims          INT           # vector length
updated_at    DATETIME
```

Also a lightweight analytics table:

```
Table: {prefix}_tuki_events
─────────────────────────────────────────────
id          BIGINT PRIMARY KEY AUTO_INCREMENT
event_type  VARCHAR(30)   # query | product_click | add_to_cart | sale
query_text  TEXT NULL
product_id  BIGINT NULL
session_id  VARCHAR(64)   # anonymous session hash
created_at  DATETIME
```

`content_hash` prevents paying to re-embed unchanged products. On uninstall, both tables and all `tuki_*` options are removed.

> **Scaling:** MySQL + PHP cosine similarity is fine to ~1,000 products. Build the search layer behind an interface (`Tuki_Search_Backend`) so a future release can swap in an external vector DB (Qdrant/Pinecone) without rewriting callers.

---

## 5. Provider abstraction (Gemini default, BYOK)

One interface so nothing else in the code cares which provider is selected.

```php
interface Tuki_Provider {
    // Returns an array of float-vectors, one per input string.
    public function embed( array $texts ): array;

    // $context = retrieved products + cart; returns assistant reply text.
    public function chat( array $messages, array $context ): string;

    // Optional streaming variant for latency (see Phase 4/6).
}
```

Implement `Tuki_Gemini` (default), `Tuki_OpenAI`, `Tuki_Anthropic`.

**Default models (Gemini free tier; confirm exact current names in Google's docs at build time — model strings change):**
- Embeddings: `gemini-embedding-001` (or current Gemini embedding model)
- Chat: `gemini-2.5-flash` (fast, cheap) with `gemini-2.5-flash-lite` as an economy option

**Rules:**
- API keys stored via `get_option()` / `update_option()`, sanitized on save, shown masked in the UI (`AIza…last4`). Never hardcode, never expose to the frontend — all AI calls happen server-side in PHP.
- Anthropic has **no embeddings endpoint**; if a user selects Anthropic for chat, embeddings still use Gemini or OpenAI. State this in settings.
- All provider calls: timeouts, retry with exponential backoff on `429`/`5xx`, and clear error surfacing.

---

## 6. Configuration vs. page components (the key split)

**WP Admin Settings** (global, set-once): provider + API key(s); embedding model; chat model; reindex controls + progress; assistant name/persona; retrieval count N; guest rate limits; global default accent color and dark/light default; enable/disable the global floating widget; analytics on/off.

**Elementor widgets** (placed and styled per-page, drag-and-drop): the actual shopper-facing surfaces. Store owners drop these onto any page/template and restyle them visually — no code. This is how "the UI must be editable and match the site" is satisfied.

Tukify ships **three Elementor widgets** (Phase 7):

1. **Tukify Chat** — an embedded chat panel (inline block or launcher button).
2. **Tukify Search** — an AI semantic search bar; results render as product cards.
3. **Tukify Recommendations** — a cart-aware / context-aware "you might like" product block.

Each widget exposes **Elementor controls** so the owner customizes without CSS: accent color, background, text colors, dark/light, border radius, size/columns, placeholder text, heading, number of products, and (for chat) launcher vs. inline mode. Sensible dark defaults out of the box.

---

## 7. Visual design system (dark, minimal, modern — customizable)

Defaults below. Every color is a CSS variable so Elementor controls (and the settings global default) can override it live.

```css
:root {
  --tuki-bg:         #0E0E11;   /* panel background (near-black, not pure black) */
  --tuki-surface:    #1A1A1F;   /* assistant bubble, input */
  --tuki-card:       #16161A;   /* product card */
  --tuki-border:     rgba(255,255,255,0.08);
  --tuki-text:       #F5F5F7;
  --tuki-text-muted: #9A9AA2;
  --tuki-text-faint: #6A6A72;
  --tuki-accent:     #7C6FF0;   /* user bubble, buttons, send — single accent */
  --tuki-success:    #5BC98E;   /* "in stock", online dot */
  --tuki-radius:     12px;
  --tuki-radius-lg:  20px;
  --tuki-font:       inherit;   /* inherit the store's font by default */
}
```

**Principles that keep it feeling premium:** near-black (not pure black); exactly one accent color plus one success-green, everything else neutral; hairline borders (~0.5–1px, low opacity); generous spacing; large radii on the panel, medium on cards; typing indicator and (ideally) streamed responses.

**Customization surface:**
- **Owner exposes** accent, background, text, dark/light, radius, size — via Elementor controls or the settings global default.
- **Owner cannot break** the internal spacing/layout — those are locked so the clean look survives every theme.

**Theme safety:** render the frontend widget inside a **Shadow DOM** so the store's theme CSS can't leak in and break Tukify's styling, and vice-versa.

---

## 8. Build phases

Each phase is a complete, tested slice. Do not start the next phase until the current one passes its acceptance test on your local site.

### Phase 0 — Scaffold
- `tukify.php` with proper plugin header, activation/deactivation hooks.
- `Tuki_Plugin` singleton that loads `includes/`.
- `Tuki_DB` creates both tables via `dbDelta` on activation; `uninstall.php` drops them + options.
- **Acceptance:** activates/deactivates with zero notices in debug.log; both tables exist (check via Adminer in LocalWP).

### Phase 1 — Settings + providers
- Settings page under WooCommerce (or its own top-level menu).
- Provider dropdown (Gemini default), API key field(s) (masked), model selectors, retrieval count N, guest rate limit, global accent + dark/light default, floating-widget toggle.
- Provider interface + `Tuki_Gemini` with a "Test connection" button that does a tiny live embed/chat call and reports success/failure.
- **Acceptance:** settings persist; masked key; "Test connection" succeeds with a valid Gemini key and fails gracefully with an invalid one.

### Phase 2 — Embeddings + indexing (production-grade)
- `Tuki_Embeddings`: batched embed calls, transient cache, `content_hash` skip.
- `Tuki_Indexer`: builds per-product text (title + short description + categories + key attributes), embeds changed products, writes vectors.
- Hooks: `woocommerce_update_product`, `save_post_product`, `woocommerce_product_set_stock`, product delete → remove row.
- "Reindex all" runs via **Action Scheduler** (bundled with WooCommerce) in batches (e.g., 20/product per batch) with a live progress bar; exponential backoff on Gemini `429`.
- **Acceptance:** full reindex completes for all sample products with a moving progress bar; editing one product re-embeds only that product; deleting removes its row; a big reindex never times out or hard-fails on rate limits.

### Phase 3 — Semantic search
- `Tuki_Search::query(string $text, int $n)`: embed query, load candidate vectors, cosine similarity in PHP, return ranked product IDs + scores. Backend behind `Tuki_Search_Backend` interface.
- **Acceptance:** "warm clothes for winter" surfaces jackets/sweaters even when those words aren't in the titles — proving semantic (intent) matching, not keyword matching. Verify via a temporary WP-CLI command or admin tool.

### Phase 4 — REST API + RAG chat
- `POST /wp-json/tukify/v1/chat` — body: message + short history; nonce for logged-in users, IP rate-limiting for guests.
- Pipeline: search → build RAG prompt → Gemini chat → return `{ reply, products:[{id,title,price,stock,image,url}] }`.
- RAG system prompt: recommend only from provided products; be concise; ask one clarifying question if intent is unclear; never invent products; reply in the shopper's language.
- `POST /wp-json/tukify/v1/search` — semantic search returning product cards.
- `POST /wp-json/tukify/v1/add-to-cart` — WooCommerce add-to-cart with real-time stock check.
- **Acceptance:** curl/Postman returns grounded replies + correct product data; invalid key → friendly error, no crash; guest spam gets throttled.

### Phase 5 — Analytics
- `Tuki_Analytics`: log `query`, `product_click`, `add_to_cart`, and attribute `sale` on WooCommerce order completion when items were surfaced by Tukify (session-based attribution).
- Admin dashboard: top queries, zero-result queries, click-through, chat-to-sale rate, estimated API cost of last reindex.
- **Acceptance:** interacting with the widget produces real rows; dashboard numbers reconcile with actions taken.

### Phase 6 — Frontend widget (Shadow DOM)
- `tukify-widget.js` + `tukify-widget.css` rendered in a shadow root; vanilla JS or a tiny build (no heavy framework).
- Chat UX: launcher bubble / inline mode; message list; typing indicator; streamed response if feasible; product cards with image, title, price, stock badge, **Add to Cart** (hidden when out of stock, real-time check); "zero-result rescue" (suggest closest alternatives instead of "nothing found").
- Global floating widget wired to settings toggle + global accent/dark-light defaults.
- **Acceptance:** on the storefront across Storefront + Astra + one block theme, the widget looks identical (Shadow DOM holds), chat works, add-to-cart increments the cart, out-of-stock hides the button.

### Phase 7 — Elementor widgets
- `Tuki_Elementor` registers a "Tukify" widget category and the three widgets.
- **Tukify Chat**, **Tukify Search**, **Tukify Recommendations**, each extending `\Elementor\Widget_Base` with: content controls (heading, placeholder, product count, mode) and **style controls** (accent, background, text, dark/light, radius, size/columns) mapped to the CSS variables. Live preview updates in the Elementor editor.
- Recommendations widget is **cart-aware**: reads current cart and suggests complementary items; on non-cart pages, falls back to context (current product/category) or popular items.
- **Acceptance:** all three drag onto a page; changing an Elementor color control restyles the widget live in the editor and on the front end; they inherit dark defaults; they work on the same page together without conflicts.

### Phase 8 — Hardening + release polish
- Security pass: sanitize every input (`sanitize_text_field` etc.), escape every output (`esc_html`, `wp_json_encode`), verify nonces, confirm no key reaches the frontend, finalize guest rate-limiting.
- Performance pass: confirm no AI calls on page load; embeddings batched + cached; queries indexed.
- Graceful failure everywhere: missing/invalid key, provider outage, empty catalog → friendly messages, never a white screen.
- i18n: wrap all strings, generate `tukify.pot`.
- Compatibility: latest WordPress + WooCommerce + Elementor; PHP 8.1–8.3.
- Uninstall cleanup verified.
- **Acceptance:** full end-to-end run on a fresh local site with no notices; every failure mode degrades gracefully; strings translatable.

---

## 9. Non-negotiable engineering guardrails

Tell Claude Code these explicitly and hold the line on them:

- **No synchronous AI on page load.** Indexing = Action Scheduler background jobs. Chat/search = user-triggered REST calls only.
- **Cost control.** Always check `content_hash` before re-embedding. Batch embeddings. Cache query embeddings in transients.
- **Rate limits.** Exponential backoff on Gemini `429`/`5xx` so a large reindex can't fail midway.
- **Security.** Server-side AI calls only; key never exposed. Sanitize in, escape out, verify nonces, throttle guests.
- **Theme safety.** Shadow DOM for the frontend widget.
- **Grounded answers.** RAG only recommends real, in-stock catalog products; never invents.
- **Graceful failure.** Any error → friendly message + logged detail; never break the store page.
- **Latency UX.** Typing indicator; stream tokens where possible.
- **Clean code.** WordPress coding standards, `tuki_`/`TUKI_` prefixes, no dead code, no leftover debug logging in release.

---

## 10. Definition of "done" (production, not MVP)

The plugin is complete when all of these are true:

- Store owner installs Tukify, enters a Gemini key, clicks "Test connection" → success.
- One-click reindex processes the entire catalog in the background with a progress bar.
- Editing/adding/deleting a product updates the index automatically.
- Semantic search returns intent-matched products.
- The chat assistant answers conversationally, grounded only in real products, in the shopper's language, and never invents items.
- Add-to-cart works from chat/search results; out-of-stock items can't be added.
- The floating widget (settings) and all three Elementor widgets work, look consistent, and are visually customizable — accent color and dark/light match any site.
- Analytics dashboard shows real queries, click-through, and chat-to-sale.
- Every failure mode (bad key, outage, empty catalog, rate limit) degrades gracefully.
- Works across common themes and the latest WordPress/WooCommerce/Elementor; strings are translatable; uninstall cleans up.

---

## 11. Name check before public release

Tukify looks clear (only a tiny, unrelated food app surfaced). Before publishing, confirm:
1. `wordpress.org/plugins` search for "Tukify" → plugin slug free.
2. `tukify.com` / `.io` domain availability (branding).
3. Quick trademark search in your country for ecommerce/software classes.

The name is only referenced via the `tuki_` prefix, text domain `tukify`, and display strings — renaming later is a find-and-replace, so this never blocks building.

---

## 12. First message to give Claude Code

> "Read `Tukify-Plugin-Build-Spec.md` in this folder. We are building **Phase 0 only**. Scaffold the plugin exactly per the file structure and Phase 0 acceptance criteria: `tukify.php` with a proper plugin header and activation/deactivation hooks; a `Tuki_Plugin` singleton in `includes/class-tuki-plugin.php` that loads the includes folder; `Tuki_DB` in `includes/class-tuki-db.php` that creates the `_tuki_embeddings` and `_tuki_events` tables via dbDelta on activation; and `uninstall.php` that drops those tables and all `tuki_` options. Use WordPress coding standards and the `tuki_`/`TUKI_`/`Tuki_` prefixes. Do not build anything from later phases."

Then activate the plugin, check debug.log and the tables, and only then say **"Phase 1"** — and continue through Phase 8.
