# Implementation status

Single source of truth for progress. **Update this file in every session.** Last updated: 2026-10-20 (eleven local runs on Magento 2.4.9, see "Verified locally" and "Local test coverage").

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

Bugs found by this run (fixed and committed on the branch):
1. **Credit memo never queued a `refund_server` event.** `TotalsResolver::resolveItemPrice()` took `?float $priceInclTax` under `strict_types`, but credit memo items (and orders loaded from the DB for an admin re-send) return decimals as strings, so it threw a `TypeError` that `FollowUpEnqueuer` logged as "could not queue refund". Fix: accept `float|string|null` and cast inside the pure resolver; unit test added. Order placement was not affected (items hold floats there), so the purchase path never showed it.
2. **Order # in the event log grid was plain text instead of a link.** `OrderLink::prepareDataSource()` iterated `$dataSource['data']['items'] ?? []` by reference, which loops over a temporary copy. Fix: iterate the real array.

3. **Console errors on `/checkout/` (Hyvä CSP).** Hyvä Checkout loads the CSP build of Alpine, which cannot evaluate `x-data="initGtmDatalayer()"` / `x-init="checkLogoutCookie()"`: three "Alpine is unable to interpret the following expression" warnings plus a `TypeError` (undefined `x-bind` object), and the component (event delivery from the customer-data section, logout cookie) did not initialise there. Fix: register with `Alpine.data()` on `alpine:init`, `x-data="initGtmDatalayer"` without parentheses, `init()` method; JS tests model the registration and lint every Alpine directive in `view/frontend/templates` for CSP-safe expressions. Verified afterwards: no console messages on cart, product, add to cart, checkout shipping and payment steps; a synthetic `private-content-loaded` on `/checkout/` (CSP build) pushes the event; `add_to_cart` still fires once on a normal page. Checkout CSP is enforced (nonce + hashes for the registered inline scripts, `*.googletagmanager.com` in `script-src`, `*.google-analytics.com` in `connect-src` from Magento's own whitelists): no violations.

Run 3 (mobile, one-page and virtual-only checkout) found no bugs; see item 11 and `local-testing.md`.

### Verified locally, run 4 (🟢, 2026-10-20): consent variants
Same environment; each variant set with `bin/magento config:set googletagmanager/consent/...`, cache flushed, one guest order per server-side case, config rows deleted afterwards (defaults confirmed by behaviour).

| Variant | Browser | Server-side purchase |
|---|---|---|
| *Require Consent* = No | GTM loads with no cookie at all; the `<noscript>` iframe is output | order 000000009: `sent`, real `_ga` client id and session id |
| Event-only (*Consent Cookie Name* empty) | no GTM until `user-allowed-save-cookie` fires, then GTM loads once; no `<noscript>` iframe | consent unknown: 000000010 `sent` anonymously (own random client id, no session id); with *When Consent Cannot Be Determined* = Skip: 000000011 `skipped` / "consent unknown (configured to skip)" |
| Custom cookie name `my_consent` | no cookie: off; `false`: off (also after the event); `true`: on; Magento's default cookie `{"1":1}` is ignored | `true`: 000000012 `sent` with the real `_ga` client id |
| Cookiebot style: name `CookieConsent`, accepted value `statistics:true` (URL-encoded Cookiebot-like value) | `statistics:false`: off (also after the event); `statistics:true`: on | `statistics:true`: 000000013 `sent` with the real client id; `statistics:false`: 000000014 `skipped` / "tracking consent not given" |

No bugs found in run 4. Cookie-deletion gotcha for manual tests: Hyvä's banner writes `user_allowed_save_cookie` with an explicit `Domain`, so clearing it needs `domain=<host>` too (see `local-testing.md`).

Not run yet: see "Local test coverage: tested vs still missing" below (the authoritative list).

### Verified locally, run 5 (🟢, 2026-10-20): FPC, search, ACL, cleanup cron, blocked network, wrong secret
| Area | Result |
|---|---|
| Search page | 🟢 `search` (`search_term` = "bag") then `view_item_list` with `item_list_id` `search_results` and the 8 results; clean console |
| Full page cache (built-in, enabled) | 🟢 category page: first anonymous request MISS, second HIT, both carry the same `view_item_list` payload; another sort order and `?p=2` render different item lists (so the catalog events follow the cached variant). Note: `index` restarts at 0 on page 2 |
| Cleanup cron | 🟢 with synthetic rows (sent / skipped / failed / expired / pending 100 days old, sent 10 days old): only the two old `sent`/`skipped` rows were deleted and logged ("cleanup: deleted 2 …"); retention 0 deletes nothing. Run through the cron class; the daily 03:17 job itself only shows in `cron_schedule` close to its time |
| Blocked network (`www.google-analytics.com` → 127.0.0.1 in the container) | 🟢 pending with growing backoff (59 s, 120 s), `last_error` "Network error: Failed to connect …", `failed` after the maximum attempts (3 in the test) and one `queue run` log line each time; each send attempt returned in under 0.2 s. A real order placed while blocked completed (client `purchase`, row `pending`); the placement code makes no network call by design (observer/enqueuer only insert a row). It was sent on the next run once the host was reachable (attempt 2). Not measured against an unblocked baseline: the order took about 4.7 s in developer mode |
| Wrong API secret | 🟢 behaves as documented: the normal endpoint answers 2xx and the row becomes `sent` (not detectable). **Debug Mode does not detect it either**: Google's validation endpoint answers "valid"/no messages for a wrong secret, so the only check is whether the event appears in GA4 Realtime/DebugView (`local-testing.md` corrected) |
| ACL | 🟢 two throw-away admin roles. Role without the module's resources (only Sales > Orders): menu shows only Sales, no Reports or Stores; the "Re-send GA4 server-side purchase" mass action is still listed (core mass actions are not hidden by ACL either) but running it ends in "Sorry, you need permissions to view this content"; stored rules show `report`, `server_side_resend` and `configuration` as `deny`. Role with the three resources: Reports > GA4 Server-side Events is in the menu and opens (17 rows), Stores > Configuration shows only *Mage-OS > Google Tag Manager*. Not exercised as the restricted user: opening the report or config URL directly and the export download (secret keys are per session; their controllers use `ADMIN_RESOURCE`) |

No bugs found in run 5; one documentation error fixed (wrong secret / Debug Mode).

### Verified locally, run 6 (🟢, 2026-10-20): credit memos, cancellation, expiry, re-send (on Hyvä's one-page checkout)
The three orders of this run (000000016 to 18) were placed with the **one-page** checkout (`hyva_themes_checkout/general/checkout = onepage`, now the checkout in use here since it is the common one): `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase` once each, as in run 3.

| Case | Result |
|---|---|
| Full credit memo (order 16, memo 4) | 🟢 one `refund_server` row, value 47, `refund_type` `full`, same `client_id` as the purchase, sent |
| Two partial credit memos (order 17, 2 items, value 80) | 🟢 two rows with their own `reference_id` (5 and 6): 42 for the first item + shipping, 38 for the other item, both `partial`, items listed per memo; both sent |
| Credit memo after an **expired** purchase (order 18) | 🟢 refund row `skipped`, `last_error` "skipped: server-side purchase was not sent (expired)" |
| Cancel an order whose purchase was sent (order 15) | 🟢 one `cancel_server` row, value 47, same `client_id`, sent |
| Re-save of a credit memo | 🟢 saving a memo twice with a changed field leaves one refund row (unique key). The memo from run 1, whose refund was lost to the `resolveItemPrice` bug, got its row (`reference_id` 2) on the first real re-save and none on the second: lost follow-ups can be recovered by saving the memo again. A **comment** does not count as a re-save: Magento's comment action saves only the comment, so no save event fires; and Magento also skips the save event when nothing changed |
| Expiry | 🟢 order 18's row aged to 80 h (`created_at`, the age is measured from the row, not the payload) and cron run: `expired`, message "unrecoverable: older than Google accepts (72 h), not sent after 0 attempt(s)", payload kept, nothing sent, one `queue run` line with `expired: 1` |
| Mass re-send of the expired order | 🟢 confirmation, "1 order(s) queued …", row `pending` (last_error "manual re-send", no `timestamp_micros`), sent by the next run, i.e. dated at sending time |

No bugs found in run 6. One documentation correction (comment is not a re-save).

### Verified locally, run 7 (🟢, 2026-10-20): default retry schedule, refund / cancel after a failed purchase
Two one-page checkout orders (000000019, 000000020) placed with `www.google-analytics.com` blocked in the container and the **default** *Maximum Send Attempts* (8, no override). The sender was run eight times, each time forcing the row due (`next_attempt_at = 1`) and reading the delay it had computed.

| Case | Result |
|---|---|
| Retry timing, default 8 attempts | 🟢 delay after attempt 1 to 7 = 60, 120, 240, 480, 960, 1920, 3840 s (60 s × 2^(n-1), 127 min in total, the "about 2 h" of the docs); the 8th failed attempt closes the row as `failed` (`attempts` 8, last error "Network error: Failed to connect …"). One `queue run` log line per run: `retried: 2` seven times, then `failed: 2`. The 6 h cap is not reached with 8 attempts (it only matters above 9) and is covered by `RetryPolicyTest` |
| Refund after a failed purchase (order 19: invoice + full credit memo) | 🟢 refund row `skipped`, `last_error` "skipped: server-side purchase was not sent (failed)" |
| Cancel after a failed purchase (order 20) | 🟢 cancel row `skipped`, same reason |
| Mass re-send of the two failed orders | 🟢 confirmation, "2 order(s) queued …"; rows back to `pending` (`attempts` 0, "manual re-send") and `sent` on the next run (attempt 1) |
| Follow-ups after the re-send | ℹ️ the skipped refund and cancel rows **stay skipped**: nothing re-queues them, so GA4 ends up with the purchase but not the refund / cancellation (see "Known risks") |

No bugs found in run 7. One behaviour to decide on (👤): whether a re-sent purchase should also re-queue follow-ups that were skipped only because the purchase had not been sent.

### Test environment: multi-store, multi-currency, multi-tax (2026-10-20)
The test installation now has a second website for Italy (`https://ita.magento249.test/`, EUR, 22 % VAT included in the prices, `it_IT`, Italian provinces) next to the US store (USD, US state tax rates, prices exclude tax); details in the project `CLAUDE.md`. Base currency stays USD, so the order currency (EUR) differs from the base currency. Smoke test: Italian guest order `2000000001` placed on the one-page checkout (store-prefixed increment id), totals €34.04 incl. VAT 6.14, base total $37.00; the consent cookie for that host must be `{"2":1}` (website id).

First observations on the Italian store (not yet analysed in the code; 🧪 to confirm and fix, they are the first multi-currency findings):
1. **`view_item`, `view_item_list`, `add_to_cart` use the base-currency price with the store currency code**: `add_to_cart` and `view_item` show `currency` EUR, `value` 32 (the USD base price) while the shop shows €29.44 (net €24.13).
2. **`view_cart` and `begin_checkout` are wrong in another way**: `currency` EUR, `value` and item `price` 26.23, which is the tax-exclusive EUR price 24.13 multiplied by the EUR→USD rate 1.087 (conversion applied in the wrong direction).
3. `purchase` (client) and `purchase_server` both carry the correct order currency EUR and value 34.04, tax 6.14, but represent the lines differently: client item 24.13 + shipping 3.77 (net) + tax 6.14; server item 29.44 + shipping 4.60 (incl. VAT) with the same tax, following *Tax in Totals = including*.
So events within one visit disagree with each other (and with the shop) for any store whose display currency differs from the base currency or whose prices include tax. Fixed in run 9 (items 1 and 2).

### Verified locally, run 8 (🟢/🔴, 2026-10-20): multi-store, multi-currency, multi-tax smoke test
Environment prepared in this run (see "Test environment" above and the project `CLAUDE.md`): Italy website `ita` (EUR, 22 % VAT included, `it_IT`) next to the US store (USD, state taxes, prices excluding tax), selected by host name through an nginx `MAGE_RUN_CODE` map. Smoke test with real storefront and one Italian guest order on the one-page checkout.

| Area | Result |
|---|---|
| Both hosts | 🟢 `app.magento249.test` unchanged (HTTP 200, `en`); `ita.magento249.test` serves the Italy website in Chrome with valid TLS, `lang="it"`, prices like "29,44 €", Italian checkout texts, 110 provinces |
| Tax and currency math (unsaved quotes, product `24-MB04` $32) | 🟢 Texas: tax 2.64, shipping 5.00, total 39.64; Florida: tax 1.92; California: tax 2.64; Italy: price incl. VAT €29.44 = $32 × 0.92, net 24.13, VAT 5.31, base total larger than the EUR total (order ≠ base currency) |
| Italian checkout (real) | 🟢 subtotal 29,44 €, shipping 4,60 € incl. VAT, VAT 6,14 €, total 34,04 € (27,90 € excl. VAT); shipping *Flat Rate - Fixed*; order `2000000001` (store-prefixed increment id); order currency EUR, base USD, total $37.00 |
| Consent per website | 🟢 cookie `{"2":1}` on the Italian host loads GTM (website id is the map key) |
| Client events on the Italian store | 🔴 `view_item` / `view_item_list` / `add_to_cart` carry the USD base price with currency EUR; `view_cart` / `begin_checkout` carry the net EUR price × 1.087 (see Open issues 1 and 2). `add_shipping_info`, `add_payment_info` fire once as before |
| `purchase` (client) | 🟢 currency EUR, value 34.04, tax 6.14; lines as net amounts (item 24.13, shipping 3.77), which add up to the value |
| `purchase_server` | 🟢 `sent`; currency EUR, value 34.04, tax 6.14; lines as tax-inclusive amounts (item 29.44, shipping 4.60) following *Tax in Totals = including*; `_ga` client id of the visitor |

Not exercised in run 8: *Currency basis* = base currency, *Tax in Totals* = excluding, *Item ID* modes, refunds / cancellations in EUR, switching the US store to EUR, per-website module settings, FPC with two websites.

### Open issues (found locally, not fixed yet)
| # | Issue | Evidence | Status |
|---|---|---|---|
| 1 | `view_item`, `view_item_list`, `add_to_cart` sent the **base-currency price labelled with the store currency** | Italian store: `view_item` and `add_to_cart` `value` 32 with `currency` EUR; shop shows €29.44 (net €24.13) | 🟢 fixed and verified in run 9 |
| 2 | `view_cart`, `begin_checkout` sent the **base-currency, tax-exclusive quote item price** labelled with the store currency | Italian store: `value`/`price` 26.23 EUR = 32 / 1.22 (not a rate effect, see run 9) | 🟢 fixed and verified in run 9 |
| 3 | Client `purchase` and `purchase_server` represented lines differently (client = Google's net convention, server = *Tax in Totals* setting) (client: net item and shipping + tax; server: tax-inclusive item and shipping, setting *Tax in Totals*) | same order: client 24.13 + 3.77 + 6.14, server 29.44 + 4.60, both = 34.04 | 🟢 resolved in run 11: server lines now the same as the client's |
| 4 | A refund or cancel skipped because the purchase had not been sent was not re-queued when the purchase was re-sent | run 7 | 🟢 fixed and verified in run 10 |

### Verified locally, run 9 (🟢, 2026-10-21): client-side prices in multi-currency / tax-inclusive stores (Open issues 1 and 2 fixed)
**Root cause (one bug, two symptoms).** The browsing and cart events took Magento's *base-currency* amounts but labelled them with the store's *current* currency:
- `view_item`, `view_item_list`, `add_to_cart`, `remove_from_cart` used `Product::getFinalPrice()`: base currency, and with the tax *included* when "Catalog Prices" include tax. Italy: $32 (incl. VAT) shown as `EUR 32`.
- `view_cart`, `begin_checkout`, `update_cart` used the quote item `price`, which Magento keeps in the **base currency**, net of tax (set by the tax collector from the base tax details): $32 / 1.22 = 26.23, labelled `EUR`. (My first explanation, "EUR→USD rate applied backwards", was a coincidence: 24.13 × 1.087 equals 32 / 1.22 because the two rates are reciprocal.)

**Fix** (`Service/AbstractGtmService.php`, `Service/CartService.php`, commit "Fix client-side item prices in multi-currency and tax-inclusive stores"): catalog events use the price info's tax-excluding amount (Magento's price info is already in the current currency; only the fallback to the raw catalog price is converted); cart items use the quote item's `converted_price` (display currency, excluding tax), with row total / qty as fallback; `remove_from_cart` uses the cart row. Item prices of all events are now tax-excluding amounts in the display currency, the same basis as the order items of `purchase`. No unit test possible (Magento price APIs); covered by the checks below.

| Store / case | Shop shows | `view_item` | `add_to_cart` | `view_cart` | `begin_checkout` | `remove_from_cart` |
|---|---|---|---|---|---|---|
| Italy, EUR, 22 % VAT included (product $32) | €29.44 (net 24.13) | 24.13 EUR 🟢 | 24.13 EUR 🟢 | 24.13 EUR 🟢 | 24.13 EUR 🟢 | not run |
| US, USD, tax added at checkout | $32.00 | 32 USD 🟢 | 32 USD 🟢 | not run | 32 USD 🟢 | not run |
| US store switched to EUR | €29.44 | 29.44 EUR 🟢 | 29.44 EUR 🟢 | 2 × 29.44 = 58.88 EUR 🟢 (shop: subtotal €58.88) | not run | 58.88 EUR for 2 × 29.44 🟢 |

`view_item_list` on the Italian bags category: net prices in EUR (Strive 24.13, Crown Summit 28.66); one product shows 31.28 = its shop price because its tax class in the sample data is "None" (no VAT to remove). `update_cart` and `view_item_list` on the US store were re-run in run 10 (fine); not re-run: FPC with the Italian store.

**Open issue 3 explained (not a bug, a representation difference).** Client `purchase` follows Google's item convention (item price and shipping without tax, tax separate); `purchase_server` follows the admin setting *Tax in Totals* (default "including": item and shipping with tax). Both give the same `value`. Decision for the owner: keep the two representations, or add a setting to the client event.

### Verified locally, run 10 (🟢, 2026-10-21): `update_cart` / `view_item_list` after the price fix; follow-ups after a re-send (Open issue 4 fixed)
**1. Client events re-run after the run 9 fix**

| Store | Case | Result |
|---|---|---|
| US (USD) | `view_item_list` on Gear > Bags | 🟢 `currency` USD, 12 items; first four 34, 32, 38, 45 = the shop prices; sorted by price descending: 74, 59, 45 with `index` 0, 1, 2 |
| US (USD) | `update_cart`, quantity 1 → 3 | 🟢 USD, value 96, item 32 × 3 (shop subtotal $96.00) |
| Italy (EUR, VAT included) | `update_cart`, quantity → 3 | 🟢 EUR, value 72.39, item 24.13 × 3 (shop subtotal 88,32 € incl. VAT, 72.39 net) |

**2. Follow-ups skipped because the purchase had not been sent are now queued again when it is re-sent.**
Cause (found by reading the code): the skipped refund / cancel row carries no payload (it was never built) and its unique key (`order_id`, `event_type`, `reference_id`) blocked any new attempt, while the re-send only reset the *purchase* row. Fix: `PurchaseResender` deletes those skipped rows and rebuilds the events from the order's credit memos and its canceled state. The rule that picks the rows is pure and unit-tested (`FollowUpPolicy::rowsToRequeue`: only rows skipped with the reasons "purchase was not sent (…)" or "no server-side purchase recorded"; rows skipped for consent or an exclusion are left alone). The mass action reports "N refund / cancellation event(s) that had been skipped because the purchase was not sent were queued again". 140 PHPUnit tests pass.

| Case | Result |
|---|---|
| Order 19 (full credit memo, refund skipped) and order 20 (canceled, cancel skipped) with their purchases set back to `failed`; mass re-send | 🟢 purchases `pending` again **and** the refund (value 47, `full`) and cancel rows `pending` with payloads; both messages shown; the next cron run sent all four (Debug Mode on, so nothing was recorded in GA4; switched off again) |
| A purchase that is already `sent` / `pending` | not touched by the re-send, so its follow-ups are not touched either (logic only, not run) |
| Follow-ups skipped for consent / exclusion | not re-queued (unit test; not run in the browser) |

Remaining order of events: the re-queued follow-up can reach Google before the purchase (each row has its own retry timing); harmless for a comparison signal, noted in the risks.

### Verified locally, run 11 (🟢, 2026-10-21): client and server events use the same lines (Open issue 3 resolved)
**Decision:** the server-side `purchase_server` (and `refund_server`) must describe the order with the same lines as the client-side `purchase`: item prices and shipping **without tax**, `tax` separately, `value` the grand total. Before, the server lines followed *Tax in Totals* (default "including": tax-inclusive item prices and shipping), so one order was 24.13 + 3.77 + 6.14 on the client and 29.44 + 4.60 on the server.

**Change:** item prices (order item price, credit memo item price) and shipping are always without tax; *Tax in Totals* now only decides whether `value` is the grand total (default, as on the client) or the grand total minus tax. The tax-inclusive price lookup (`TotalsResolver::resolveItemPrice`) is removed, which also removes the string-price code path behind the earlier refund bug. Labels, the admin comment and the README were updated; `TotalsResolverTest` rewritten (139 PHPUnit tests pass).

| Check | Result |
|---|---|
| Italian order `2000000002` (3 × `24-MB04`, EUR, VAT 22 % included, flat rate shipping per item) | 🟢 client `purchase`: value 102.12, tax 18.42, shipping 11.31, item 24.13 × 3; `purchase_server` payload: identical (EUR, 102.12, 18.42, 11.31, `24-MB04` 24.13 × 3) |
| Full credit memo of that order | 🟢 `refund_server` EUR, value 102.12, item 24.13 × 3, `refund_type` `full`; both events sent (Debug Mode on, then off) |
| *Tax in Totals* = Excluding | logic only (unit tests: `value` = grand total minus tax, shipping and tax unchanged); not run in the browser |

### Local test coverage: tested vs still missing (kept up to date, last: 2026-10-21 after run 11)
**Tested locally on Magento 2.4.9 + Hyvä + Hyvä Checkout (guest orders unless noted):**
- Install / update / uninstall of the consent stub, `setup:upgrade`, `setup:di:compile`, DB table + FK, 139 PHPUnit + 30 JS tests
- Admin config rendering, ID validation both ways, encrypted API secret, container export generated
- Consent: console cookie (accept / decline / none), fallback event, Hyvä built-in banner, *Require Consent* = No, event-only (Send and Skip), custom cookie name, Cookiebot-style accepted value, server side for each
- Client events: `view_item_list`, `view_item`, `search`, `add_to_cart` (no replay), `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`; default, mobile, one-page and virtual-only checkouts; CSP console check on checkout (enforced CSP, Alpine CSP build)
- Server side: purchase queue, cron send, `_ga` client/session ids, consent skip, anonymous send, full and two partial refunds, re-saving a memo (dedupe and recovery of a lost row), refund / cancel after an expired, a skipped and a failed purchase, cancellation of a sent purchase, the full default retry schedule (8 attempts), expiry (> 71 h) and mass re-send of an expired order, Debug Mode, retries / failed with blocked network, wrong secret, cleanup cron
- Hyvä one-page checkout (also used for the run 6 orders), default, mobile and virtual-only checkouts
- Multi-store environment: second website (Italy, EUR, 22 % VAT included, `it_IT`) selected by host, US tax rates, tax and currency math, one Italian order end to end (client and server events, consent per website); client-side item prices in USD, EUR-on-Italy (VAT included) and EUR-on-US (run 9, after the fix)
- Admin: order grid column, event log grid (filters, sort, order link), ACL for two roles, FPC (MISS/HIT, sort, pagination)

**Still missing (🧪 unless marked 👤):**
- Multi-currency / multi-tax (Italian store exists): *Currency basis* = base currency (the default order currency is tested), *Tax in Totals* = Excluding in the browser (including is tested, excluding only by unit tests), *Item ID* parent / child with a configurable product, the US store with EUR display and a real order (only the events were checked), per-website module settings
- Orders: logged-in customer (`user_id`), admin-created order (*Track Admin-created Orders* No/Yes), multishipping, redirect-based payment methods (Mollie, Braintree are installed), configurable products (`item_variant`), coupons / discounts, excluded statuses / payment methods / zero-value orders, a real order with no consent cookie at all while consent is required
- Follow-ups: refunds with coupons / discounts / multi-currency / tax options, a refund whose purchase is still `pending` (can reach Google first), refund / cancel after a re-sent purchase with a coupon / partial memo (only full memo and cancel tried)
- Client events not yet exercised: `login`, `logout`, `sign_up`, `add_to_wishlist`/`remove_from_wishlist`, single `page_view`, `select_item`-style events (not implemented)
- Google side (👤 a person): `purchase_server`/`refund_server`/`cancel_server` and client events in GA4 Realtime/DebugView, GTM container import (Merge) acceptance, GTM Preview, whether server and client `transaction_id`s line up in an Exploration, the 503 seen on `collect` requests in the test browser
- Platform: Varnish, logged-in customer group prices with FPC, multi-website / multi-store, real `MageOS_CookieConsent` (does not exist yet), user-agent based switch to the mobile checkout, upgrade from the earlier table version (item 16), production mode (`di:compile` + static content deploy)
- Tooling: `dev/check.sh` inside the Warden container (`php -l` segfault), `dev/check-magento.sh` (PHPStan/XSD against the Magento sources)
- Docs acceptance by someone new to GA4/GTM (👤), legal sign-off on the anonymous-send default (👤)

## Needs local testing (🧪)
Everything under "Magento layer" above, in particular the risky items:
1. `setup:upgrade` creates the table; the hand-written `db_schema_whitelist.json` is accepted (else regenerate it); FK to `sales_order` OK. 🟢 (2026-10-20)
2. `setup:di:compile` passes (constructor types, nullable defaults). 🟢 (2026-10-20)
3. Admin config renders; saving a swapped ID shows the validation error; API secret is stored encrypted. 🟢 (2026-10-20)
4. Observer fires for: guest order, logged-in order, admin-created order, multishipping, order placed via payment redirect/callback. Check `$order->getEntityId()` is set at `checkout_submit_all_after`. 🟢 guest order only (observer fires, order id set); logged-in, admin, multishipping and redirect payments still open
5. `OrderSnapshotFactory`: values/currency match the client-side `purchase` for the same order; configurable products show one item with `item_variant`; categories fill `item_category..5`; discount per unit. 🟢 guest order, simple products only; configurable/coupon/discount still open
6. Configurable consent (2026-10-20, replaces the `MageOS_CookieConsent` dependency): defaults with the console cookie / Hyvä built-in banner, decline, no cookie, fallback event, custom cookie name, "accepted" value (Cookiebot style), *Require Consent* = No, event-only setup on the server side; session cookie name `PHPSESSID`. Browser gate and server must agree (`local-testing.md`, "Simulating cookie consent") 🟢 (run 2) tested without any consent module: defaults with a console cookie, decline, no cookie, fallback event (also after a decline: GTM stays off), and Hyvä's built-in banner (Cookie Restriction Mode on, "Allow Cookies" loads GTM on that page via the event and on the next page via the cookie; server rows use the same rules: accepted = real `_ga` client id, declined = `skipped`). (run 4) *Require Consent* = No, event-only setup (empty cookie name, both *When Consent Cannot Be Determined* actions), custom cookie name and a Cookiebot-style accepted value `statistics:true` behave as documented in the browser and on real orders, see "Verified locally, run 4". Still open: a real order with no consent cookie at all while consent is required (expected `skipped`)
7. `_ga` / `_ga_<ID>` parsing against real cookies (both `GS1` and `GS2` formats), and that the server event joins the browser session in GA4. 🟡 `client_id` and `session_id` were taken from the real `_ga` / `_ga_<ID>` cookies of a guest order; which GS format Google sent and joining the browser session in GA4 are still to check
8. Cron picks up pending rows; retry/backoff against a blocked network; permanent-error path with a wrong API secret. 🟢 cron sends once the `curl_close()` bug was fixed; retry/backoff with a blocked network and the wrong-secret path still open (run 5) 🟢 retry/backoff and `failed` with a blocked network, and the wrong-secret path (accepted with 2xx, not detectable even in Debug Mode) (run 7) 🟢 the full default schedule with 8 attempts measured: 60, 120, 240, 480, 960, 1920, 3840 s, then `failed`
9. Debug mode: validation messages are logged to `var/log`. 🟢 (run 2) log shows "Payload valid (debug endpoint, event not recorded)"; no GA4 check needed
10. `purchase_server` visible in GA4 Realtime / DebugView with correct value, currency, items. 🟢 queue side (row reaches `sent`); GA4 Realtime/DebugView check still needs a person
11. Hyvä Checkout shipping/payment events (`local-testing.md` section 5): once per submission, right method codes, default/mobile/onepage checkouts, virtual cart. 🟢 default, mobile (`add_payment_info` fires when leaving the payment step), one-page (both at Place Order) and a virtual-only cart (only `add_payment_info`), guest orders, each event once with the right method code. Still open: the user agent switch to the mobile checkout, logged-in customers, multiple addresses
12. Client-side fixes: flat `items`, purchase currency, single `page_view`, custom-event triggers after re-importing the container, `view_item` / `view_item_list` / `search` on product, category and search pages (also with FPC/Varnish on, second visitor gets the same events). 🟡 partly: `view_item_list`, `view_item`, `view_cart`, `begin_checkout` seen in `dataLayer`; GTM Preview, search page, FPC/Varnish still open (run 5) 🟢 search page (`search` + `view_item_list`), FPC MISS/HIT with identical events, sort and pagination; still open: Varnish, GTM Preview, logged-in customer group prices with FPC
13. Refunds (full and partial credit memos, re-saving a credit memo does not duplicate) and cancellations; skipped when the purchase was skipped/failed/expired. 🟡 (run 2) partial credit memo (adjustment fee) → one `refund_server` row, value 112, `refund_type` partial, same `client_id`, sent by cron; cancelling an order whose purchase was skipped → `cancel` row skipped with the reason. Bug found and fixed (full credit memo never queued). Still open: full credit memo after the fix, two partial memos, re-saving a memo, refund after a failed/expired purchase, cancel of a sent order (run 6) 🟢 full credit memo, two partial memos, re-save (dedupe and recovery of a lost row), refund after an expired purchase (skipped) and cancel of a sent order; still open: refund after a *failed* purchase (run 7) 🟢 refund and cancel after a *failed* purchase are `skipped`; a skipped follow-up is not re-queued when the purchase is re-sent
14. Order grid column renders, filters and sorts without SQL errors (ambiguous columns), also combined with the grid's own status/date filters; mass action counts and messages; re-sent expired orders arrive in GA4 dated at re-send time. 🟢 column renders and shows "Sent"; filter/sort combinations and mass action still open 🟢 (run 2) mass re-send: confirmation text, 2 queued (anonymous client ids, no timestamp) and 1 not queued with the reason ("skipped on purpose"); the 2 queued orders were sent by cron
15. Event log page (Reports > Marketing > GA4 Server-side Events): grid renders with spacing below the intro text, one row per event, order # links to the order, filters by date/status/event and sorting work without "ambiguous column" errors, ACL; cleanup cron deletes only old sent/skipped rows; *Show in GA4 DebugView* makes server events appear in DebugView. The earlier summary report was verified 🟢 but has been replaced (2026-10-20), so the new grid needs a fresh check 🟢 (run 2) event log renders with intro, 7 rows, filters by Order # work, sorting by order date; order # links to the order (was plain text, fixed). Still open: date/status/event/error filters, ACL, cleanup cron (run 5) 🟢 ACL (menu, mass action denied, config section scoped) and cleanup cron (only old sent/skipped rows, retention 0 keeps all); direct URL / export download as a restricted user not exercised
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
- A refund or cancellation skipped because the purchase had not been sent (`failed`, `expired`, never recorded) is queued again when the purchase is re-sent from the order grid (run 10): the skipped row is deleted and the event rebuilt from the credit memos / canceled state. Follow-ups skipped for consent or an exclusion are not touched. A re-queued refund can reach Google before its purchase.
- Follow-ups for an order whose purchase is still `pending` can reach Google before the purchase (each row has its own retry timing). Harmless for comparison, noted.
- Payment-gateway flows that cancel an order right after placement produce both `purchase_server` and `cancel_server`. Expected.
- `view_item_list` on category pages reads the list block's collection; a theme that renames `category.products.list` / `search_result_list` gets no list event (🧪 Hyvä default theme uses these names, checked in the source).
- The client-side events for `add_shipping_info` / `add_payment_info` fire on Hyvä's validation success, before the server confirms a step change (rare false positive, see `hyva-checkout-notes.md`).
