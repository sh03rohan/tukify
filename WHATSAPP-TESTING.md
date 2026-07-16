# Tukify — WhatsApp channel manual test checklist

Dev document. Not shipped: `.distignore` excludes `*.md` from the production zip.

Meta requires a **public HTTPS** webhook, so a Local/localhost site needs a tunnel.

---

## ⚠️ The channel ships LOCKED

`TUKI_WHATSAPP_ENABLED` defaults to **false** in `tukify.php`. While it is false:

- Settings → Channels shows the blurred "Coming soon" teaser; all fields are
  `disabled` (not focusable, not submitted).
- `/wp-json/tukify/v1/whatsapp` is **not registered** (404).
- `{prefix}tuki_wa_sessions` is **not created**.
- No WhatsApp cron/Action Scheduler jobs are registered (a previously scheduled
  purge is cleared).
- `Tuki_Settings::sanitize()` ignores every submitted `wa_*` value and forces
  `wa_enabled = 0` — a hand-crafted POST cannot enable it.

**To test everything below, unlock it first** in `wp-config.php`:

```php
define( 'TUKI_WHATSAPP_ENABLED', true );
```

Then visit any admin page once so `maybe_upgrade()` creates the table.

### Locked-state checks (do these with the flag OFF)

- [ ] Channels tab renders the teaser; fields are blurred and cannot be clicked
      **or reached with Tab**.
- [ ] `curl -i https://<host>/wp-json/tukify/v1/whatsapp` → **404** (route absent).
- [ ] `SHOW TABLES LIKE '%tuki_wa_sessions%'` → **no rows**.
- [ ] `wp cron event list | grep tuki_purge_wa_sessions` → **nothing**.
- [ ] Hand-crafted enable attempt is refused — POST `tuki_settings[wa_enabled]=1`
      to `options.php` (with a valid nonce), then check:
      `wp option pluck tuki_settings wa_enabled` → still **0**.
- [ ] Saving the Settings page does **not** wipe unrelated settings (models,
      colours, KB, upsell all survive).
- [ ] Web chat, visual search, and the checkout bar all still work.

---

## 0. Prerequisites

- [ ] WooCommerce active, catalog indexed (Tukify → Dashboard → Reindex), web chat working.
- [ ] An AI provider key saved and "Test connection" green.
- [ ] A **spare phone number** for the bot.
      ⚠️ Registering a number to the Cloud API removes it from the normal
      WhatsApp / WhatsApp Business app. **Never use your personal or main
      business number.**
- [ ] A second phone (your own WhatsApp) to message the bot from.

## 1. Expose the local site (ngrok)

```bash
# Local site runs on e.g. http://tukify.local
ngrok http --host-header=rewrite tukify.local
# → Forwarding  https://<random>.ngrok-free.app -> tukify.local
```

- [ ] Open `https://<random>.ngrok-free.app/wp-admin` — the site loads over HTTPS.
- [ ] If WordPress redirects back to `tukify.local`, temporarily set in `wp-config.php`:
      ```php
      define( 'WP_HOME',    'https://<random>.ngrok-free.app' );
      define( 'WP_SITEURL', 'https://<random>.ngrok-free.app' );
      ```
- [ ] Tukify → Settings → **Channels**: the Webhook URL now shows the ngrok host.
      (It is built from `rest_url()`, so it follows WP_HOME.)
      Expected: `https://<random>.ngrok-free.app/wp-json/tukify/v1/whatsapp`

> The ngrok URL changes on every restart (free tier) → re-paste the webhook in
> Meta each time you restart the tunnel.

## 2. Meta setup

- [ ] business.facebook.com → Business account exists.
- [ ] developers.facebook.com → My Apps → Create App → **Business**.
- [ ] Add the **WhatsApp** product; link the Business account.
- [ ] WhatsApp → API Setup → register + verify the **spare** number.
- [ ] Copy **access token** and **Phone number ID** → paste into Channels → Save.
- [ ] App settings → Basic → copy **App secret** → paste into Channels → Save.
- [ ] Copy the **Verify token** from Channels (Copy button).

## 3. Webhook verification (GET handshake)

- [ ] Meta → WhatsApp → Configuration → Webhook → Edit.
- [ ] Callback URL = the Webhook URL from Channels; Verify token = the one from Channels.
- [ ] Click **Verify and save**.
      - [ ] ✅ Meta accepts it (Tukify echoed `hub.challenge`).
      - [ ] ❌ If it fails: token mismatch, tunnel down, or the REST route is
            unreachable. Check with:
            ```bash
            curl "https://<host>/wp-json/tukify/v1/whatsapp?hub.mode=subscribe&hub.verify_token=<TOKEN>&hub.challenge=12345"
            # expect: 12345
            curl "https://<host>/wp-json/tukify/v1/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345"
            # expect: 403
            ```
- [ ] Configuration → **subscribe to the `messages` field**. (Without this, nothing arrives.)

## 4. Signature enforcement (security — do these)

