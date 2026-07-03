# Tukify — Advanced Features Specification

> **Purpose:** Add six advanced capabilities on top of the working Tukify plugin. These turn the chat from a search box into a real shopping assistant, increase conversion, and reduce support load. Hand this to Claude Code and build the features **one at a time, in the order below**, testing each before the next. This spec assumes Phases 0–7 of the main build spec are complete and working (indexing, semantic search, RAG chat, cart, analytics, admin UI, frontend widget, Elementor widgets).
>
> **Prefixes:** keep `tuki_` / `TUKI_` / `Tuki_`. Everything grounded — never invent products. All AI calls server-side (Gemini via PHP, key never exposed). Every new setting goes in the existing dark Settings screen using the established tokens.

---

## Build order (why this sequence)

1. **Clarifying questions** — foundation; improves every other feature by resolving vague intent first.
2. **Natural language filters** — precision layer the other features rely on.
3. **Comparison mode** — builds on retrieval + filters.
4. **Policy / FAQ answers** — separate knowledge source; independent, high value.
5. **Cart-aware upsell** — needs solid retrieval; drives revenue.
6. **Exit-intent trigger** — presentation layer on top of everything; do last.

Build one, test with its acceptance checks, then continue.

---

## Feature 1 — Clarifying questions

**Goal:** When a shopper's request is too vague to answer well, the assistant asks one short follow-up instead of dumping mediocre results.

**Behavior:**
- On each message, the RAG step assesses whether intent is specific enough to return good products.
- If **vague** (e.g. "I need a gift", "show me something nice", "looking for headphones" with 40 matches), ask **one** targeted clarifying question — budget, recipient, use-case, or the single most disambiguating attribute for that catalog.
- If **specific enough**, skip straight to results. Never ask more than one question in a row; never interrogate.
- After the shopper answers, combine the original request + answer and proceed.

**Implementation:**
- Extend the structured RAG decision output to include: `needs_clarification` (bool), `clarifying_question` (string), and optionally `suggested_quick_replies` (array of 2–4 short options the widget renders as tappable chips).
- Widget renders quick-reply chips when present (tapping one sends it as the next message) — much better UX than making the shopper type.
- Setting: toggle "Ask clarifying questions" (default on) + max questions per conversation (default 1–2).

**Acceptance:**
- "I need a gift" → assistant asks one question (e.g. "Who's it for, and what's your budget?") with quick-reply chips, no product dump.
- "wireless noise-cancelling headphones under $200" → skips clarification, returns products directly.
- After answering the clarifying question, relevant products appear.

---

## Feature 2 — Natural language filters

**Goal:** Extract structured filters from plain language and apply them as real WooCommerce queries, so results are precise — not just semantically close.

**Behavior:** Parse constraints from the message and apply them on top of (or instead of) semantic search:
- **Price:** "under $50", "between $20 and $40", "cheap", "premium" → price range.
- **Attributes:** "red", "large", "cotton", "waterproof" → map to product attributes/variations.
- **Brand:** "Nike", "Sony" → brand taxonomy/attribute.
- **Stock:** "in stock", "available now" → stock status.
- **Category:** "in kitchen", "for the garden" → category.
- **Sort:** "cheapest", "newest", "best rated" → order.

**Implementation:**
- The RAG/parse step returns a structured `filters` object: `{ price_min, price_max, attributes:{}, brand, in_stock, category, sort }`.
- Map to a `WP_Query` / `wc_get_products()` query with tax_query + meta_query, combined with semantic ranking:
  - **Hybrid approach:** apply hard filters (price, stock, brand, category) as query constraints, then rank the filtered set by semantic similarity to the free-text part. This is more accurate than semantics alone.
- Only map attributes/brands that actually exist in the store's taxonomy (read available attributes dynamically) — don't invent filters.
- If a filter yields zero results, say so and offer to relax it ("No red ones under $30 — want to see red ones a bit higher, or other colours under $30?").
- Setting: toggle hybrid filtering (default on); similarity threshold reused from earlier work.

**Acceptance:**
- "waterproof jacket under $100 in black" → only black, waterproof jackets ≤ $100.
- "cheapest wireless headphones" → wireless headphones sorted by price ascending.
- "red dress size M in stock" → applies colour + size + stock filters.
- A filter with no matches → friendly relax-the-filter offer, not an empty screen.

---

## Feature 3 — Comparison mode

**Goal:** When a shopper asks to compare, show a clear side-by-side of the candidates.

**Behavior:**
- Triggered by "compare X and Y", "which is better", "difference between these", or after the assistant has shown 2–3 products and the shopper asks to compare them.
- Show a side-by-side comparison of 2–3 products across the most relevant dimensions: price, key attributes/specs, rating, stock, and a one-line AI summary of the trade-off ("A is cheaper and lighter; B has longer battery").
- Each compared product keeps an add-to-cart action.

**Implementation:**
- Detect comparison intent in the structured decision (`intent = compare`, plus the product IDs to compare — resolved from the current message or recent conversation context).
- Build a comparison payload: for each product, pull price, rating, stock, and a curated set of attributes (use the attributes the products share so columns line up).
- Widget renders a comparison card/table (dark tokens, horizontally scrollable on mobile) with an AI trade-off summary line above it.
- Cap at 3 products to keep it readable; if more are referenced, compare the top 3 and note the rest.

**Acceptance:**
- "compare the Bose and Sony headphones" → side-by-side with price/specs/rating/stock + a one-line trade-off summary, each with add-to-cart.
- After showing 3 speakers, "which of these is best for bass?" → comparison focused on the relevant dimension.
- Comparing products with no shared attributes → still compares price/rating/stock gracefully.

