# Implementation status

Single source of truth for progress. **Update this file in every session.** Last updated: 2026-10-20.

Legend: ✅ done and verified in cloud (lint/XML/JSON/unit tests) · 🧪 needs local test (Magento 2.4.9 + Hyvä + Hyvä Checkout, see `local-testing.md`) · 👤 needs user (human) testing/decision · ⏳ not started · ❓ blocked on an answer

How "verified" is used: cloud verification means PHP syntax, XML well-formedness, JSON validity, and the Magento-free unit/JS tests pass (`dev/check.sh`). It does **not** mean the Magento wiring works.

## Summary by phase

| Phase | State |
|---|---|
| 1 Client-side purchase fix | ✅ code + tests · 🧪 verify on real success page |
| 1b Hyvä Checkout `add_shipping_info` / `add_payment_info` | base layer only · ❓ Hyvä event names unknown · 🧪 |
| 0 Measurement Protocol foundation | ✅ core + tests · 🧪 Magento wiring |
| 2 Server-side purchase event | ✅ core + tests · 🧪 observer/snapshot/config · some options ⏳ |
| 3 Refunds / cancellations / mass re-send | ⏳ not started |
| 4 Admin visibility (grid column, reconciliation report) | ⏳ not started (debug mode is done as part of Phase 0) |
| 5 Documentation | ✅ README, `google-setup.md`, `local-testing.md`, this file · 👤 docs acceptance (see below) |

## Done

### Phase 1: client-side purchase
- ✅ dataLayer event renamed `checkout_success` → `purchase` (`Service/CartService.php`).
- ✅ Reload/back-navigation dedup in `checkout-purchase.phtml` (localStorage, last 20 orders; no push when there is no order). Node tests in `Test/js/`.
- ✅ Exported GTM `Purchase` trigger now fires on `ga4_event = purchase` (was: success URL, which would send an empty hit on a deduped reload).
- ✅ Exported `Add Shipping Info` / `Add Payment Info` triggers fixed (they fired on the success URL). The tags are now idle until the events are pushed (Phase 1b).
- ✅ Leftover GTM `accountId` / `containerId` removed from `etc/*.json`.
- 🧪 Check on a real order: one `purchase` per order, none on reload, GTM import accepts the JSON without those IDs.

### Phase 0 + 2: server-side `purchase_server`
Pure core (✅ 87 PHPUnit tests): `Service/MeasurementProtocol/` `PayloadBuilder`, `Client`, `QueueProcessor`, `RetryPolicy`, `ConsentEvaluator`, `GaCookieParser`, `ExclusionPolicy`, `ConfigIdValidator`.

Magento layer (written, 🧪 untested against Magento):
- `etc/db_schema.xml` (+ hand-written whitelist) table `mageos_gtm_server_event`, FK to `sales_order` (cascade)
- `Observer/EnqueueServerSidePurchase` on `checkout_submit_all_after` (regular + multishipping + admin orders)
- `Cron/SendPurchases` (`etc/crontab.xml`, every minute), `Model/DbQueueStorage`, `Model/CurlTransport`, `Model/Log`
- `Model/Config`, `etc/adminhtml/system.xml` group "Server-side Purchase Tracking", `etc/config.xml` defaults, `etc/di.xml`
- `Model/OrderSnapshotFactory` (items, up to 5 category levels, variant from selected options, parent/child folding)
- `Model/RequestContextProvider` (consent + `_ga` / `_ga_<ID>` cookies)
- Config ID validation: GTM `GTM-…` and GA4 `G-…` formats rejected on save if swapped (`Model/Config/Backend/*`)
- Config: enable, Measurement ID (falls back to General), encrypted API secret, event name, currency basis, unknown-consent action, include admin orders, exclude zero value / statuses / payment methods, max attempts, debug (validation endpoint).

### Phase 5: docs
- ✅ `README.md`, `docs/google-setup.md`, `docs/local-testing.md`, `CLAUDE.md`.
- ⏳ Update `google-setup.md` / README screenshots-free walkthrough for the server-side group once tested locally (field names may change).