- [ ] Unsigned POST is rejected:
      ```bash
      curl -i -X POST "https://<host>/wp-json/tukify/v1/whatsapp" \
        -H 'Content-Type: application/json' -d '{"entry":[]}'
      # expect: 403 (tuki_wa_unsigned)
      ```
- [ ] Bad signature is rejected:
      ```bash
      curl -i -X POST "https://<host>/wp-json/tukify/v1/whatsapp" \
        -H 'Content-Type: application/json' \
        -H 'X-Hub-Signature-256: sha256=deadbeef' -d '{"entry":[]}'
      # expect: 403 (tuki_wa_bad_signature)
      ```
- [ ] Valid signature is accepted (sign the exact body with the app secret):
      ```bash
      BODY='{"entry":[]}'
      SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "<APP_SECRET>" | awk '{print $2}')
      curl -i -X POST "https://<host>/wp-json/tukify/v1/whatsapp" \
        -H 'Content-Type: application/json' \
        -H "X-Hub-Signature-256: sha256=$SIG" -d "$BODY"
      # expect: 200 {"received":true}
      ```

## 5. Enable + test send

- [ ] Channels → toggle **Enable the WhatsApp channel** → Save.
- [ ] Enter your own number (country code, digits only, no `+`) → **Send test**.
      - [ ] ✅ "Sent — check WhatsApp on that number" and the message arrives.
      - [ ] ❌ Meta rejects sends to numbers that haven't messaged you in 24h unless
            they're added as test recipients in API Setup. Add yours there first.

## 6. Inbound conversation (the real thing)

From your own WhatsApp, message the bot number:

- [ ] **Text product search** — "do you have anything for winter?"
      - [ ] A reply arrives within a few seconds.
      - [ ] 1 match → image + bold name + price · stock + "View:" link + "Add to cart:" link.
      - [ ] 2+ matches → text reply, then a tappable **list**; tapping a row narrows to that product.
- [ ] **Add-to-cart link** — tap it → lands on the site cart with the product added.
      - [ ] Tamper with the URL (`tuki_atc=999`) → link does nothing (bad token).
- [ ] **Photo** — send a photo of a product → visual search replies with matches.
- [ ] **Unsupported type** — send a voice note / sticker →
      "I can read text and photos…".
- [ ] **Follow-up context** — "how much is the second one?" → answers about the
      right product (session history is working).
- [ ] **Language** — message in Bangla → replies in Bangla (same as web chat).
- [ ] **Human handoff** — "I want to talk to a human" →
      - [ ] Shopper gets "our team will get back to you shortly".
      - [ ] Handoff email arrives (Channels → notification email, else admin email)
            with the transcript and a `#reference` — **and no phone number**.

## 7. Reliability / abuse

- [ ] **Dedupe** — replay the same signed webhook body twice → only **one** reply
      (message id de-duplicated for 10 min).
- [ ] **Rate limit** — send >20 messages within 5 minutes from one number →
      further messages are dropped (no reply, no provider spend).
- [ ] **Provider failure** — temporarily break the AI key → messaging the bot gives
      "Sorry — I'm having trouble right now", never a stack trace.
- [ ] **Fast ack** — webhook returns 200 immediately; the reply arrives after the
      async job runs (Action Scheduler). Check WooCommerce → Status → Scheduled
      Actions for the `tuki_wa_process` group `tukify-whatsapp`.
      - [ ] If replies never arrive, Action Scheduler isn't running — check that
            WooCommerce is active and cron isn't disabled.

## 8. Privacy / data

- [ ] `wp_tuki_wa_sessions` has rows, `phone_hash` is a 64-char hash, and there is
      **no column containing the raw number**.
      ```sql
      SELECT id, phone_hash, display_name, updated_at FROM wp_tuki_wa_sessions;
      ```
- [ ] `conversation_context` stays bounded (≤10 turns) after a long chat.
- [ ] Channels → **Recent WhatsApp conversations** shows the transcript with a
      `#reference` only.
- [ ] Set "Keep conversations for" to 1 day, run the purge, confirm old rows go:
      ```bash
      wp cron event run tuki_purge_wa_sessions
      ```

## 9. Web chat regression (the refactor must not change it)

The web widget now goes through the same adapter — re-verify:

- [ ] Web chat replies normally; product cards render.
- [ ] Clarifying questions + quick replies still work.
- [ ] Visual search (image upload) still works.
- [ ] Compare / filters / browse-all / policy answers unchanged.
- [ ] Order status + size advisor forms still appear.
- [ ] Checkout bar still appears at the bottom when the cart has items.

## 10. Off switch

- [ ] Disable the channel → inbound messages get no reply; the webhook still 200s.
- [ ] Clear the app secret → inbound POSTs 403 (fails closed).

---

## Notes / known limits (v1)

- **Reply-only.** Tukify never initiates a WhatsApp conversation, so Meta's 24-hour
  customer-service window is respected by construction. No templates/campaigns.
- **No in-WhatsApp checkout.** Add-to-cart hands off to the site (by design).
- **Image links must be publicly reachable.** Product images behind a
  local-only host won't render in WhatsApp; the code falls back to sending the
  caption as text. Over ngrok they work.
