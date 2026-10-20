# Implementation status

Single source of truth for progress. **Update this file in every session.** Last updated: 2026-10-20 (two local runs on Magento 2.4.9, see "Verified locally").

Legend: ✅ done and verified in cloud (lint/XML/JSON/unit tests) · 🧪 needs local test (Magento 2.4.9 + Hyvä + Hyvä Checkout, see `local-testing.md`) · 👤 needs user (human) testing/decision · ⏳ not started · ❓ blocked on an answer · 🟢 verified locally on a real install (date and environment in "Verified locally")

How "verified" is used: cloud verification means PHP syntax, XML well-formedness, JSON validity, and the Magento-free unit/JS tests pass (`dev/check.sh`). It does **not** mean the Magento wiring works.

## Summary by phase

| Phase | State |
|---|---|
| 1 Client-side purchase fix | ✅ code + tests · 🧪 verify on real success page |
| 1b Hyvä Checkout `add_shipping_info` / `add_payment_info` | ✅ implemented from Hyvä Checkout 1.3.15 source + JS tests · 🟢 default checkout, guest · 🧪 mobile / one-page / virtual |
| 0 Measurement Protocol foundation | ✅ core + tests + checked against Magento 2.4.9 source · 🧪 Magento wiring |
| 2 Server-side purchase event | ✅ core + tests · 🟢 guest order end to end (after the `curl_close()` fix) · 🧪 other order types, consent, retries · reporting-currency option is a future improvement ⏳ |
| 3 Refunds / cancellations / mass re-send | ✅ core + tests + static checks · 🧪 Magento wiring |
| 4 Admin visibility (grid column, event log, DebugView flag, retention) | ✅ core + tests + static checks · 🟢 order grid column · 🧪 new event log grid (replaced the summary report), filters, ACL, cleanup, DebugView |
| Client-side fixes (former "known issues") | ✅ code + XSD/PHPStan · 🧪 GTM Preview / DebugView; containers must be re-imported |
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
Pure core (✅ 123 PHPUnit tests in total, incl. Phase 3/4): `Service/MeasurementProtocol/` `PayloadBuilder`, `Client`, `QueueProcessor`, `RetryPolicy`, `ConsentEvaluator`, `GaCookieParser`, `ExclusionPolicy`, `ConfigIdValidator`, `TotalsResolver`, `ItemIdResolver`.

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