## Needs local testing (🧪)
Everything under "Magento layer" above, in particular the risky items:
1. `setup:upgrade` creates the table; the hand-written `db_schema_whitelist.json` is accepted (else regenerate it); FK to `sales_order` OK.
2. `setup:di:compile` passes (constructor types, nullable defaults).
3. Admin config renders; saving a swapped ID shows the validation error; API secret is stored encrypted.
4. Observer fires for: guest order, logged-in order, admin-created order, multishipping, order placed via payment redirect/callback. Check `$order->getEntityId()` is set at `checkout_submit_all_after`.
5. `OrderSnapshotFactory`: values/currency match the client-side `purchase` for the same order; configurable products show one item with `item_variant`; categories fill `item_category..5`; discount per unit.
6. Consent cookie (`user_allowed_save_cookie`): confirm what `MageOS_CookieConsent` writes, whether it reflects the *tracking* category, and the real session cookie name (assumed `PHPSESSID`). `ConsentEvaluator` assumes a JSON map `websiteId → 1` (mirrors `head.phtml`).
7. `_ga` / `_ga_<ID>` parsing against real cookies (both `GS1` and `GS2` formats), and that the server event joins the browser session in GA4.
8. Cron picks up pending rows; retry/backoff against a blocked network; permanent-error path with a wrong API secret.
9. Debug mode: validation messages are logged to `var/log`.
10. `purchase_server` visible in GA4 Realtime / DebugView with correct value, currency, items.
11. Hyvä Checkout shipping/payment events (see Phase 1b).

## Needs user testing (👤)
- Docs acceptance: someone who never used GA4/GTM follows only `docs/google-setup.md` on the clean install and reaches a working state.
- Reporting decision in practice: check that `purchase_server` vs `purchase` counts for the same `transaction_id` are useful in a GA4 Exploration.
- Legal/privacy sign-off on the "send anonymously when consent is unknown" default (see Decisions).

## Not started (⏳)
- Phase 3: refund (`sales_order_creditmemo_save_after`), cancellation, admin mass action "re-send server-side purchase".
- Phase 4: order grid column with delivery status, reconciliation report, `debug_mode` event param so server events show in DebugView.
- Phase 2 options not yet built: inclusive/exclusive tax totals toggle, fixed currency conversion rate override, parent vs child SKU toggle (currently parent row, children folded in).
- Cleanup of old `sent`/`skipped` rows from the queue table.

## Blocked / questions (❓)
1. **Hyvä Checkout `add_shipping_info` / `add_payment_info`**: the exact Hyvä Checkout events for "shipping method submitted" and "payment method submitted" are unknown (docs.hyva.io unreachable from cloud). Base layer is in `view/frontend/templates/gtm/checkout-steps.phtml` (`window.mageosGtmCheckout.push(...)`, listeners disabled). To finish: run the discovery steps in `local-testing.md`, fill `HYVA_EVENTS` / `EXTRACTORS`, add a local test.

## Decisions made
| Decision | Choice | Date |
|---|---|---|
| Queue mechanism | Own DB table + cron (no Magento MQ) | 2026-10-20 |
| Consent unknown (admin / webhook orders) | Send anonymously by default; configurable to skip. Declined visitors never sent | 2026-10-20 |
| Hyvä shipping/payment events | Base layer + disabled stub now; real wiring after local verification | 2026-10-20 |
| `purchase_server` semantics | Comparison-only signal, not revenue; document it | earlier |
| Unit tests | Written alongside code (earlier "defer tests" instruction superseded) | 2026-10-20 |

## Known risks / notes
- GA4 rejects Measurement Protocol events whose `timestamp_micros` is older than ~72 h; an order that keeps failing past that will be rejected even after the queue recovers. Keep max attempts × backoff well inside 72 h (defaults: 8 attempts, about 4 h).
- `QueueProcessor` in debug mode closes rows as `sent` although Google records nothing; do not leave debug on.
- Client-side `purchase` takes its currency from the store's *current* currency but the value from the order grand total (order currency); they can disagree on multi-currency stores. The server-side event uses the order's own currency/total pair.
- `head.phtml` calls `gtag('config', <G-ID>)` and the exported container has a GA4 Configuration tag: possible double `page_view` (🧪 check in DebugView).
- Other exported-container quirks not yet audited: tags with `WINDOW_LOADED` triggers for post-load events (`add_to_cart`, ...) may not fire reliably; items are keyed by SKU and nested one level deeper than GA4 expects (`Ga4-Ecommerce-Items` variable). Verify in GTM Preview.
