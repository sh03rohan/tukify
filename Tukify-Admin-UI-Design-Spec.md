# Tukify — Admin UI Design Specification

> **Purpose:** Rebuild the Tukify admin interface to match the approved dark dashboard exactly. This covers the WP-admin **Dashboard** screen and the **Settings** screen. Both use one shared dark design system so the whole plugin feels like a single premium app. Hand this file to Claude Code alongside the main build spec when building the admin UI (Phase 1 settings + Phase 5 dashboard).

---

## 1. Design tokens (single source of truth)

Define these once as CSS variables and use them everywhere in the admin UI. Never hardcode a raw hex outside this block — reference the variables so a future re-theme is one edit.

```css
:root {
  /* Surfaces */
  --tuki-bg:         #0E0E11;   /* main panel background (near-black, NOT pure black) */
  --tuki-card:       #16161A;   /* metric cards, panels, list containers */
  --tuki-card-2:     #1A1A1F;   /* nested/secondary surfaces, inputs */
  --tuki-track:      #232329;   /* progress bar track, empty bars */

  /* Borders */
  --tuki-border:     rgba(255,255,255,0.08);  /* hairline panel borders */
  --tuki-border-soft:rgba(255,255,255,0.06);  /* list-row dividers */

  /* Text */
  --tuki-text:       #F5F5F7;   /* primary: headings, numbers, values */
  --tuki-text-2:     #D8D8DE;   /* list item labels */
  --tuki-text-muted: #9A9AA2;   /* captions, secondary labels, counts */

  /* Accent + status */
  --tuki-accent:     #7C6FF0;   /* buttons, positive-trend, brand icon tint */
  --tuki-accent-bg:  #1C1A2E;   /* brand icon chip background */
  --tuki-accent-ink: #9D92F5;   /* brand icon glyph color */
  --tuki-success:    #5BC98E;   /* connected, in-stock, synced, filled progress */
  --tuki-success-bg: #14231C;   /* success pill background */
  --tuki-warning:    #EF9F27;   /* zero-result / attention states */

  /* Shape */
  --tuki-radius:     9px;    /* buttons, small controls */
  --tuki-radius-md:  12px;   /* cards, panels */
  --tuki-radius-lg:  16px;   /* outer container */
  --tuki-radius-pill:20px;   /* status pills */
}
```

**Aesthetic rules that keep it premium (do not deviate):**
- Near-black background, never pure `#000`.
- Exactly **one** accent (violet) + **one** success (green) + **one** warning (amber). Everything else is neutral gray. No other colors.
- Hairline borders only (~0.5px, low-opacity white). Never heavy/bright borders.
- Generous padding and spacing — breathing room, never cramped.
- Two font weights only: 400 (regular) and 500 (medium). No bold-heavy 600/700.
- Sentence case everywhere. No ALL CAPS, no Title Case except proper nouns (Tukify, Gemini).
- Numbers are large and calm (24px/500); labels are small and muted (12px).

---

## 2. Layout structure

The whole admin screen sits inside one rounded dark container so it reads as an "app" inside WP-admin.

```
┌─ OUTER PANEL (--tuki-bg, --tuki-radius-lg, hairline border) ────────┐
│                                                                     │
│  HEADER ROW (border-bottom hairline)                                │
│   [brand icon]  Tukify                        [ status pill ]       │
│                 AI shopping assistant                               │
│                                                                     │
│  BODY (padding 20px 22px)                                           │
│   • Metric cards row (auto-fit grid, 3 cards)                       │
│   • Index panel (reindex button + progress bar)                     │
│   • Top searches list                                               │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

- Outer container: `background: var(--tuki-bg); border: 0.5px solid var(--tuki-border); border-radius: var(--tuki-radius-lg); overflow: hidden;`
- Give the container a max-width (e.g. 900px) and left margin so it doesn't crash into WP-admin's menu; add ~20px breathing space around it.
- Font: inherit or use the plugin's sans stack.

---

## 3. Header row

- Padding: `18px 22px`. Bottom hairline: `0.5px solid var(--tuki-border-soft)` (use `--tuki-border` value ~0.07 alpha).
- **Brand icon chip:** 34×34px, `border-radius: 9px`, `background: var(--tuki-accent-bg)`, centered glyph in `var(--tuki-accent-ink)`, icon ~18px (a sparkle/spark mark).
- **Title block:** "Tukify" at 16px/500 in `--tuki-text`; subtitle "AI shopping assistant" at 12px in `--tuki-text-muted`, 2px top margin.
- **Status pill (right):** pill shape (`border-radius: var(--tuki-radius-pill)`), `padding: 6px 12px`, `background: var(--tuki-success-bg)`, `border: 0.5px solid rgba(91,201,142,0.25)`. Inside: a 7px green dot + text "Gemini connected" at 12px in `--tuki-success`.
  - **State-driven:** connected → green ("Gemini connected"); missing/invalid key → red variant (use `#EF9F27`/amber or a red family with matching bg + border) reading "Not connected" or "Check API key". This pill is the at-a-glance health signal.

---

## 4. Metric cards row

Responsive grid: `display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;`. Three cards (add a 4th later if needed — e.g. estimated API cost).

Each card:
- `background: var(--tuki-card); border-radius: var(--tuki-radius-md); padding: 14px 16px;` (no border — the fill separates it).
- **Label** (top): 12px, `--tuki-text-muted`.
- **Value** (middle): 24px/500, `--tuki-text`, 6px top margin. **Always round numbers** (`482`, `1,204`, `14.2%`) — no float artifacts; use `toLocaleString()` for thousands.
- **Sub-line** (bottom): 11px, 3px top margin. Color encodes meaning:
  - positive/synced → `--tuki-success` ("All synced", "87 orders")
  - trend → `--tuki-accent` ("+18% vs last")

