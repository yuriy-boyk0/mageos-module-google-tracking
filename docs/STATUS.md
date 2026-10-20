# Implementation status

Single source of truth for progress. **Update this file in every session.** Last updated: 2026-10-20.

Legend: ✅ done and verified in cloud (lint/XML/JSON/unit tests) · 🧪 needs local test (Magento 2.4.9 + Hyvä + Hyvä Checkout, see `local-testing.md`) · 👤 needs user (human) testing/decision · ⏳ not started · ❓ blocked on an answer

How "verified" is used: cloud verification means PHP syntax, XML well-formedness, JSON validity, and the Magento-free unit/JS tests pass (`dev/check.sh`). It does **not** mean the Magento wiring works.

## Summary by phase

| Phase | State |
|---|---|
| 1 Client-side purchase fix | ✅ code + tests · 🧪 verify on real success page |
| 1b Hyvä Checkout `add_shipping_info` / `add_payment_info` | ✅ implemented from Hyvä Checkout 1.3.15 source + JS tests · 🧪 verify in a real checkout |
| 0 Measurement Protocol foundation | ✅ core + tests + checked against Magento 2.4.9 source · 🧪 Magento wiring |
| 2 Server-side purchase event | ✅ core + tests · 🧪 observer/snapshot/config · only the fixed-conversion-rate option ⏳ |
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
Pure core (✅ 102 PHPUnit tests): `Service/MeasurementProtocol/` `PayloadBuilder`, `Client`, `QueueProcessor`, `RetryPolicy`, `ConsentEvaluator`, `GaCookieParser`, `ExclusionPolicy`, `ConfigIdValidator`, `TotalsResolver`, `ItemIdResolver`.

Magento layer (written, 🧪 untested against Magento):
- `etc/db_schema.xml` (+ hand-written whitelist) table `mageos_gtm_server_event`, FK to `sales_order` (cascade)
- `Observer/EnqueueServerSidePurchase` on `checkout_submit_all_after` (regular + multishipping + admin orders)
- `Cron/SendPurchases` (`etc/crontab.xml`, every minute), `Model/DbQueueStorage`, `Model/CurlTransport`, `Model/Log`
- `Model/Config`, `etc/adminhtml/system.xml` group "Server-side Purchase Tracking", `etc/config.xml` defaults, `etc/di.xml`
- `Model/OrderSnapshotFactory` (items, up to 5 category levels, variant from selected options, parent/child folding)
- `Model/RequestContextProvider` (consent + `_ga` / `_ga_<ID>` cookies)
- Config ID validation: GTM `GTM-…` and GA4 `G-…` formats rejected on save if swapped (`Model/Config/Backend/*`)
- Config: enable, Measurement ID (falls back to General), encrypted API secret, event name, currency basis, **tax in totals (including/excluding)**, **item ID for configurable products (parent/child SKU)**, unknown-consent action, include admin orders, exclude zero value / statuses / payment methods, max attempts, debug (validation endpoint).
- ✅ Events older than Google accepts (72 h): the row becomes status `expired` (message "unrecoverable: …"), is never sent again, and stays in the table with its payload for admin review. The cron stops one hour early (71 h) and also expires a row whose next retry would land outside the window.
- Tax setting: *including* = `value` is the order grand total (same as the client-side event), prices and shipping with tax; *excluding* = grand total minus tax, prices and shipping without tax; `tax` is always sent separately.
- SKU setting: for configurable products `item_id` is the configurable's own SKU (parent, default) or the selected variant's SKU (child); other product types always use their own SKU. `item_variant` ("Black, XS") is sent either way.

### Verified against the real Magento 2.4.9 source (✅, cloud)
The tag `2.4.9` of github.com/magento/magento2 is cloned at `/home/user/magento/magento2` (read-only, via the session's git proxy; re-clone with the command in `dev/check-magento.sh` in a new session). `dev/check-magento.sh` runs PHPStan against it and validates every XML file against Magento's XSDs. This is static checking only; it is not a substitute for local testing. It found and fixed:
- `AdapterInterface::insertIgnore()` does not exist (would have crashed order placement handling): now `insert()` + `DuplicateException`.
- `ProductInterface::getCategoryIds()` is not on the interface: guarded with `instanceof Product`.
- `checkout_submit_all_after` is dispatched by `QuoteManagement::placeOrder()` (not `submit()`), by admin order creation, PayPal Express and multishipping (`orders` key); the observer handles `order` and `orders`.
- Magento stores a configurable's order row with the *variant* SKU (`Configurable::getSku`), so the parent SKU must come from the catalog product; handled in `ItemIdResolver` / `OrderSnapshotFactory`.
- Pre-existing: `Observer/AddToWishlist.php` called `array_last()` (PHP 8.5 only; Magento 2.4.9 supports 8.3/8.4) and could use an undefined `$item`; fixed. `composer.json` now requires PHP `~8.3.0||~8.4.0||~8.5.0` like Magento 2.4.9.
- ✅ Fixed on the user's instruction: the admin tab is created by this module as id `mage_os` (valid for Magento 2.4.9's XSD, which rejects hyphens in tab ids) with the label **Mage-OS**. Other modules that should share the tab must use the same id `mage_os`; a module declaring a different id creates a separate tab. 🧪 confirm the menu entry appears as Stores > Configuration > Mage-OS > Google Tag Manager, and check `MageOS_CookieConsent` for its tab id.
- Other PHPStan notes are pre-existing magic-getter noise (`$observer->getEvent()->getProduct()` etc.) or classes not available without Composer (`Psr\Log`).

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
11. Hyvä Checkout shipping/payment events (`local-testing.md` section 5): once per submission, right method codes, default/mobile/onepage checkouts, virtual cart.