---

## Feature 4 — Policy / FAQ answers

**Goal:** Answer shipping, returns, warranty, and general store questions from the store's own content, cutting support tickets.

**Behavior:**
- Questions like "what's your return policy", "how long is shipping to X", "do you offer warranty", "how do I track my order" get answered from the store's real policy/FAQ content — not made up.
- If the answer isn't in the knowledge base, say so and offer to contact support / show the relevant page, rather than guessing.

**Implementation:**
- Add a **knowledge base** the owner populates in settings:
  - Auto-source: pull content from selected WordPress pages (a multi-select of pages — e.g. Shipping, Returns, Terms, FAQ) and WooCommerce's built-in policy pages.
  - Manual: a simple Q&A editor for custom entries.
- Embed this content into the existing embeddings table (or a separate `_tuki_kb` table) so policy questions run through the same RAG retrieval — but tagged as `kb` so product queries and policy queries are routed correctly.
- Intent routing: classify each message as `product` vs `policy/support` vs `order` and retrieve from the right source.
- Re-embed KB content when the source pages change (hook on page save for selected pages).
- Setting: select KB source pages, manage manual Q&A, toggle "Answer policy/FAQ questions".

**Acceptance:**
- "what's your return policy" → accurate answer sourced from the store's returns page, with a link to it.
- "do you ship to Canada" → answers if the shipping page covers it; otherwise a graceful "I couldn't find that — here's the shipping page / contact support".
- A product question still returns products (routing works); a policy question does not dump products.

---

## Feature 5 — Cart-aware upsell

**Goal:** Suggest genuinely complementary items based on what's in the cart, lifting average order value.

**Behavior:**
- When the shopper opens chat with items in the cart, or after adding an item, the assistant can proactively suggest complementary products ("For that camera, people often add this SD card and a case").
- Suggestions must be relevant complements (accessories, consumables, pairings) — not random popular items, and never items already in the cart.
- Non-pushy: one tasteful suggestion moment, dismissible.

**Implementation:**
- Read cart contents via the existing cart helper (`get_cart_context`).
- Determine complements using, in order of preference: WooCommerce's native cross-sells/up-sells and linked products if the owner set them; then category/attribute affinity; then semantic similarity to cart items (excluding same-category duplicates and out-of-stock).
- Optionally let the LLM phrase the suggestion naturally given the cart context, but the product set comes from real retrieval (grounded).
- Respect stock and exclude cart items.
- Setting: toggle cart-aware upsell, max suggestions (default 2–3), and whether it's proactive (auto) or only on request.

**Acceptance:**
- Cart has a camera → suggests a compatible SD card / case / bag, not a random product, none already in cart, all in stock.
- Owner-defined cross-sells are respected when present.
- Empty cart → no upsell; disabling the setting removes the behavior entirely.

---

## Feature 6 — Exit-intent trigger

**Goal:** Re-engage a shopper who's about to leave, with a helpful, non-annoying prompt.

**Behavior:**
- Detect exit intent (desktop: cursor moving toward the browser chrome/leaving the viewport top; mobile: rapid scroll-up / back gesture heuristics).
- Open the chat with a contextual, helpful message — e.g. "Need help finding something? I can help you choose" or, if there's a cart, a gentle nudge ("Want me to help you finish up? I can answer any questions").
- Fire **once per session**, respect a cooldown, and never on shoppers who've already engaged with the chat. Fully dismissible.

**Implementation:**
- Frontend: exit-intent detection in the widget JS (mouseleave toward top on desktop; a mobile heuristic). Guard with a session flag + cooldown so it never repeats or annoys.
- The trigger message is configurable; support a cart-aware variant vs. a browsing variant.
- No discount logic required, but leave a filter/hook so the owner could later attach a coupon offer.
- Respect the global widget enable state and don't fire if chat is already open or previously engaged this session.
- Setting: toggle exit-intent, choose the message(s), set frequency/cooldown, enable/disable on mobile.

**Acceptance:**
- Moving the cursor to leave (desktop) → chat opens once with the configured message; leaving again same session → does not re-fire.
- Shopper who already used the chat → no exit-intent popup.
- With items in cart → cart-aware message; empty → browsing message.
- Disabling the setting removes the behavior; mobile toggle works.

---

## Cross-cutting notes

- **One structured decision object** should carry intent + clarification + filters + comparison targets + routing (product/policy/order). Extend it feature by feature rather than adding parallel systems.
- **Grounding always holds:** every product shown comes from real retrieval; every policy answer from real KB content. Never invent.
- **Cost:** these add more/longer Gemini calls. Keep using Flash models, cache where possible, and reuse retrieved context within a conversation turn instead of re-retrieving repeatedly.
- **Settings:** all new toggles live in the existing dark Settings screen, grouped sensibly (e.g. a "Conversation" section and a "Conversion" section), matching the admin UI design spec tokens.
- **Analytics:** log new event types (clarification_shown, filter_applied, comparison_shown, policy_answered, upsell_shown, upsell_clicked, exit_intent_shown) so the dashboard can show what's working.

---

## First message to give Claude Code

> "Read `Tukify-Advanced-Features-Spec.md`. We're adding Feature 1 (Clarifying questions) ONLY. Extend the structured RAG decision output with `needs_clarification`, `clarifying_question`, and `suggested_quick_replies`; have the widget render tappable quick-reply chips; add the settings toggle and max-questions control using the existing dark tokens. Keep everything grounded and server-side. Build only Feature 1, then stop so I can test against its acceptance checks."

Then test, then say "Feature 2" — and continue through Feature 6.