**The three default cards:**
1. Products indexed — value = indexed count; sub = "All synced" (green) or "N pending" (muted/amber).
2. Queries this week — value = query count; sub = week-over-week trend (accent).
3. Chat-to-sale — value = conversion %; sub = attributed order count (green).

---

## 5. Index / reindex panel

A single card: `background: var(--tuki-card); border-radius: var(--tuki-radius-md); padding: 16px 18px;`.

- **Top row** (space-between):
  - Left: title "Product index" (14px/500, `--tuki-text`) + caption "Last reindexed 2 hours ago" (12px, `--tuki-text-muted`, 2px margin). Caption is dynamic (relative time).
  - Right: **Reindex all** button — `background: var(--tuki-accent); color: #fff; font-size: 12px; font-weight: 500; padding: 8px 16px; border-radius: var(--tuki-radius);`. This is the ONE primary (accent-filled) button on the screen.
- **Progress bar:** track `height: 8px; background: var(--tuki-track); border-radius: 4px; overflow: hidden;`. Fill: `height: 100%; background: var(--tuki-success);` width = % complete. During an active reindex the fill animates as batches complete (driven by the Action Scheduler job progress).
- **Progress caption:** below the bar, 11px, `--tuki-text-muted`: "482 / 482 products embedded" (live counts).

Behavior: clicking Reindex all kicks off the batched background job; the bar and caption update via periodic polling (AJAX/REST) so the admin sees real progress, not a frozen screen.

---

## 6. Top searches list

A single card: `background: var(--tuki-card); border-radius: var(--tuki-radius-md); padding: 16px 18px;`.

- **Heading:** "Top searches" (14px/500, `--tuki-text`), 14px bottom margin.
- **Rows:** each `display: flex; justify-content: space-between; align-items: center; padding: 9px 0;` with a bottom divider `0.5px solid var(--tuki-border-soft)` (omit divider on the last row).
  - Left: query text (13px, `--tuki-text-2`).
  - Right: count (12px, `--tuki-text-muted`).
- **Zero-result rows are special:** when a query returned no products, prefix the label with an amber warning icon (`ti-alert-triangle`, ~14px, `--tuki-warning`) and render the right-side value as "0 results" in `--tuki-warning`. These rows are the actionable insight — they tell the owner what shoppers want but can't find (missing products or bad tagging).

Populate from the `_tuki_events` analytics table (top query strings by count; zero-result flagged where retrieval scored below threshold).

---

## 7. Settings screen (same system, different content)

The Settings screen reuses every token and the same outer dark panel. Structure it as labeled sections inside the panel:

- **Section pattern:** section title (14px/500, `--tuki-text`) + helper caption (12px, `--tuki-text-muted`) + the control below, each section separated by a hairline divider and ~18px spacing.
- **Inputs / selects / textareas:** `background: var(--tuki-card-2); border: 0.5px solid var(--tuki-border); border-radius: var(--tuki-radius); color: var(--tuki-text);` with muted placeholder. Comfortable height (~38–40px), 10–14px horizontal padding.
- **Sections to include** (from the main build spec §6): provider dropdown (Gemini default), API key field (masked as `AIza…last4`) + a "Test connection" button, embedding model, chat model, assistant name/persona, retrieval count N, guest rate limit, global default accent color (color picker), dark/light default, enable global floating widget toggle, analytics on/off.
- **Buttons:** primary actions (Save changes, Test connection) use the accent-filled style; secondary/neutral actions use a ghost style (`background: transparent; border: 0.5px solid var(--tuki-border); color: var(--tuki-text);`). Only **one** accent button emphasized per view.
- **Toggles:** custom switch styled with the accent when on, `--tuki-track` when off.
- **Test connection result:** inline status text under the button — green "Connected" or amber/red "Couldn't reach Gemini — check the key", matching the status-pill logic.

---

## 8. Copy / tone (microcopy)

- Sentence case, no terminal punctuation on labels/buttons, contractions allowed.
- Buttons are verb-first: "Reindex all", "Save changes", "Test connection".
- Status is plain and non-shouty: "Gemini connected", "All synced", "482 / 482 products embedded", "0 results".
- Errors say what to do: "Couldn't reach Gemini — check the API key" (never a raw exception, never "Error:").

---

## 9. Integration notes for WP-admin

- WP-admin ships its own light gray chrome. Your dark panel is intentionally an "app inside the app" — keep ~20px margin around the outer container so it never collides with the admin menu or notices area.
- Suppress or contain WP admin notices inside your screens where possible (they clash with the dark panel). At minimum, leave top spacing so a stray notice doesn't overlap the header.
- Enqueue the admin CSS/JS **only on Tukify's own screens** (check the current screen ID), never globally — don't leak styles into the rest of wp-admin.
- Round every displayed number. Relative times ("2 hours ago") via `human_time_diff()`.
- All strings translatable (text domain `tukify`).

---

## 10. Acceptance (admin UI matches the design)

- Dashboard shows the dark panel: header with brand chip + state-driven status pill; three metric cards; reindex panel with a working, polling progress bar; top-searches list with amber zero-result rows.
- Settings screen uses the same tokens/panel; API key masked; Test connection gives inline green/amber feedback.
- Only one accent-filled button per screen; everything else neutral/ghost.
- No raw hex outside the token block; numbers rounded; strings translatable; styles scoped to Tukify screens only; clean debug.log.