### Cookie consent without a consent module (2026-10-20)
- ✅ Dropped the `mage-os/module-cookie-consent` dependency (Composer and `module.xml` sequence): the package does not exist yet.
- ✅ New config group *Cookie Consent* (`googletagmanager/consent/*`): Require Consent (default Yes), Consent Cookie Name (default `user_allowed_save_cookie`, Magento/Hyvä banner), Cookie Value Meaning "Accepted" (optional substring, e.g. Cookiebot `statistics:true`; empty = JSON website map or plain truthy flag), Consent Event Names (fallback, default `user-allowed-save-cookie` = Hyvä banner's accept event).
- ✅ `head.phtml` rewritten: loads GTM when the cookie is granted, or on a configured event unless the cookie says declined, or always when consent is not required; no `hyva.getCookie` dependency. 9 JS tests. GTM `<noscript>` iframe only without a consent requirement.
- ✅ Server side: `ConsentEvaluator` uses the same configurable rules (16 tests); event-only setup (no cookie name) = consent unknown, *Require Consent* = No = granted. `RequestContextProvider` reads the configured cookie.
- 🧪 Local: see item 6 below.

### Client-side fixes (former "known issues", 2026-10-20)
- ✅ `items` is a flat GA4 list (was keyed by SKU and nested), one entry per visible cart/order row, row price, child rows skipped. Purchase quantity now uses `getQtyOrdered()` (the old `getQty()` does not exist on order items, so quantity was 0). Checkout blocks read `getAllVisibleItems()`.
- ✅ Client-side `purchase` currency = order currency (was the store's current display currency).
- ✅ Double `page_view`: removed `gtag('config')` from `head.phtml`.
- ✅ Exported triggers: every GA4 event trigger is a *Custom Event* on `{{_event}}` (was *Window Loaded* + `ga4_event`, which misses events pushed after load); GA4 Configuration fires on *All Pages*. 👤 Existing GTM containers must be re-imported (documented).
- ✅ New `view_item` (product page), `view_item_list` (category + search, max 50 items of the current page), `search` (`Block/CatalogTagManager`, `Service/CatalogService`, `gtm/catalog-events.phtml`, layouts `catalog_product_view`, `catalog_category_view`, `catalogsearch_result_index`). Rendered into FPC-cached HTML (catalog data only). The block renders after the product list (`after="-"`) so it reads the already loaded, paginated collection.
- ✅ Exported GTM names consistently use the uppercase `GA4` prefix (tags `GA4 Removefromcart` / `GA4 Updatecart` / `GA4 Viewcart` and all `GA4-…` variables were `Ga4`); all `{{…}}` references updated, checked that every reference resolves. 🧪 re-import over an older container may leave the old `Ga4…` items (documented in `google-setup.md`).
- ✅ Export controllers now require the `MageOS_GoogleTagManager::configuration` ACL resource (any admin could call them before).

### Phase 3: refunds, cancellations, re-send (2026-10-20)
- ✅ Pure + tests: `FollowUpPayloadBuilder` (reuses client_id/user_id of the purchase, no timestamp), `FollowUpPolicy` (only when the purchase is pending/processing/sent), `ResendPolicy` (create / reset failed+expired / ignore sent, queued, skipped; drops `timestamp_micros` on reset).
- 🧪 Magento: `Observer/EnqueueServerSideRefund` (`sales_order_creditmemo_save_after`, one row per credit memo via new column `reference_id` in the unique key), `Observer/EnqueueServerSideCancellation` (`order_cancel_after`), `Model/FollowUpEnqueuer`, `Model/PurchaseEnqueuer` (observer logic moved here so the re-send backfill reuses it), `Model/PurchaseResender`, mass action `gtm/serverside/massResend` + ACL `MageOS_GoogleTagManager::server_side_resend`.
- Config: Track Refunds / Refund Event Name (`refund_server`), Track Cancellations / Cancellation Event Name (`cancel_server`), default on.

### Phase 4: admin visibility (2026-10-20)
- ✅ Pure + tests: `debug_mode` added at send time when *Show in GA4 DebugView* is on (`QueueProcessor`).
- 🧪 Magento: order grid column *GA4 Server-side Purchase* (`ui_component/sales_order_grid.xml`, `Plugin/OrderGridDeliveryStatus` on `UiComponent\DataProvider\CollectionFactory::getReport`, derived-table join), event log Reports > Marketing > *GA4 Server-side Events* (`menu.xml`, ACL `MageOS_GoogleTagManager::report`, `Controller/Adminhtml/Report/Index`, UI listing `mageos_gtm_server_event_listing` with `Model/ResourceModel/ServerEvent/Grid/Collection` and `Ui/Component/Listing/Column/OrderLink`; replaced the per-day summary on user request, 2026-10-20: columns order date, order #, event, status, error/reason; intro text spaced away from the grid), daily cleanup cron `mageos_gtm_cleanup` (`Cron/Cleanup`, *Keep Sent/Skipped Rows*, default 90 days).
- Schema: new column `reference_id`, unique key now (order_id, event_type, reference_id), index on `created_at`; whitelist updated by hand (keeps the old unique key name so it is dropped on upgrade).

### Verified against the real Magento 2.4.9 source (✅, cloud)
The tag `2.4.9` of github.com/magento/magento2 is cloned at `/home/user/magento/magento2` (read-only, via the session's git proxy; re-clone with the command in `dev/check-magento.sh` in a new session). `dev/check-magento.sh` runs PHPStan against it and validates every XML file against Magento's XSDs. This is static checking only; it is not a substitute for local testing. It found and fixed:
- `AdapterInterface::insertIgnore()` does not exist (would have crashed order placement handling): now `insert()` + `DuplicateException`.
- `ProductInterface::getCategoryIds()` is not on the interface: guarded with `instanceof Product`.
- `checkout_submit_all_after` is dispatched by `QuoteManagement::placeOrder()` (not `submit()`), by admin order creation, PayPal Express and multishipping (`orders` key); the observer handles `order` and `orders`.
- Magento stores a configurable's order row with the *variant* SKU (`Configurable::getSku`), so the parent SKU must come from the catalog product; handled in `ItemIdResolver` / `OrderSnapshotFactory`.
- Pre-existing: `Observer/AddToWishlist.php` called `array_last()` (PHP 8.5 only; Magento 2.4.9 supports 8.3/8.4) and could use an undefined `$item`; fixed. `composer.json` now requires PHP `~8.3.0||~8.4.0||~8.5.0` like Magento 2.4.9.
- ✅ Fixed on the user's instruction: the admin tab is created by this module as id `mage_os` (valid for Magento 2.4.9's XSD, which rejects hyphens in tab ids) with the label **Mage-OS**. Other modules that should share the tab must use the same id `mage_os`; a module declaring a different id creates a separate tab. 🧪 confirm the menu entry appears as Stores > Configuration > Mage-OS > Google Tag Manager (🟢 seen locally under one Mage-OS tab).
- Other PHPStan notes are pre-existing magic-getter noise (`$observer->getEvent()->getProduct()` etc.) or classes not available without Composer (`Psr\Log`).

### Phase 5: docs
- ✅ `README.md`, `docs/google-setup.md`, `docs/local-testing.md`, `CLAUDE.md`.
- ⏳ Update `google-setup.md` / README screenshots-free walkthrough for the server-side group once tested locally (field names may change).

### Verified locally (🟢, 2026-10-20)
Environment: Magento Open Source 2.4.9, Hyvä default theme 1.5.3, Hyvä Checkout 1.3.15, PHP 8.5.9, MySQL 8.4, OpenSearch 3.6, developer mode, sample data, Warden on WSL2. Module installed from the `claude/funny-feynman-84buem` branch through a Composer path repository. **Not the real `MageOS_CookieConsent`**: it could not be found on Packagist or GitHub, so a throw-away stub was used (cookie `user_allowed_save_cookie` = `{"<websiteId>":1|0}` and window event `cookie-consent-tracking-approved`, i.e. exactly what this module reads).

| Area | Result |
|---|---|
| `composer require` (path repo), `module:enable`, `setup:upgrade`, `setup:di:compile` | 🟢 no errors; `liquid/liquid` 1.4.45 pulled in |
| Table `mageos_gtm_server_event` | 🟢 created with unique key, status/created indexes and the FK to `sales_order` (`ON DELETE CASCADE`); hand-written whitelist accepted |
| Admin config | 🟢 section renders under one **Mage-OS** tab; `G-…` in the GTM field and `GTM-…` in the GA4 field are both rejected with a clear message; API secret is stored encrypted (`0:3:…`) |
| Consent gate | 🟢 before consent: no GTM script and empty `dataLayer`; after Accept: `gtm.js?id=GTM-…` is loaded |
| Client events (guest, default Hyvä Checkout) | 🟢 `view_item_list` (category), `view_item`, `add_to_cart`, `view_cart`, `begin_checkout` (value 117, 3 items), `add_shipping_info` once on "Proceed to Review & Payments" (`shipping_tier` = `tablerate_bestway`), `add_payment_info` once on Place Order, `purchase` once on the success page (value 122 = 117 + 5 shipping, order currency, 3 items). Selecting a shipping or payment method alone pushes nothing |
| Server-side purchase | 🟢 one `pending` row per order with `client_id`/`session_id` taken from the `_ga` cookies; payload value, shipping, tax and items match the client-side event; cron moved it to `sent` (after the `curl_close()` fix below) |
| Order grid / report | 🟢 column shows "Sent" for the new order, blank for the two old ones, no SQL error; report numbers match (1 order sent, 2 not tracked) |

Not verified yet (still 🧪): a person confirming `purchase_server` in GA4 Realtime/DebugView and the client events in GTM Preview/DebugView; logged-in, admin-created, multishipping and redirect-payment orders; consent declined; refunds, cancellations, mass re-send, ACL; network blocked / wrong secret / debug mode / expiry; configurable products and coupons; tax and SKU options; mobile and one-page checkout, virtual cart; FPC/Varnish; search page; the new configurable consent settings (item 6; the stub used in this run is no longer needed).

Bugs found by this run (both fixed, with a test where possible):
1. **`add_to_cart` delivered twice** (once on the page, again on the next page load). Hyvä's `private-content.phtml` dispatches `private-content-loaded` with the section-data object and then writes that same object to localStorage, which undid `clearDatalayerSection()` in `body.phtml`. Fix: also empty `event.detail.data.gtm.datalayer` in the handler (Hyvä does the same for `messages`). Test: `Test/js/phtml-scripts.test.js` ("body: …").
2. **Every server-side send failed on PHP 8.5.** `curl_close()` is deprecated in 8.5 and Magento's developer-mode error handler throws on deprecations, so the row stayed `pending` ("Network error: Deprecated Functionality…"). Fix: removed the call (it has done nothing since PHP 8.0). Not unit-testable (real cURL).

Findings that are not module bugs:
- The Hyvä Theme module has its own **Google Tag Manager** config section (`hyva_theme_google`) and the Hyvä default theme overrides `Magento_GoogleTagManager`/`Magento_GoogleGtag`. Keep them disabled, otherwise GTM can be loaded twice.
- Hyvä Checkout is not active after installing it: the default of `hyva_themes_checkout/general/checkout` is the Luma fallback and `/checkout/` says "No Checkout module installed". Set it to `default`.
- An ad blocker in the browser (uBlock-style `google-analytics_analytics.js` redirect) neutered GTM: `gtm.js` returned 200 but `google_tag_manager` stayed empty. Disable it for the test site.
- `php -l` segfaults (exit 139) in the Warden `php-fpm` PHP 8.5.9 container with the default ini (works with `php -n`), so `dev/check.sh` cannot run there; PHPUnit and the JS tests run fine (123 and 18 tests pass).

### Verified locally, run 2 (🟢, 2026-10-20, after the cookie-consent dependency was dropped)
Same environment as run 1, module updated to `c94fbcd`. The consent stub was removed (`composer remove`, `setup:upgrade`, `setup:di:compile`: clean, `MageOS_CookieConsent` gone from `config.php`). Module tests from a separate copy: 138 PHPUnit tests and 27 JS tests pass.

| Area | Result |
|---|---|
| Update without the dependency | 🟢 `composer remove` of the stub also refreshed the module (its `composer.json` now requires only `liquid/liquid`), `setup:upgrade`/`di:compile` clean |
| Admin: Cookie Consent group | 🟢 renders with the documented defaults (Require Consent Yes, `user_allowed_save_cookie`, empty accepted value, `user-allowed-save-cookie`) |
| Consent gate, console cookie | 🟢 no cookie: no GTM, empty `dataLayer`; fallback event: GTM loads once; `{"1":0}`: no GTM, also not after the event; `{"1":1}`: GTM loads on every page |
| Consent gate, Hyvä built-in banner | 🟢 with Cookie Restriction Mode on, the banner shows, GTM is not loaded before "Allow Cookies", loads right after it and on the next page |
| Orders and server side | 🟢 guest order with consent accepted: `sent`, `client_id` from `_ga`; guest order with `{"1":0}`: `skipped` / "tracking consent not given", no `client_id`; the `add_to_cart` fix still holds (no replay on the cart page) |
| Credit memo / cancellation | 🟢 after the fix below: partial refund queued and sent; cancel of an order with a skipped purchase → `skipped` |
| Mass re-send | 🟢 see item 14 |
| Debug mode | 🟢 see item 9 |
| Event log grid | 🟢 see item 15 |

Bugs found by this run (both fixed, committed on the branch):
1. **Credit memo never queued a `refund_server` event.** `TotalsResolver::resolveItemPrice()` took `?float $priceInclTax` under `strict_types`, but credit memo items (and orders loaded from the DB for an admin re-send) return decimals as strings, so it threw a `TypeError` that `FollowUpEnqueuer` logged as "could not queue refund". Fix: accept `float|string|null` and cast inside the pure resolver; unit test added. Order placement was not affected (items hold floats there), so the purchase path never showed it.
2. **Order # in the event log grid was plain text instead of a link.** `OrderLink::prepareDataSource()` iterated `$dataSource['data']['items'] ?? []` by reference, which loops over a temporary copy. Fix: iterate the real array.

Not run yet in run 2: *Require Consent* = No, event-only setup, custom cookie name and accepted value, logged-in / admin-created / multishipping / redirect orders, configurable products and coupons, tax and SKU options, network blocked / wrong secret / expiry, mobile and one-page checkout, virtual cart, FPC/Varnish, search page, ACL, cleanup cron, GA4 Realtime/DebugView and GTM Preview checks by a person.

## Needs local testing (🧪)
Everything under "Magento layer" above, in particular the risky items:
1. `setup:upgrade` creates the table; the hand-written `db_schema_whitelist.json` is accepted (else regenerate it); FK to `sales_order` OK. 🟢 (2026-10-20)
2. `setup:di:compile` passes (constructor types, nullable defaults). 🟢 (2026-10-20)
3. Admin config renders; saving a swapped ID shows the validation error; API secret is stored encrypted. 🟢 (2026-10-20)
4. Observer fires for: guest order, logged-in order, admin-created order, multishipping, order placed via payment redirect/callback. Check `$order->getEntityId()` is set at `checkout_submit_all_after`. 🟢 guest order only (observer fires, order id set); logged-in, admin, multishipping and redirect payments still open
5. `OrderSnapshotFactory`: values/currency match the client-side `purchase` for the same order; configurable products show one item with `item_variant`; categories fill `item_category..5`; discount per unit. 🟢 guest order, simple products only; configurable/coupon/discount still open
6. Configurable consent (2026-10-20, replaces the `MageOS_CookieConsent` dependency): defaults with the console cookie / Hyvä built-in banner, decline, no cookie, fallback event, custom cookie name, "accepted" value (Cookiebot style), *Require Consent* = No, event-only setup on the server side; session cookie name `PHPSESSID`. Browser gate and server must agree (`local-testing.md`, "Simulating cookie consent") 🟢 (run 2) tested without any consent module: defaults with a console cookie, decline, no cookie, fallback event (also after a decline: GTM stays off), and Hyvä's built-in banner (Cookie Restriction Mode on, "Allow Cookies" loads GTM on that page via the event and on the next page via the cookie; server rows use the same rules: accepted = real `_ga` client id, declined = `skipped`). Still open: *Require Consent* = No, event-only setup (empty cookie name), custom cookie name, `statistics:true` style accepted value, no cookie at all on a real order
7. `_ga` / `_ga_<ID>` parsing against real cookies (both `GS1` and `GS2` formats), and that the server event joins the browser session in GA4. 🟡 `client_id` and `session_id` were taken from the real `_ga` / `_ga_<ID>` cookies of a guest order; which GS format Google sent and joining the browser session in GA4 are still to check
8. Cron picks up pending rows; retry/backoff against a blocked network; permanent-error path with a wrong API secret. 🟢 cron sends once the `curl_close()` bug was fixed; retry/backoff with a blocked network and the wrong-secret path still open
9. Debug mode: validation messages are logged to `var/log`. 🟢 (run 2) log shows "Payload valid (debug endpoint, event not recorded)"; no GA4 check needed
10. `purchase_server` visible in GA4 Realtime / DebugView with correct value, currency, items. 🟢 queue side (row reaches `sent`); GA4 Realtime/DebugView check still needs a person
11. Hyvä Checkout shipping/payment events (`local-testing.md` section 5): once per submission, right method codes, default/mobile/onepage checkouts, virtual cart. 🟢 default checkout, guest, one order (both events fire once, right method codes); mobile/one-page/virtual cart still open
12. Client-side fixes: flat `items`, purchase currency, single `page_view`, custom-event triggers after re-importing the container, `view_item` / `view_item_list` / `search` on product, category and search pages (also with FPC/Varnish on, second visitor gets the same events). 🟡 partly: `view_item_list`, `view_item`, `view_cart`, `begin_checkout` seen in `dataLayer`; GTM Preview, search page, FPC/Varnish still open
13. Refunds (full and partial credit memos, re-saving a credit memo does not duplicate) and cancellations; skipped when the purchase was skipped/failed/expired. 🟡 (run 2) partial credit memo (adjustment fee) → one `refund_server` row, value 112, `refund_type` partial, same `client_id`, sent by cron; cancelling an order whose purchase was skipped → `cancel` row skipped with the reason. Bug found and fixed (full credit memo never queued). Still open: full credit memo after the fix, two partial memos, re-saving a memo, refund after a failed/expired purchase, cancel of a sent order
14. Order grid column renders, filters and sorts without SQL errors (ambiguous columns), also combined with the grid's own status/date filters; mass action counts and messages; re-sent expired orders arrive in GA4 dated at re-send time. 🟢 column renders and shows "Sent"; filter/sort combinations and mass action still open 🟢 (run 2) mass re-send: confirmation text, 2 queued (anonymous client ids, no timestamp) and 1 not queued with the reason ("skipped on purpose"); the 2 queued orders were sent by cron
15. Event log page (Reports > Marketing > GA4 Server-side Events): grid renders with spacing below the intro text, one row per event, order # links to the order, filters by date/status/event and sorting work without "ambiguous column" errors, ACL; cleanup cron deletes only old sent/skipped rows; *Show in GA4 DebugView* makes server events appear in DebugView. The earlier summary report was verified 🟢 but has been replaced (2026-10-20), so the new grid needs a fresh check 🟢 (run 2) event log renders with intro, 7 rows, filters by Order # work, sorting by order date; order # links to the order (was plain text, fixed). Still open: date/status/event/error filters, ACL, cleanup cron
16. `setup:upgrade` on an install that already had the earlier table (unique key change, new column) as well as on a clean one.

## Needs user testing (👤)
- Docs acceptance: someone who never used GA4/GTM follows only `docs/google-setup.md` on the clean install and reaches a working state.
- Reporting decision in practice: check that `purchase_server` vs `purchase` counts for the same `transaction_id` are useful in a GA4 Exploration.
- Legal/privacy sign-off on the "send anonymously when consent is unknown" default (see Decisions).

## Not started (⏳)
- **Future improvement, noted by the user:** an admin-configured *reporting currency* for purchase events (e.g. an EU+US store that wants everything tracked in EUR instead of EUR + USD). Today the order's currency (or the store base currency) is used. Needs a conversion step (Magento currency rates or a fixed rate) for value/tax/shipping/item prices/discounts, and a decision whether the client-side events follow the same rule. Replaces the earlier "fixed conversion rate" idea.
- Event log: CSV export, store-view filter, per-row detail view of the payload.
- `select_item` / `add_to_compare` client-side events (tags not in the export yet).

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
| Consent | No consent-module dependency; configurable cookie name, accepted value, fallback events, require consent | 2026-10-20 |
| Admin report | Simple event log grid (order date, order #, event, status, error) instead of the per-day summary; intro text spaced from the grid | 2026-10-20 |

## Known risks / notes
- GA4 rejects Measurement Protocol events whose `timestamp_micros` is older than ~72 h. Such rows are closed as `expired` (never sent automatically). With the defaults (8 attempts, delays 1, 2, 4 … 64 min, about 2 h total) this only happens after cron was down for ~3 days. The re-send action sends them again without the old timestamp (dated at re-send).
- `QueueProcessor` in debug mode closes rows as `sent` although Google records nothing; do not leave debug on.
- Follow-ups for an order whose purchase is still `pending` can reach Google before the purchase (each row has its own retry timing). Harmless for comparison, noted.
- Payment-gateway flows that cancel an order right after placement produce both `purchase_server` and `cancel_server`. Expected.
- `view_item_list` on category pages reads the list block's collection; a theme that renames `category.products.list` / `search_result_list` gets no list event (🧪 Hyvä default theme uses these names, checked in the source).
- The client-side events for `add_shipping_info` / `add_payment_info` fire on Hyvä's validation success, before the server confirms a step change (rare false positive, see `hyva-checkout-notes.md`).