## Needs user testing (👤)
- Docs acceptance: someone who never used GA4/GTM follows only `docs/google-setup.md` on the clean install and reaches a working state.
- Reporting decision in practice: check that `purchase_server` vs `purchase` counts for the same `transaction_id` are useful in a GA4 Exploration.
- Legal/privacy sign-off on the "send anonymously when consent is unknown" default (see Decisions).

## Not started (⏳)
- Phase 3: refund (`sales_order_creditmemo_save_after`), cancellation, admin mass action "re-send server-side purchase".
- Phase 4: order grid column with delivery status, reconciliation report, `debug_mode` event param so server events show in DebugView.
- **Future improvement, noted by the user:** an admin-configured *reporting currency* for purchase events (e.g. an EU+US store that wants everything tracked in EUR instead of EUR + USD). Today the order's currency (or the store base currency) is used. Needs a conversion step (Magento currency rates or a fixed rate) for value/tax/shipping/item prices/discounts, and a decision whether the client-side events follow the same rule. Replaces the earlier "fixed conversion rate" idea.
- Admin action to re-send an `expired` order (needs the `timestamp_micros` dropped or refreshed; Google would reject the original). Part of Phase 3 mass re-send.
- Cleanup of old `sent`/`skipped` rows from the queue table.

## Blocked / questions (❓)
1. ~~Hyvä Checkout events~~ resolved: the user provided the Hyvä zip; events documented in `docs/hyva-checkout-notes.md` and wired in `checkout-steps.phtml`. Remaining: local verification (🧪 item 11).
2. ~~`system.xml` tab id~~ resolved (tab `mage_os`, label Mage-OS).

## Decisions made
| Decision | Choice | Date |
|---|---|---|
| Queue mechanism | Own DB table + cron (no Magento MQ) | 2026-10-20 |
| Consent unknown (admin / webhook orders) | Send anonymously by default; configurable to skip. Declined visitors never sent | 2026-10-20 |
| Hyvä shipping/payment events | Base layer first; wired to the real Hyvä Checkout 1.3.15 events once the user supplied the source (push on `checkout:validation:success`) | 2026-10-20 |
| Currency | For now the currency of the purchase; admin-selectable reporting currency is a future improvement | 2026-10-20 |
| `purchase_server` semantics | Comparison-only signal, not revenue; document it | earlier |
| Unit tests | Written alongside code (earlier "defer tests" instruction superseded) | 2026-10-20 |
| Events older than 72 h | Keep in the table as `expired` (unrecoverable), skip in cron | 2026-10-20 |
| Tax in totals / item ID | Admin config: including or excluding tax; parent or child SKU for configurables | 2026-10-20 |
| Consent unknown | Confirmed again: send anonymously (default) | 2026-10-20 |

## Known risks / notes
- GA4 rejects Measurement Protocol events whose `timestamp_micros` is older than ~72 h. Such rows are now closed as `expired` (never sent). With the defaults (8 attempts, delays 1, 2, 4 … 64 min, about 2 h total) this only happens after cron was down for ~3 days.
- `QueueProcessor` in debug mode closes rows as `sent` although Google records nothing; do not leave debug on.
- Client-side `purchase` takes its currency from the store's *current* currency but the value from the order grand total (order currency); they can disagree on multi-currency stores. The server-side event uses the order's own currency/total pair.
- `head.phtml` calls `gtag('config', <G-ID>)` and the exported container has a GA4 Configuration tag: possible double `page_view` (🧪 check in DebugView).
- Other exported-container quirks not yet audited: tags with `WINDOW_LOADED` triggers for post-load events (`add_to_cart`, ...) may not fire reliably; items are keyed by SKU and nested one level deeper than GA4 expects (`Ga4-Ecommerce-Items` variable). Verify in GTM Preview.
