# Implementation status

Single source of truth for progress. **Update this file in every session.** Last updated: 2026-10-21 (twenty-one local runs on Magento 2.4.9, see "Verified locally" and "Local test coverage"; Open issues 15 to 18 and `coupon: null` fixed in cloud afterwards, not yet verified locally).

Legend: ✅ done and verified in cloud (lint/XML/JSON/unit tests) · 🧪 needs local test (Magento 2.4.9 + Hyvä + Hyvä Checkout, see `local-testing.md`) · 👤 needs user (human) testing/decision · ⏳ not started · ❓ blocked on an answer · 🟢 verified locally on a real install (date and environment in "Verified locally")

How "verified" is used: cloud verification means PHP syntax, XML well-formedness, JSON validity, and the Magento-free unit/JS tests pass (`dev/check.sh`). It does **not** mean the Magento wiring works.

## Summary by phase

| Phase | State |
|---|---|
| 1 Client-side purchase fix | ✅ code + tests · 🟢 one `purchase` per order, no replay on reload (runs 1 to 13) |
| 1b Hyvä Checkout `add_shipping_info` / `add_payment_info` | ✅ · 🟢 default, mobile, one-page, virtual-only, logged in, Stripe (runs 1 to 13) · 🧪 user-agent switch to the mobile checkout |
| 0 Measurement Protocol foundation | ✅ · 🟢 queue, cron, retries, expiry, wrong secret, debug mode (runs 1 to 7) |
| 2 Server-side purchase event | ✅ · 🟢 guest / logged-in / admin / Stripe orders, consent variants, exclusions, product types (runs 1 to 13) · 🟢 2026-10-21 changes: excluded status = hold + release (run 18, simulated `pending_payment`; real Stripe redirect 🧪), zero-value setting in General (run 17), consent object (run 19) · reporting currency ⏳ future |
| 3 Refunds / cancellations / mass re-send | ✅ · 🟢 full / partial / discounted / bundle refunds, cancel, re-send, follow-up re-queue (runs 2 to 13) · 🟢 refund item ids and bundle quantity (run 15), consent copied to refunds (run 19) |
| 4 Admin visibility (grid column, event log, DebugView flag, retention) | ✅ · 🟢 grid column, event log, ACL, cleanup (runs 2, 5) · 🧪 DebugView flag in GA4 (needs a person) |
| Client-side fixes | ✅ · 🟢 prices in multi-currency / tax-inclusive stores (run 9), single `page_view`, FPC · 🟢 client items described like the server (run 15), grouped / merged cart events (run 16) · 🧪 GTM Preview, Varnish |
| Competitor parity (Consent Mode v2, select_item + list attribution, promotions, brand/affiliation, debug logger) | ✅ code + tests + static checks (2026-10-21) · 🟢 local runs 19 and 20 (Consent Mode Off / Basic / Advanced, select_item + list attribution, promotions, brand / affiliation, debug logger) · 🧪 GTM Preview and GA4 side (needs a person) |
| Second competitor batch (custom GTM URL, page/visitor context, customer-group exclusion, max transaction value, add_to_compare, minicart view_cart, category depth / exclusions, custom item parameters) | ✅ code + tests + static checks (2026-10-21) · 🟢 local run 21 (page context, customer groups + FPC, max value, compare, minicart view_cart, category options, custom item parameters, custom GTM URL with CSP) · 🔴 variant-level custom parameter (Open issue 17), 🟡 URL edge cases (18) · 👤 a real first-party / server-side GTM container |
| Multishipping | ⛔ not supported or tested (user decision 2026-10-21), documented |
| GTM container export | ✅ · 🟢 imported by the user after the `fieldsToSet` key fix (run 14) · 🧪 GTM Preview of the new tags |
| 5 Documentation | ✅ · 👤 docs acceptance |

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

### Verified locally, run 3 (🟢, 2026-10-20): mobile, one-page and virtual-only checkout
Same environment as run 2 (no consent module); checkouts selected through *Hyvä Checkout > General > Checkout* (`mobile`, `onepage`; the user agent based switch itself was not tried, the browser tool cannot change it); a temporary virtual product created by script and deleted afterwards; everything reset to `default` at the end.

| Checkout | Result |
|---|---|
| One-page (physical item, order 7) | 🟢 `add_shipping_info` (`tablerate_bestway`) and `add_payment_info` (`checkmo`) once each at *Place Order*, one `purchase`, clean console |
| Mobile (shipping, payment, summary; order 8) | 🟢 `add_shipping_info` on *Proceed to Payment*, `add_payment_info` on *Proceed to Summary*, nothing more at *Place Order*, one `purchase`, clean console (differs from the earlier note "both events at order placement") |
| Virtual-only cart (order 6, value 10) | 🟢 no shipping step; `begin_checkout`, `add_payment_info` once, no `add_shipping_info`; `purchase`; server row with shipping 0, sent |
| Also seen | 🟢 `remove_from_cart` fires when a cart item is removed; the three server rows were `sent` with the visitor's `_ga` client id |

No bugs found in run 3.

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

### Open issues found locally (1 to 14 fixed and verified, 15 to 18 fixed in cloud and not yet verified locally)
| # | Issue | Evidence | Status |
|---|---|---|---|
| 1 | `view_item`, `view_item_list`, `add_to_cart` sent the **base-currency price labelled with the store currency** | Italian store: `view_item` and `add_to_cart` `value` 32 with `currency` EUR; shop shows €29.44 (net €24.13) | 🟢 fixed and verified in run 9 |
| 2 | `view_cart`, `begin_checkout` sent the **base-currency, tax-exclusive quote item price** labelled with the store currency | Italian store: `value`/`price` 26.23 EUR = 32 / 1.22 (not a rate effect, see run 9) | 🟢 fixed and verified in run 9 |
| 3 | Client `purchase` and `purchase_server` represented lines differently (client = Google's net convention, server = *Tax in Totals* setting) (client: net item and shipping + tax; server: tax-inclusive item and shipping, setting *Tax in Totals*) | same order: client 24.13 + 3.77 + 6.14, server 29.44 + 4.60, both = 34.04 | 🟢 resolved in run 11: server lines now the same as the client's |
| 4 | A refund or cancel skipped because the purchase had not been sent was not re-queued when the purchase was re-sent | run 7 | 🟢 fixed and verified in run 10 |
| 5 | Client `item_id` of a configurable switches from child SKU (`add_to_cart`, `view_cart`, `begin_checkout`) to parent (`purchase`) inside one visit | run 12, order 21 | ✅ fixed 2026-10-21 (`Service/ItemDataResolver`: every event follows *Item ID*), 🟢 verified run 15 |
| 6 | Client `purchase` `discount` is negative (−5.2) and event-level; GA4 expects positive item-level discounts | run 12 | ✅ fixed 2026-10-21: positive per-unit `discount` on each client item (cart, checkout, purchase), event-level field removed, 🟢 verified run 15 |
| 7 | `refund_server` item_id is the child SKU of a configurable, the purchase uses the parent (or child per *Item ID*) | run 12, credit memo of order 21 | ✅ fixed 2026-10-21 (refund items described from the order item, same rule), 🟢 verified run 15 |
| 8 | Client items lack `item_variant`; client `item_category` is the first category found, the server sends the category path | run 12 | ✅ user decision "client matches server", implemented 2026-10-21, 🟢 verified run 15 |
| 9 | Client sends `purchase` for zero-value orders, the server excludes them | run 12, order 24 | ✅ user decision: *Exclude Zero-value Orders* moved to the General group and applies to the client `purchase` too (2026-10-21), 🟢 verified run 17 |
| 10 | **Bundle `item_id`** is the composed cart SKU (`24-WG080-24-WG081-blue-24-WG084-24-WG085-24-WG088`) in the client cart events and in `purchase_server`, but `24-WG080` in the client `purchase`; the composed SKU changes with the selected options, so one product shows up as many items in GA4 | run 13, order 31 | ✅ fixed 2026-10-21: bundles always use the bundle's catalog SKU (`ItemIdResolver`), 🟢 verified run 15 |
| 11 | **`refund_server` of a bundle**: `quantity` 4 (the credit memo's bundle row counts its children), purchase 1, and the composed `item_id` | run 13, credit memo of order 31 | ✅ fixed 2026-10-21: bundle quantity derived from the children (`RefundQuantity`), catalog SKU, 🟢 verified run 15 |
| 12 | Client `add_to_cart` for a **grouped** product describes the group parent (14 × 1) instead of the child lines added; only one `add_to_cart` is delivered when several adds happen before the next customer-data load (single session slot) | run 13 | ✅ fixed 2026-10-21 after comparing Yireo GoogleTagManager2 and WeltPixel GA4 Pro 1.17.5: one `add_to_cart` with one item per child added (as WeltPixel); pending add/remove-from-cart and wishlist events are merged (items appended, `value` recomputed, unlike WeltPixel which keeps the first value) instead of overwritten. 🟢 verified run 16 |
| 13 | **Orders that are not paid yet** (redirect-based methods, `pending_payment`) are tracked at placement; the client `purchase` cannot be excluded; a server row skipped by *Excluded Order Statuses* is never re-queued when the order is paid later (no status-change observer, re-send ignores skipped rows) | run 13, orders 37, 38 | ✅ user decision "re-queue when an allowed status is reached", implemented 2026-10-21: status-excluded purchases are stored as `waiting` (payload with consent and GA ids) and released on `sales_order_save_after`; client `purchase` still at placement (documented). 🟢 verified run 18 (invoice / status changes and a simulated `pending_payment`); 🧪 the real Stripe Checkout redirect with a person paying |
| 14 | No client `purchase` / events on the **multishipping** success page (not wired); multishipping has no UI on Hyvä anyway | run 13 | ⛔ won't do: multishipping is not supported or tested (user decision 2026-10-21, documented in README and plan) |
| 15 | **Bundle price in a tax-inclusive store is gross in `view_item` / `add_to_cart` and net in `view_cart` / `begin_checkout` / `purchase` / `purchase_server`**: Italian store, Sprite Yoga Companion Kit: 56.12 vs 48.33 (the same cart, 87.54 vs 95.33 event value). The bundle's own tax class does not match its children (one child has no tax class), so the PriceInfo amount used for catalog and add-to-cart events still contains VAT | run 15, order 2000000003 | ✅ fixed 🧪 verify: a bundle's catalog price is now the bundle price model's minimal price without tax (`getTotalPrices(min, excluding tax)`, tax removed per option with the option's tax class, price index values ignored); `add_to_cart` of a bundle uses the cart row's price (`converted_price`), as `view_cart` |
| 16 | **A coupon on a dynamic-priced bundle gives the bundle line no `discount`**: Magento books the discount on the bundle's children (6.1 of the 11.3 discount of order 000000040), the bundle line carries 0; the event `value` is right (113 − 11.3 + 5 + 7.35 = 114.05), the sum of the item discounts (5.2) is not | run 15, orders 000000040, 2000000003 (client and server identical) | ✅ fixed 🧪 verify: a bundle line without its own discount gets the sum of its children's discounts (per unit), client (`CartService`) and server (`OrderSnapshotFactory`) |
| 17 | **Custom Item Parameters read the configurable parent only**: `item_color:color` gives nothing for the Chaz Kangeroo Hoodie because `color` is set on the simple variant (Black), not on the configurable (parent `material` "Wool" works). Variant-level attributes are therefore never sent for configurables, client or server | run 21 (view_cart, add_to_cart; DB: parent 62 has no `color`, child 47 has Black) | ✅ fixed 🧪 verify: brand and custom parameters are read from the chosen variant first (`simple_product` option, child quote item, child order item), then from the parent; only for configurables (bundle children are not variants) |
| 18 | *Custom GTM Script URL*: a value with a path (`https://host/custom/path`) is accepted and used although the help text says "no path"; an invalid `http://host` value falls back to Google for `gtm.js` but the host is still added to the CSP | run 21 | ✅ fixed 🧪 verify: `Service/DataLayer/GtmScriptUrl` (20 tests) allows only `https://host[:port]` (trailing slash dropped); saving anything else fails (backend model `Model/Config/Backend/GtmScriptUrl`), and the storefront and the CSP collector use the same rule, so an invalid stored value loads Google's `gtm.js` and adds no host to the CSP |

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

### Fixes of Open issues 15 to 18 and `coupon: null` (2026-10-21, cloud; ✅ unit/JS tests + PHPStan on the changed files; 🧪 not verified locally)
- **15 Bundle price** (`Service/AbstractGtmService::bundleNetPrice`): `view_item`, `view_item_list`, wishlist and similar events use the bundle price model's minimal total excluding tax, computed from the options (price index values left out), converted to the display currency. `add_to_cart` of a bundle (`Observer/AddToCart`) looks up the cart row (`Quote::getItemByProduct`) and sends its price, so it equals `view_cart` for the chosen options.
- **16 Bundle discount**: when the bundle line has no discount, the children's discounts are summed (client `CartService::rowDiscount`, server `OrderSnapshotFactory::buildItem`).
- **17 Variant attributes**: `ItemDataResolver` reads brand and custom item parameters from the chosen configurable variant first, then the parent (client and server).
- **18 Custom GTM Script URL**: strict validation on save, at runtime and for the CSP (`Service/DataLayer/GtmScriptUrl`, `Model/Config/Backend/GtmScriptUrl`). A previously saved value with a path now falls back to Google until it is saved again without the path.
- **`coupon: null`**: the client `purchase` leaves `coupon` out when the order has none, as the server does.
- Tests: 205 PHPUnit (20 new), 51 JS. PHPStan of the whole tree currently aborts on a missing Laminas class (cloud bootstrap, unrelated); the changed files were analysed on their own and show only the known magic-method findings.

### Changes of 2026-10-21 (cloud; ✅ unit/JS tests + PHPStan/XSD; verified locally in runs 15 to 18)
- **Client items match the server** (Open issues 5 to 8, 10): new `Service/ItemDataResolver` describes every item for all events: `item_id` via `ItemIdResolver` and the *Item ID for Configurable Products* setting (now applies to client and server; `add_to_cart` reads the selected variant from the product's `simple_product` option), `item_variant` (configurable options), category path `item_category` … `item_category5`. Client cart / checkout / purchase items carry a positive per-unit `discount`; the client `purchase` has no event-level `discount` any more. Bundles always use their catalog SKU.
- **Refunds** (Open issues 7, 11): items are described from their order item with the same rule; a bundle's refunded quantity is derived from its children (`Service/MeasurementProtocol/RefundQuantity`, 4 tests).
- **Excluded status = hold** (Open issue 13): `Model/PurchaseEnqueuer` stores such a purchase as `waiting` (new status) with its payload; `Observer/ReleaseWaitingPurchase` on `sales_order_save_after` → `Model/PurchaseReleaser` → `HoldPolicy` (11 tests) queues it once the status is no longer excluded and the state is not canceled / closed / pending_payment / payment_review; the timestamp is dropped (dated at release), follow-ups skipped meanwhile are re-queued. Re-send ignores waiting rows; grid label "Waiting (order status)". Rows skipped by the old rule (before this change) are not converted.
- **Zero-value orders** (Open issue 9): *Exclude Zero-value Orders* moved from the server-side group to General (`googletagmanager/general/exclude_zero_value`, default Yes) and suppresses the client `purchase` too (`Block/CheckoutTagManager` renders `null`; JS test). A value saved under the old path is not migrated.
- **Multishipping** (Open issue 14): not supported or tested, documented (README, plan, local-testing).
- Tests: 155 PHPUnit, 31 JS.
- **Grouped products / several adds** (Open issue 12): `CartService::addGroupedToCart()` builds one item per child with a quantity from `super_group`; `EventQueue::appendSessionEvent()` + `Service/DataLayer/EcommerceEventMerger` (5 tests) combine pending add_to_cart / remove_from_cart / wishlist events. Compared with Yireo GoogleTagManager2 (sends the group parent with quantity 1, single slot) and WeltPixel GA4 Pro 1.17.5 (one item per child, merges only some cases, keeps the first value). Tests now 160 PHPUnit.

### Second competitor batch (2026-10-21, cloud; ✅ tests + PHPStan/XSD; verified locally in run 21)
- **Custom GTM Script URL** (General): `gtm.js` / `ns.html` from a first-party or server-side GTM host; `Model/Csp/GtmScriptHostCollector` adds the host to script-src, connect-src, img-src, frame-src.
- **Page and visitor context**: `{page_type, customer_group, logged_in}` pushed before GTM on every page (`PageTypeResolver`, 13 tests; `PageContext` from the HTTP context, FPC-safe). Exported GA4 Configuration tag: `fieldsToSet` page_type, `userProperties` customer_group / logged_in.
- **Don't Track Customer Groups** (General multiselect incl. NOT LOGGED IN): `TagManager::isEnabled()` false (no GTM, no events), server exclusion "excluded: customer group".
- **Maximum Transaction Value** (General, base currency): no client `purchase`, server "excluded: above the maximum transaction value".
- **`add_to_compare`**: observer on `catalog_product_compare_add_product`, `CompareService`, GTM tag *GA4 Addtocompare*.
- **`view_cart` when the minicart opens** (General Yes/No): the `gtm` customer-data section carries a ready `view_cart`; `body.phtml` pushes it on Hyvä's `toggle-cart` (isOpen). Also added `checkout/sidebar/removeItem` and `updateItemQty` to `sections.xml`, so `remove_from_cart` / `update_cart` from the drawer are now delivered (they were not before). 2 JS tests.
- **Category Depth** and **Excluded Categories** (`CategoryPathPicker`, 3 tests) for client and server items.
- **Custom Item Parameters** (up to five `name:attribute` pairs, `CustomItemAttributes`, tests): on every item, client and server (`PayloadBuilder` passes valid extra item keys through, test).
- Tests now 185 PHPUnit, 51 JS. GTM container must be re-imported.

### Competitor parity (2026-10-21, cloud; ✅ tests + PHPStan/XSD; verified locally in runs 19 and 20)
After comparing Yireo GoogleTagManager2 and WeltPixel GA4 Pro 1.17.5 the user chose items 1, 2, 3, 4 and 6:
- **Google Consent Mode v2**: *Cookie Consent > Google Consent Mode v2* = Off (default) / Basic / Advanced, *Signals Granted on Consent* = all / analytics only, *Banner Sends Consent Mode Signals*. `head.phtml` pushes `consent default` (all denied, `wait_for_update` 500) and `consent update` before GTM loads; Advanced loads GTM at once. Server side: Measurement Protocol `consent` object (`ConsentModeSignals`, 4 tests) on purchases, copied to refunds/cancellations. 7 JS tests. Options documented in the README with the legal note on Advanced.
- **`select_item` + list attribution**: click on a product link in category / search lists pushes `select_item`; the list (id, name, index) is remembered in localStorage (7 days, 100 products) and added to `add_to_cart` and client `purchase` items; the list's add-to-cart button only remembers. 4 JS tests. Server-side `purchase_server` does not carry list fields (browser-only knowledge).
- **Promotions**: `view_promotion` (once, ≥ 50 % visible) / `select_promotion` from `data-gtm-promotion-*` attributes (WeltPixel's `data-track-promo-*` accepted). 3 JS tests.
- **`item_brand` / `affiliation`**: General settings *Brand Attribute* and *Affiliation*, on every item of every event (client and server). Listings never load products for the brand.
- **Debug logger**: *Allow dataLayer Debug Logging* + `?gtm_debug=1` / `?gtm_debug=0`, console only, FPC-safe. 2 JS tests.
- **GTM export**: new tags *GA4 Selectitem*, *GA4 Viewpromotion*, *GA4 Selectpromotion* with triggers and 6 variables; *GA4 Category* sends list id/name. **Bug fixed**: the *GA4-Ecommerce-SearchTerm* variable read `ecommerce.searchterm`, but the module pushes `search_term`, so GA4 search events had no term. Containers must be re-imported.
- Tests now 166 PHPUnit, 47 JS.

### Verified locally, run 12 (🟢/🔴, 2026-10-21): follow-ups, client events, exclusion rules, configurable product, coupons / discounts
Environment for this run: US store, Hyvä one-page checkout, two temporary sales rules (`GTM10` 10 % off, `GTM100` 100 % off with free shipping), a temporary customer account; all removed afterwards.

**Exclusion rules** (each with one order; the client `purchase` is never affected, only the server row)

| Rule | Result |
|---|---|
| Excluded payment method (`checkmo`) | 🟢 order 22: purchase row `skipped`, "excluded: payment method", no payload |
| Excluded order status (`pending`) | 🟢 order 23: `skipped`, "excluded: order status" |
| Zero-value order (coupon `GTM100`, payment method `free`; `checkmo` is refused at zero total) | 🟢 order 24: `skipped`, "excluded: zero value order". The client still sends a `purchase` with value 0, discount −32 |
| Admin-created order, *Track Admin-created Orders* = No (default) | 🟢 `skipped`, "excluded: admin-created order" (order created through Magento's admin order model) |
| Admin-created order, *Track Admin-created Orders* = Yes | 🟢 sent anonymously: random `client_id`, no `user_id`, no `session_id`, although the order belongs to a registered customer |

**Configurable product and coupon** (order 21: Chaz Kangeroo Hoodie, Black / XS, $52, coupon `GTM10` −$5.20, shipping $5, tax 3.86, total $55.66)

| Check | Result |
|---|---|
| `purchase_server` | 🟢 `item_id` `MH01` (parent mode), `item_variant` "Black, XS", `price` 52, `discount` 5.2 per unit (positive), `coupon` `GTM10`, value 55.66, tax 3.86, shipping 5, categories Men / Tops / Hoodies & Sweatshirts |
| Client `purchase` | 🟢 same value, tax, shipping, coupon and price; 🔴 differences listed under Open issues 5 to 8 |
| *Item ID* parent / child, *Tax in Totals* excluding, *Currency basis* base (built from real orders with the snapshot factory, no new orders) | 🟢 `MH01` vs `MH01-XS-Black`; value 55.66 → 51.80 and Italian order 102.12 → 83.70 (tax and shipping unchanged); Italian order in base currency: USD, value 111.00, tax 20.02, shipping 12.29, item 26.23 |
| Partial credit memo with the discount (adjustment fee 6) | 🟢 `refund_server` value 49.66, `partial`; 🔴 item_id is the child SKU (Open issue 7) |

**Follow-ups**
- 🟢 A refund created while its purchase is still `pending` is queued as `pending` (not skipped). With the purchase pushed an hour ahead (as if retrying) the refund was sent **first**, the purchase later: GA4 can receive `refund_server` before `purchase_server`. Behaviour as documented in the risks; both were sent once the purchase was due.
- 🟢 Refund with a coupon and with a partial memo: see above. Refund in EUR: run 11. A re-sent purchase with a partial memo: not run.

**Client events**

| Event | Result |
|---|---|
| `sign_up` | 🟢 once, `user_id` 2, `method` Magento; preceded by `login` (the new account is logged in automatically) |
| `login` | 🟢 once, `user_id` 2 |
| `logout` | 🟢 once, on the "signed out" page (cookie `gtm_logout_event` consumed); the GA4 request carries `ep.method=false` (a GTM tag parameter, the dataLayer event has an empty method) |
| `add_to_wishlist`, `remove_from_wishlist` | 🟢 USD 32, item `24-MB04` |
| `page_view` | 🟢 exactly one `page_view` and one `view_item_list` request per page load (category page); no duplicates |
| Logged-in order | 🟢 order 29: `user_id` "2" and the visitor's `_ga` client id in `purchase_server` |

**New findings (🔴, not fixed; they are about the same product being described differently by different events)**
5. Client `item_id` of a configurable product changes inside one visit: `add_to_cart`, `view_cart`, `begin_checkout` use the **child SKU** (`MH01-XS-Black`, the quote item's product SKU) but `purchase` and `purchase_server` use the **parent** (`MH01`). (runs 12 and 13) 🟢 configurable (`item_variant`, parent / child `item_id`), coupon (per-unit `discount`, `coupon`), bundle (one line, children folded), grouped (one line per child), downloadable, categories as path; differences from the client events are Open issues 5 to 12
6. Client `purchase` sends `discount` as a **negative** number at event level (−5.2); GA4 has no event-level `discount` and expects positive item-level discounts, which only the server event sends.
7. `refund_server` items use the credit memo item's SKU: the **child SKU** for configurables, while the purchase uses the parent (or the child, depending on *Item ID*), so GA4 cannot match the refunded item to the purchased one.
8. Client item data is poorer than the server's: no `item_variant`, and `item_category` is the first category found (`Hoodies & Sweatshirts`) where the server sends the path (`Men`, `Tops`, `Hoodies & Sweatshirts`).
9. 🟡 The client sends a `purchase` for zero-value orders (value 0) that the server excludes; decide whether that is wanted.

Method pitfalls found: a bare `QuoteManagement::submit()` does not dispatch `checkout_submit_all_after` (only `placeOrder()` and the admin order model do), so scripted orders must use the right path; the free payment method must be chosen at zero total.

### Verified locally, run 13 (🟢/🔴, 2026-10-21): no consent cookie, bundle / grouped / downloadable, multishipping, Stripe (embedded, 3D Secure, redirect)
Environment: US store, one-page checkout, Stripe in **test mode** with the keys already configured (Stripe's published test cards only), temporary downloadable product + whitelisted domain `example.com` + a temporary customer with two addresses; everything removed afterwards (`app/etc/env.php` byte-identical to the backup).

| Case | Result |
|---|---|
| **No consent cookie at all**, consent required (guest order 30) | 🟢 purchase row `skipped`, "tracking consent not given"; GTM not loaded, the client still pushes `purchase` to `dataLayer` (nothing leaves the browser) |
| **Bundle** (Sprite Yoga Companion Kit, 4 default options, $61) + **grouped** (2 of the 3 straps) in one order (31) | 🟢 values, tax and shipping match in client and server; the bundle is one line (children folded in), the grouped product becomes one line per child (14 × 1 and 17 × 2); server also sends categories. 🔴 `item_id` problems and a refund quantity bug, Open issues 10 to 12 |
| **Downloadable** (logged in, as guest checkout is disabled for downloadables) (order 32) | 🟢 no shipping step, `add_payment_info` only, client and server `purchase` agree (15 × 1, value 16.24), `user_id` in the server payload |
| **Multishipping** | 🟡 Hyvä has no multishipping templates: `/multishipping/checkout/` renders an empty page and the cart has no "multiple addresses" link, so the UI cannot be used. Through Magento's multishipping model (2 items, 2 addresses, flat rate each): 🟢 two orders (33, 34) and **one purchase row per order** with its own value, shipping, items and transaction id (the observer handles `orders`). 🔴 the module has no client-side code for the multishipping success page (no `purchase` there) |
| **Stripe, embedded card** (test card 4242, order 35) | 🟢 order `processing`, paid and invoiced; `add_payment_info` with `payment_type` `stripe_payments`; one client `purchase`; server row with `user_id` |
| **Stripe, 3D Secure test card, authentication failed** | 🟢 no order, no `purchase` event, no queue row; an error message only. `add_payment_info` had already fired when *Place Order* was pressed (identical repeats are dropped) |
| **Stripe, 3D Secure completed** (order 36) | 🟢 the order is only created after authentication; `processing`, paid; one client `purchase`, one server row |
| **Stripe Checkout (redirect) mode** (orders 37, 38) | 🔴 the order is created as `pending_payment` and the purchase is tracked immediately (client `purchase` on the success page, server row `pending`), although nothing has been paid. In this installation the automated flow did not leave the shop (no redirect to stripe.com was observed, the success page was shown), which looks like a Hyvä-Stripe compatibility matter, not the module; the module tracks whatever Magento reports as placed |
| Mitigation: *Excluded Order Statuses* = `pending_payment` | 🟢 server row `skipped` ("excluded: order status"); the client `purchase` cannot be excluded. A skipped row is never re-queued: re-sending the order is ignored ("skipped on purpose"), so an order that is paid later is **never tracked server-side** (limitation, Open issue 13) |
| Refund of order 31 (bundle + grouped), full credit memo | 🟢 value 135.68, `full`; 🔴 bundle line `quantity` 4 (purchase: 1) and the composed `item_id` (Open issue 11) |

Notes: the admin shows "Stripe webhooks could not be configured automatically … `bin/magento stripe:webhooks:configure`" (the Stripe module, not related to this module); the Stripe test page and error text follow the browser language (Italian here).

### Verified locally, run 14 (🟢/🔴, 2026-10-21): GTM container export of the new commits
Pulled `9a5e02a..df71204` (four cloud commits), 185 PHPUnit + 51 JS tests pass in the local PHP 8.5 / Node, both stores answer 200, `setup:db:status` up to date (no schema change). The container was exported with the module's own `Service\Export` (same code as the admin button) and imported into GTM by the user.

| Case | Result |
|---|---|
| Export of the new template (21 tags, 21 triggers, 22 variables) | 🟢 valid JSON; new tags *GA4 Addtocompare*, *Selectitem*, *Viewpromotion*, *Selectpromotion*; `Ga4 Viewcart/Updatecart/Removefromcart` renamed to `GA4 …` (merge-import can leave both spellings, see `google-setup.md`) |
| **GTM import of the first export** | 🔴 GTM refused it ("invalid format on line 10, unknown column name"): the GA4 Configuration tag (`gaawc`) used the Universal Analytics key `fieldName` in `fieldsToSet` |
| After changing the key to `name` in `etc/tags.json` | 🟢 imported by the user without error |
| Tags firing in GTM Preview / events in GA4 | 🧪 not checked yet |

Not run: any of the behaviour changes of the four commits (see "Needs local testing" items 17 to 19).

### Verified locally, run 15 (🟢/🔴, 2026-10-21): item data identical in every event (group 1 of the 2026-10-21 changes)
Environment: one-page checkout, guest, Check / Money order, temporary 10 % coupon `GTM10` (rule deleted afterwards), consent cookie set, GTM loaded. Products: Chaz Kangeroo Hoodie (configurable, Black / XS) and Sprite Yoga Companion Kit (dynamic bundle). Orders 000000039 (Item ID = Parent), 000000040 (Child, hoodie + bundle) and 2000000003 (Italian store, Parent, hoodie + bundle).

| Case | Result |
|---|---|
| Parent mode, US store, hoodie + coupon (order 39): `view_item`, `add_to_cart`, `view_cart`, `begin_checkout`, client `purchase`, `purchase_server` | 🟢 `item_id` `MH01` everywhere (previously the child SKU in cart events, Open issue 5); `item_variant` "Black, XS" and the category path `Men / Tops / Hoodies & Sweatshirts` identical on every event; per-item `discount` 5.2 (positive, per unit) on `begin_checkout`, client `purchase` and `purchase_server`; no event-level discount; `value` 55.66 on both |
| Child mode, US store, hoodie + bundle + coupon (order 40) | 🟢 `MH01-XS-Black` in `add_to_cart`, `view_cart`, `begin_checkout`, `purchase` and `purchase_server`; bundle `24-WG080` everywhere (before: the composed SKU in cart events and the server payload, Open issue 10); client and server items identical |
| Full credit memo of order 40 (Child) | 🟢 `refund_server` `MH01-XS-Black` + `24-WG080`, bundle `quantity` **1** (was 4, Open issues 7 and 11), `variant` and categories as in the purchase; refund items carry no `discount` (by design: value only) |
| Full credit memo of order 39 (Parent) | 🟢 `refund_server` `MH01`, `item_variant` "Black, XS" |
| Italian store (EUR, VAT included), order 2000000003 | 🟢 hoodie 39.21 EUR net (52 USD incl. VAT: 52 × 0.92 / 1.22) in every event, discount 3.92, client `purchase` = `purchase_server` = `refund_server` (value 104.41); 🔴 bundle price differs between events (Open issue 15) |
| Discounted bundle | 🟡 bundle line has no `discount` although the coupon applies to its children (Open issue 16) |
| Settings value used at event time | 🟢 the Item ID mode in force when the refund is created decides the refund `item_id`; an order placed in Parent mode and refunded after switching to Child would show the child SKU (not exercised, follows from the code) |

Cleanup: rule `GTM10` deleted, `item_id_mode` row removed (default Parent), `var/tmp_gtm` deleted. Left behind: orders 000000039, 000000040, 2000000003 with credit memos and their events in GA4 (`purchase_server`, `refund_server`).

### Verified locally, run 16 (🟢, 2026-10-21): grouped products and several quick cart events (group 2 of the 2026-10-21 changes)
Environment: US store, guest, consent cookie set, no orders placed (cart emptied afterwards). Set of Sprite Yoga Straps (grouped, children `24-WG085` $14, `24-WG086` $17, `24-WG087` $21), Bags category list.

| Case | Result |
|---|---|
| Grouped product page, children 6 ft × 2 and 8 ft × 1, real *Add to Cart* click | 🟢 one `add_to_cart`, two items (`24-WG085` price 14 qty 2, `24-WG086` price 17 qty 1), `value` 45, child SKUs and names, delivered in the page without a reload; the zero-quantity child is not included. (`view_item` of the group still uses the group SKU `24-WG085_Group`, price 14 = cheapest child) |
| Cart page after that | 🟢 `view_cart` with the same two child items, value 45; the `add_to_cart` is not replayed |
| Bags category: two *Add to Cart* clicks 300 ms apart (Joust Duffle Bag, Strive Shoulder Pack) | 🟢 one `add_to_cart` with both items, `value` 66 (before: only the last one arrived) |
| Minicart drawer: remove one item, wait 400 ms, remove a grouped child | 🟢 two separate `remove_from_cart` events (each was delivered before the next remove), the grouped child as `24-WG085` price 14 qty 2, value 28 |
| Minicart drawer: remove two items in the same tick | 🟢 merged into one `remove_from_cart` with both items, value 51 |
| Minicart opened with *view_cart When the Minicart Opens* = No (default) | 🟢 no `view_cart` |
| Wishlist events merged | 🧪 not run (needs a logged-in customer) |

Cleanup: cart emptied, tab closed, nothing else created.

### Verified locally, run 17 (🟢, 2026-10-21): zero-value orders (group 4 of the 2026-10-21 changes)
Environment: US store, one-page checkout, guest, temporary 100 % coupon `GTM100` with free shipping (rule deleted afterwards), hoodie (configurable), payment *No Payment Information Required* (`free`). No `exclude_zero_value` row existed in `core_config_data` before (default Yes), neither under the old nor the new path.

| Case | Result |
|---|---|
| *Exclude Zero-value Orders* = default (Yes), order 000000041 | 🟢 server row `skipped`, "excluded: zero value order", no payload; success page has the page-context push (`page_type` `success`) but **no `purchase`** and no other event; `begin_checkout` before it carried value 52 and a per-item discount of 52 |
| Setting = No (`googletagmanager/general/exclude_zero_value` 0), order 000000042 | 🟢 client `purchase` with value 0, tax 0, shipping 0, coupon `GTM100`, item price 52, discount 52; server row `sent` (`purchase_server`, same items, value 0) |
| Setting saved under the old path (`server_side/exclude_zero_value`) is not migrated | not applicable here (no old row existed), behaviour documented only |
| Setting visible in the admin *General* group | 🧪 not looked at in the admin form (set with `config:set`) |

Trap: clicking *Place Order* less than about 3 s after choosing the payment method is ignored while Hyvä shows "Saving method"; no order is created. Click it again.

Cleanup: rule `GTM100` deleted, `exclude_zero_value` row removed (default Yes), `var/tmp_gtm` deleted, tab closed. Left behind: orders 000000041 and 000000042 (grand total 0) and their rows.

### Verified locally, run 18 (🟢, 2026-10-21): excluded status = hold, release and follow-ups (group 3 of the 2026-10-21 changes)
Environment: US store, *Exclude Order Statuses* = `pending` (Check / Money order starts as `pending`), orders 000000043 (browser checkout, hoodie) and 000000044 to 000000046 (placed by script through `CartManagementInterface::placeOrder`, Strive Shoulder Pack, no cookies so anonymous client id). Real Stripe Checkout redirect **not** used (it needs a person on Stripe's page); `pending_payment` was simulated by setting the order state and status.

| Case | Result |
|---|---|
| Order placed with its status excluded (43) | 🟢 purchase row `waiting` ("order status "pending" is excluded, sent once the order reaches another status"), payload already built with the visitor's `_ga` client id and session id, cron does not send it; the client `purchase` still fires on the success page (by design) |
| Order moved to `processing` (43) | 🟢 same save: row `pending`, "released: order status changed to "processing"", `timestamp_micros` dropped; cron sent it (GA4 dates it at sending time) |
| Waiting order cancelled (44) | 🟢 `cancel_server` row `skipped` ("server-side purchase was not sent (waiting)"); the purchase row stays `waiting` (never sent, never released) |
| Waiting order invoiced while `processing` is also excluded (45), then a partial credit memo (shipping 5) | 🟢 purchase stays `waiting`, `refund_server` row `skipped` (reference id = credit memo) |
| Same order after allowing `processing` (re-save with a real change) | 🟢 purchase released (`pending`, no timestamp) **and** the skipped refund re-queued as a new `pending` row (same reference id, old skipped row replaced); cron sent both |
| Status not excluded but state `pending_payment` (46) | 🟢 stays `waiting` (state check); then `processing` releases it |
| Real Stripe Checkout redirect (`stripe_payments_checkout`) with a person paying | 🧪 not run |
| A waiting row of a cancelled order | 🟡 stays `waiting` forever: the cleanup cron only deletes `sent` / `skipped` rows, so such rows accumulate (decision: expire them with the other rows, or leave them as evidence) |

Traps: `Order::addCommentToStatusHistory()` + `save()` does not fire `sales_order_save_after` (nothing changed on the order itself), change a field such as `customer_note` to trigger the release; an invoice created by script needs `$invoice->getOrder()->setIsInProcess(true)` before the transaction save, otherwise the order stays `new` / `pending` and nothing is released (the admin controller does this).

Cleanup: `excluded_statuses` back to `NULL` (row kept as before), `var/tmp_gtm` deleted, tab closed. Left behind: orders 000000043 to 000000046 (44 cancelled, 45 with a shipping-only credit memo), a `waiting` row of order 000000044, and their GA4 events (`purchase_server`, `refund_server`).

### Verified locally, run 19 (🟢, 2026-10-21): Google Consent Mode v2 (group 5 of the 2026-10-21 changes)
Environment: US store, category page `/gear/bags.html` with the debug logger on (`?gtm_debug=1`), the container re-imported by the user (GA4 page_view carries the new fields). Browser requests read with the network tool (`gcs`, `npa`, `cid` of `g/collect`); GA4 answers 503 in this browser as before, the request itself is what counts. Server side: orders placed by script with `$_COOKIE` set, so the consent evaluation sees a consented visitor.

| Case | Result |
|---|---|
| Consent Mode = Off (default) | 🟢 no `consent` commands; the page-context push is first; GTM after consent as before |
| Basic, consent cookie present | 🟢 dataLayer order: `consent default` (all four denied, `wait_for_update` 500), page context, `consent update` (all four granted), `gtm.js`; `gtm.js` request and `g/collect` with `gcs=G111`, `npa=0`, real `_ga` client id; `ep.page_type=category`, `up.customer_group`, `up.logged_in` on the `page_view` (new container) |
| Basic, no cookie | 🟢 only `consent default` and the page context, **no** `gtm.js`, no Google request |
| Advanced, no cookie | 🟢 `gtm.js` loads at once after `consent default`; `g/collect` is a cookieless ping: `gcs=G100`, `npa=1`, `pscdl=denied`, a throw-away client id, `page_view` and `view_item_list` |
| Advanced, then accept (cookie set + `user-allowed-save-cookie` event) | 🟢 `consent update` (all granted) pushed in the same page; next load `gcs=G111` with the stored client id |
| Signals Granted = Analytics only | 🟢 update: `analytics_storage` granted, the other three denied; `g/collect` `gcs=G101`, `npa=1` |
| Banner Sends Consent Mode Signals = Yes | 🟢 no `consent` commands from the module, `gtm.js` still after consent |
| Server `purchase_server`, Basic + cookie + all signals (order 47) | 🟢 `consent {ad_user_data: GRANTED, ad_personalization: GRANTED}`, `client_id` and `session_id` from the `_ga` cookies |
| Analytics only + cookie (48) | 🟢 both `DENIED` |
| Analytics only, no cookie (49, anonymous send) | 🟢 both `DENIED`, anonymous client id, no session id |
| Consent Mode Off + cookie (50) | 🟢 no `consent` object |
| `refund_server` of orders 47 and 48 | 🟢 copies the purchase's consent object (GRANTED / DENIED) |
| GA4 side (Consent Mode status in the property, modelled data) | 👤 not checked (needs a person and days of data) |

### Verified locally, run 20 (🟢, 2026-10-21): select_item and list attribution, promotions, brand / affiliation, debug logger (group 6 of the 2026-10-21 changes)
Environment: US store, Consent Mode Off, *Brand Attribute* = `material` (multiselect, 24-MB04 has four values), *Affiliation* = "Test store", temporary CMS page `/gtm-promo-test` with two promotion links (deleted afterwards), order 000000051 (Joust Duffle Bag + Strive Shoulder Pack).

| Case | Result |
|---|---|
| Click on a product link in the Bags list | 🟢 `select_item` with `item_list_id` `category_4`, `item_list_name` "Bags", `index` 0 (event and item); the list is stored in `localStorage` (`mageos_gtm_item_lists`, per SKU) |
| `add_to_cart` on the product page afterwards | 🟢 item carries `item_list_id`, `item_list_name`, `index` |
| *Add to Cart* button in the list (group 2 run) | 🟢 no `select_item`, the list is remembered (carried into the order below) |
| Client `purchase` (order 51) | 🟢 both items carry the list fields; `begin_checkout` and `view_cart` do not (by design) |
| `purchase_server` (order 51) | 🟢 no list fields (browser-only knowledge, documented) |
| `item_brand` / `affiliation` | 🟢 `affiliation` "Test store" on every item of every event (listing, `select_item`, cart, checkout, purchase, server); `item_brand` "Canvas, Cotton, Mesh, Polyester" (option labels joined with a comma) on `view_item`, `begin_checkout`, `purchase` and `purchase_server` of the product that has the attribute; not on list events (an attribute that is not *Used in Product Listing* is never loaded for lists, as documented) |
| Promotions: `data-gtm-promotion-id` / `-name` / `data-gtm-creative-name` / `-slot` / `-items` | 🟢 `view_promotion` once per element when visible (not repeated after scrolling), `select_promotion` on click, with `creative_name`, `creative_slot` and `items` [{item_id}] |
| Promotions: WeltPixel attributes (`data-track-promo-id`, `-name`, `-creative`, `-position`) | 🟢 same events |
| Debug logger | 🟢 with the setting on, `?gtm_debug=1` logs every push to the console (consent commands labelled "consent default / update", "ecommerce reset"); `?gtm_debug=0` clears the flag; with the setting off the script is absent from the HTML (checked with `curl`) |
| GTM Preview / GA4: new tags fire, promotions and item lists in the reports | 👤 not checked |

Findings: client `purchase` without a coupon has `"coupon": null` (key present with null; the server payload leaves the key out), harmless for GA4 but not identical (🟡; ✅ fixed 2026-10-21, the key is left out, 🧪 verify). `view_item_list` and other pushes also reach the dataLayer without consent (nothing reads them before GTM loads; the Basic mode intentionally keeps GTM unloaded).

Cleanup: CMS page and its URL rewrite deleted, config rows for `consent_mode`, `granted_signals`, `cmp_managed`, `debug_logger`, `brand_attribute`, `affiliation` removed (defaults back), `var/tmp_gtm` deleted, tab closed; localStorage flag `mageos_gtm_debug` cleared. Left behind: orders 000000047 to 000000051 (47 and 48 with credit memos) and their events in GA4.

### Verified locally, run 21 (🟢, 2026-10-21): page context, customer groups, limits, compare, minicart, categories, custom parameters, custom GTM URL (group 7 of the 2026-10-21 changes)
Environment: US store, two temporary customers (General, Wholesale; deleted afterwards), the user's published container (GA4 page_view carries the context fields), orders 000000052 to 000000056. For the custom URL a temporary `pub/gtm.js` stand-in served by the other store's host (`ita.magento249.test`) that loads the real `gtm.js` (deleted afterwards).

| Case | Result |
|---|---|
| Page context on every page type (guest, fetched HTML) | 🟢 `page_type` home, category, product, search, cart, account (login), cms, 404; checkout and success seen in the browser; first push before GTM |
| Logged in (General, Wholesale) | 🟢 `customer_group` "General" / "Wholesale", `logged_in` "yes" |
| FPC with groups | 🟢 category page: guest HIT with NOT LOGGED IN, General own entry (MISS then HIT), Wholesale own entry, then guest HIT again with the right group |
| *Don't Track Customer Groups* = NOT LOGGED IN | 🟢 guest HTML (home, category, cart) has no GTM code and no page context; a Wholesale visitor is tracked; guest order 000000052 `skipped` "excluded: customer group" |
| Same with Wholesale excluded | 🟢 the logged-in Wholesale page has no GTM / context, a guest page still does |
| *Maximum Transaction Value* = 50 | 🟢 guest order of 79.28 (054) and a Wholesale browser order of 84 (055): server rows `skipped` "excluded: above the maximum transaction value", **no client `purchase`** on the success page; an order of 39.64 (053) is sent |
| `add_to_compare` | 🟢 event with the item after *Add to Compare* (delivered after the page reload) |
| *view_cart When the Minicart Opens* = Yes | 🟢 every opening of the drawer pushes `view_cart` with the cart; an empty cart pushes nothing; drawer *remove* delivers `remove_from_cart` |
| Drawer quantity update | not applicable: Hyvä's drawer has no quantity control (edit pencil and remove only), so `update_cart` from the drawer cannot be triggered |
| *Category Depth* = 2 | 🟢 only `item_category` and `item_category2` on view_item, cart and compare items |
| *Excluded Categories* = 4 (Bags) | 🟢 Strive Shoulder Pack: `item_category` "Gear" only, the Bags level is gone |
| *Custom Item Parameters* (`item_material:material`, `item_activity:activity`) | 🟢 on `view_item`, `view_cart`, and in `purchase_server` of order 056 (labels joined with commas); the configurable hoodie carries the parent's `item_material` "Wool" |
| Custom parameter from a variant attribute (`item_color:color`) | 🔴 not sent for the hoodie (Open issue 17) |
| *Custom GTM Script URL* = `https://ita.magento249.test` (store scope) | 🟢 `gtm.js` requested from that host first, then the stand-in loads Google's; container ready, events normal |
| CSP of that URL | 🟢 host in `script-src`, `connect-src`, `img-src`, `frame-src` of the store that has the setting, not in the other store's headers; on the checkout (enforced CSP) the script loads without a violation, while an unlisted host is blocked (`script-src-elem` violation) |
| Value handling | 🟢 `gtm.example.com` (no scheme) falls back to Google and adds nothing to the CSP; trailing slash and spaces are trimmed; 🟡 a path is kept and `http://…` falls back but still adds its host to the CSP (Open issue 18) |
| Noscript iframe (*Require Consent* = No) | 🟢 `https://<custom host>/ns.html?id=GTM-…`; empty setting back to `https://www.googletagmanager.com/ns.html` |
| A real first-party / server-side GTM container, GA4 data through it, ad-blocker bypass | 👤 not testable here |

Cleanup: both test customers deleted (order 055 stays as a guest-order record), config rows removed (`gtm_script_url` store scope, `consent/required`, `view_cart_on_minicart`, `category_depth`, `custom_item_attributes`, `excluded_categories`, `max_transaction_value`, `excluded_customer_groups`), `pub/gtm.js` and `pub/ns.html` deleted, `var/tmp_gtm` deleted, tab closed. Left behind: orders 000000052 to 000000056 and their events in GA4.

### Local test coverage: tested vs still missing (kept up to date, last: 2026-10-21 after run 21)
**Tested locally on Magento 2.4.9 + Hyvä + Hyvä Checkout (guest orders unless noted):**
- Install / update / uninstall of the consent stub, `setup:upgrade`, `setup:di:compile`, DB table + FK, 139 PHPUnit + 30 JS tests
- Admin config rendering, ID validation both ways, encrypted API secret, container export generated
- Consent: console cookie (accept / decline / none), fallback event, Hyvä built-in banner, *Require Consent* = No, event-only (Send and Skip), custom cookie name, Cookiebot-style accepted value, server side for each
- Client events: `view_item_list`, `view_item`, `search`, `add_to_cart` (no replay), `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`; default, mobile, one-page and virtual-only checkouts; CSP console check on checkout (enforced CSP, Alpine CSP build)
- Server side: purchase queue, cron send, `_ga` client/session ids, consent skip, anonymous send, full and two partial refunds, re-saving a memo (dedupe and recovery of a lost row), refund / cancel after an expired, a skipped and a failed purchase, cancellation of a sent purchase, the full default retry schedule (8 attempts), expiry (> 71 h) and mass re-send of an expired order, Debug Mode, retries / failed with blocked network, wrong secret, cleanup cron
- Hyvä one-page checkout (also used for the run 6 orders), default, mobile and virtual-only checkouts
- Multi-store environment: second website (Italy, EUR, 22 % VAT included, `it_IT`) selected by host, US tax rates, tax and currency math, one Italian order end to end (client and server events, consent per website); client-side item prices in USD, EUR-on-Italy (VAT included) and EUR-on-US (run 9, after the fix)
- Run 12: exclusion rules (payment method, status, zero value, admin orders No and Yes), configurable product with coupon (server payload, *Item ID* modes, *Tax in Totals* excluding and *Currency basis* base through real orders), partial refund with discount, refund queued while the purchase is pending, logged-in order (`user_id`), `sign_up`, `login`, `logout`, wishlist add / remove, one `page_view` per page
- Run 13: order without a consent cookie, bundle + grouped + downloadable products (orders, refund), multishipping through the multishipping model (one row per order), Stripe embedded card, 3D Secure (failed and completed) and Stripe Checkout redirect mode with the `pending_payment` exclusion
- Run 15: the 2026-10-21 item description (Item ID Parent and Child, variant, category path, per-item discount, bundle SKU and refund quantity) across all client events, `purchase_server` and `refund_server` on the US and the Italian store, with a coupon
- Run 16: grouped product add to cart (one item per child), two quick list adds merged, quick drawer removals merged, grouped child in `view_cart` and `remove_from_cart`
- Run 17: zero-value orders with *Exclude Zero-value Orders* Yes (client `purchase` suppressed, server `skipped`) and No (both sent)
- Run 18: held purchases (`waiting`), release on a status change, cancel and refund while waiting, `pending_payment` state guard (simulated)
- Run 19: Consent Mode v2 Off / Basic / Advanced, analytics-only signals, banner-managed mode, `gcs` / `npa` on the GA4 requests, server `consent` object on purchases and refunds
- Run 20: `select_item` and list attribution, promotions (ours and WeltPixel attributes), `item_brand` / `affiliation`, debug logger
- Run 21: page context (all page types, logged in), customer groups with FPC, customer-group and maximum-value exclusions (client and server), compare, minicart `view_cart`, category depth / exclusions, custom item parameters, custom GTM URL with CSP (stand-in host)
- Admin: order grid column, event log grid (filters, sort, order link), ACL for two roles, FPC (MISS/HIT, sort, pagination)

**Still missing (🧪 unless marked 👤):**
- Multi-currency / multi-tax (Italian store exists): the US store with EUR display and a real order (only the events were checked), per-website module settings, the settings *Currency basis* base / *Tax in Totals* excluding / *Item ID* child end to end in a real order (verified on existing orders through the snapshot factory only)
- Orders: other redirect payment methods (Mollie, Braintree / PayPal are installed, not configured; Stripe's hosted page was not completed because card data is not typed on external pages), a downloadable refund, bundle with a non-default selection and fixed-price bundles, Stripe Checkout redirect completed by a person (does the order become paid and tracked once)
- Follow-ups: a re-sent purchase combined with a partial memo, refunds of a configurable in the Italian store, a second partial memo on a discounted order
- Client events not yet exercised: `update_cart` for a configurable, `remove_from_cart` for a configurable, events on mobile / Safari
- Google side (👤 a person): `purchase_server`/`refund_server`/`cancel_server` and client events in GA4 Realtime/DebugView, GTM container import (Merge) acceptance, GTM Preview, whether server and client `transaction_id`s line up in an Exploration, the 503 seen on `collect` requests in the test browser
- Platform: Varnish, logged-in customer group prices with FPC, multi-website / multi-store, real `MageOS_CookieConsent` (does not exist yet), user-agent based switch to the mobile checkout, upgrade from the earlier table version (item 16), production mode (`di:compile` + static content deploy)
- Tooling: `dev/check.sh` inside the Warden container (`php -l` segfault), `dev/check-magento.sh` (PHPStan/XSD against the Magento sources)
- Docs acceptance by someone new to GA4/GTM (👤), legal sign-off on the anonymous-send default (👤)

## Needs local testing (🧪)
Everything under "Magento layer" above, in particular the risky items:
1. `setup:upgrade` creates the table; the hand-written `db_schema_whitelist.json` is accepted (else regenerate it); FK to `sales_order` OK. 🟢 (2026-10-20)
2. `setup:di:compile` passes (constructor types, nullable defaults). 🟢 (2026-10-20)
3. Admin config renders; saving a swapped ID shows the validation error; API secret is stored encrypted. 🟢 (2026-10-20)
4. Observer fires for: guest order, logged-in order, admin-created order, multishipping, order placed via payment redirect/callback. Check `$order->getEntityId()` is set at `checkout_submit_all_after`. 🟢 guest order only (observer fires, order id set); logged-in, admin, multishipping and redirect payments still open (runs 12 and 13) 🟢 now also logged-in customer (`user_id` in the payload), admin-created order (excluded by default; sent anonymously with *Track Admin-created Orders*), multishipping through the multishipping model (one row per order), Stripe embedded card and 3D Secure; redirect payment (Stripe Checkout) places the order as `pending_payment` and is tracked at once (Open issue 13)
5. `OrderSnapshotFactory`: values/currency match the client-side `purchase` for the same order; configurable products show one item with `item_variant`; categories fill `item_category..5`; discount per unit. 🟢 guest order, simple products only; configurable/coupon/discount still open
6. Configurable consent (2026-10-20, replaces the `MageOS_CookieConsent` dependency): defaults with the console cookie / Hyvä built-in banner, decline, no cookie, fallback event, custom cookie name, "accepted" value (Cookiebot style), *Require Consent* = No, event-only setup on the server side; session cookie name `PHPSESSID`. Browser gate and server must agree (`local-testing.md`, "Simulating cookie consent") 🟢 (run 2) tested without any consent module: defaults with a console cookie, decline, no cookie, fallback event (also after a decline: GTM stays off), and Hyvä's built-in banner (Cookie Restriction Mode on, "Allow Cookies" loads GTM on that page via the event and on the next page via the cookie; server rows use the same rules: accepted = real `_ga` client id, declined = `skipped`). (run 4) *Require Consent* = No, event-only setup (empty cookie name, both *When Consent Cannot Be Determined* actions), custom cookie name and a Cookiebot-style accepted value `statistics:true` behave as documented in the browser and on real orders, see "Verified locally, run 4". Still open: a real order with no consent cookie at all while consent is required (expected `skipped`)
7. `_ga` / `_ga_<ID>` parsing against real cookies (both `GS1` and `GS2` formats), and that the server event joins the browser session in GA4. 🟡 `client_id` and `session_id` were taken from the real `_ga` / `_ga_<ID>` cookies of a guest order; which GS format Google sent and joining the browser session in GA4 are still to check
8. Cron picks up pending rows; retry/backoff against a blocked network; permanent-error path with a wrong API secret. 🟢 cron sends once the `curl_close()` bug was fixed; retry/backoff with a blocked network and the wrong-secret path still open (run 5) 🟢 retry/backoff and `failed` with a blocked network, and the wrong-secret path (accepted with 2xx, not detectable even in Debug Mode) (run 7) 🟢 the full default schedule with 8 attempts measured: 60, 120, 240, 480, 960, 1920, 3840 s, then `failed`
9. Debug mode: validation messages are logged to `var/log`. 🟢 (run 2) log shows "Payload valid (debug endpoint, event not recorded)"; no GA4 check needed
10. `purchase_server` visible in GA4 Realtime / DebugView with correct value, currency, items. 🟢 queue side (row reaches `sent`); GA4 Realtime/DebugView check still needs a person
11. Hyvä Checkout shipping/payment events (`local-testing.md` section 5): once per submission, right method codes, default/mobile/onepage checkouts, virtual cart. 🟢 default, mobile (`add_payment_info` fires when leaving the payment step), one-page (both at Place Order) and a virtual-only cart (only `add_payment_info`), guest orders, each event once with the right method code. Still open: the user agent switch to the mobile checkout, logged-in customers, multiple addresses (runs 12 and 13) 🟢 also logged-in customers and downloadable / Stripe orders; user agent switch to the mobile checkout still open
12. Client-side fixes: flat `items`, purchase currency, single `page_view`, custom-event triggers after re-importing the container, `view_item` / `view_item_list` / `search` on product, category and search pages (also with FPC/Varnish on, second visitor gets the same events). 🟡 partly: `view_item_list`, `view_item`, `view_cart`, `begin_checkout` seen in `dataLayer`; GTM Preview, search page, FPC/Varnish still open (run 5) 🟢 search page (`search` + `view_item_list`), FPC MISS/HIT with identical events, sort and pagination; still open: Varnish, GTM Preview, logged-in customer group prices with FPC (runs 12 and 13) 🟢 `sign_up`, `login`, `logout`, wishlist add / remove, exactly one `page_view` per page; still open: Varnish, GTM Preview, configurable `update_cart`
13. Refunds (full and partial credit memos, re-saving a credit memo does not duplicate) and cancellations; skipped when the purchase was skipped/failed/expired. 🟡 (run 2) partial credit memo (adjustment fee) → one `refund_server` row, value 112, `refund_type` partial, same `client_id`, sent by cron; cancelling an order whose purchase was skipped → `cancel` row skipped with the reason. Bug found and fixed (full credit memo never queued). Still open: full credit memo after the fix, two partial memos, re-saving a memo, refund after a failed/expired purchase, cancel of a sent order (run 6) 🟢 full credit memo, two partial memos, re-save (dedupe and recovery of a lost row), refund after an expired purchase (skipped) and cancel of a sent order; still open: refund after a *failed* purchase (run 7) 🟢 refund and cancel after a *failed* purchase are `skipped`; a skipped follow-up is not re-queued when the purchase is re-sent (runs 6, 7, 11, 12, 13) 🟢 full, two partial, partial with a discount, bundle + grouped refund, re-save, refund / cancel after skipped / failed / expired purchases, refund while the purchase is pending
14. Order grid column renders, filters and sorts without SQL errors (ambiguous columns), also combined with the grid's own status/date filters; mass action counts and messages; re-sent expired orders arrive in GA4 dated at re-send time. 🟢 column renders and shows "Sent"; filter/sort combinations and mass action still open 🟢 (run 2) mass re-send: confirmation text, 2 queued (anonymous client ids, no timestamp) and 1 not queued with the reason ("skipped on purpose"); the 2 queued orders were sent by cron
15. Event log page (Reports > Marketing > GA4 Server-side Events): grid renders with spacing below the intro text, one row per event, order # links to the order, filters by date/status/event and sorting work without "ambiguous column" errors, ACL; cleanup cron deletes only old sent/skipped rows; *Show in GA4 DebugView* makes server events appear in DebugView. The earlier summary report was verified 🟢 but has been replaced (2026-10-20), so the new grid needs a fresh check 🟢 (run 2) event log renders with intro, 7 rows, filters by Order # work, sorting by order date; order # links to the order (was plain text, fixed). Still open: date/status/event/error filters, ACL, cleanup cron (run 5) 🟢 ACL (menu, mass action denied, config section scoped) and cleanup cron (only old sent/skipped rows, retention 0 keeps all); direct URL / export download as a restricted user not exercised
16. `setup:upgrade` on an install that already had the earlier table (unique key change, new column) as well as on a clean one.
17. 2026-10-21 changes (`local-testing.md` sections 4 and 7b): grouped `add_to_cart` with one item per child; two quick AJAX adds from a category page before the next page load arrive as one `add_to_cart` with both items and the summed value; same `item_id` / `item_variant` / category path / per-item discount in every client event, `purchase_server` and `refund_server` (configurable in Parent and Child mode, bundle, coupon); bundle refund quantity; `pending_payment` excluded + Stripe Checkout (or an invoice on a held order) → `waiting` → released → sent, cancelled waiting order never sent, refund while waiting re-queued on release; zero-value order without client `purchase`; *Exclude Zero-value Orders* now in the General group (re-save it if it was changed before). 🟢 (run 15) same item description in every event, configurable Parent and Child, bundle, coupon, refund quantity, Italian store; 🟢 (run 16) grouped add_to_cart per child, two quick adds merged, quick removals merged; 🟢 (run 17) zero-value order, both setting values; 🟢 (run 18) waiting purchases: hold, release, cancel, refund while waiting, `pending_payment` guard (simulated); still open: real Stripe Checkout redirect, wishlist merge. 🟡 `waiting` rows of cancelled orders are never cleaned up. 🔴 Open issues 15 (bundle price gross vs net) and 16 (bundle discount)
18. Competitor-parity features (`local-testing.md` section 7c): Consent Mode Off / Basic / Advanced in the browser (debug logger shows the commands, GA4 `gcs` parameter) and in the server payload, granted signals, banner-managed mode; `select_item` and list fields on `add_to_cart` / `purchase`; promotions from a CMS block; `item_brand` / `affiliation`; re-imported container with the new tags and the fixed search term. 🟢 (runs 19 and 20) all browser and server cases; still open: GTM Preview of the new tags, GA4 side (a person), `view_item_list` list fields in GA4 reports
19. Second competitor batch (`local-testing.md` section 7d): page context and GA4 user properties, custom GTM URL with CSP, customer-group exclusion with FPC, maximum value, add_to_compare, minicart view_cart and drawer remove/update delivery, category depth / exclusions, custom item parameters. 🟢 (run 21) all cases except the variant-level custom parameter (Open issue 17) and the quantity update in the drawer (no such control in Hyvä); still open: GTM Preview, a real first-party / server-side GTM container (👤), a customer-group order through the logged-in checkout of the excluded group
20. Fixes of Open issues 15 to 18 and `coupon: null` (`local-testing.md` section 7e): bundle net price in a tax-inclusive store on view_item / listings / add_to_cart, bundle discount from its children (client and server), variant-level custom parameters and brand, Custom GTM Script URL validation on save and at runtime / CSP, no `coupon` key without a coupon. 🧪

## Needs user testing (👤)
- Docs acceptance: someone who never used GA4/GTM follows only `docs/google-setup.md` on the clean install and reaches a working state.
- Reporting decision in practice: check that `purchase_server` vs `purchase` counts for the same `transaction_id` are useful in a GA4 Exploration.
- Legal/privacy sign-off on the "send anonymously when consent is unknown" default (see Decisions).

## Not started (⏳)
- **Future improvement, noted by the user:** an admin-configured *reporting currency* for purchase events (e.g. an EU+US store that wants everything tracked in EUR instead of EUR + USD). Today the order's currency (or the store base currency) is used. Needs a conversion step (Magento currency rates or a fixed rate) for value/tax/shipping/item prices/discounts, and a decision whether the client-side events follow the same rule. Replaces the earlier "fixed conversion rate" idea.
- Event log: CSV export, store-view filter, per-row detail view of the payload.

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
| Multishipping | Not supported or tested; documented, no work planned | 2026-10-21 |
| Excluded order status | Hold the purchase (`waiting`) and send it once the order reaches an allowed status | 2026-10-21 |
| Zero-value orders | *Exclude Zero-value Orders* applies to the client `purchase` too | 2026-10-21 |
| Client item data | Client items match the server (item_id rule, variant, category path, per-item discount) | 2026-10-21 |
| Competitor parity | Implement Consent Mode v2 (Off/Basic/Advanced), select_item + list attribution, promotions, brand/affiliation, debug logger | 2026-10-21 |
| Second competitor batch | Custom GTM URL, page/visitor context, customer-group exclusion, max transaction value, add_to_compare, minicart view_cart, category depth / exclusions, custom item parameters | 2026-10-21 |
| Admin report | Simple event log grid (order date, order #, event, status, error) instead of the per-day summary; intro text spaced from the grid | 2026-10-20 |

## Known risks / notes
- GA4 rejects Measurement Protocol events whose `timestamp_micros` is older than ~72 h. Such rows are closed as `expired` (never sent automatically). With the defaults (8 attempts, delays 1, 2, 4 … 64 min, about 2 h total) this only happens after cron was down for ~3 days. The re-send action sends them again without the old timestamp (dated at re-send).
- `QueueProcessor` in debug mode closes rows as `sent` although Google records nothing; do not leave debug on.
- A refund or cancellation skipped because the purchase had not been sent (`failed`, `expired`, never recorded) is queued again when the purchase is re-sent from the order grid (run 10): the skipped row is deleted and the event rebuilt from the credit memos / canceled state. Follow-ups skipped for consent or an exclusion are not touched. A re-queued refund can reach Google before its purchase.
- Follow-ups for an order whose purchase is still `pending` can reach Google before the purchase (each row has its own retry timing). Harmless for comparison, noted.
- Payment-gateway flows that cancel an order right after placement produce both `purchase_server` and `cancel_server`. Expected.
- `view_item_list` on category pages reads the list block's collection; a theme that renames `category.products.list` / `search_result_list` gets no list event (🧪 Hyvä default theme uses these names, checked in the source).
- The client-side events for `add_shipping_info` / `add_payment_info` fire on Hyvä's validation success, before the server confirms a step change (rare false positive, see `hyva-checkout-notes.md`).
- **Unpaid orders on the client.** A redirect-based payment method that places the order as `pending_payment` (Stripe Checkout) gets the client `purchase` on the success page at once; this cannot wait for the payment. Server side: add `pending_payment` to *Exclude Order Statuses* and the purchase waits until the payment is confirmed (2026-10-21).
- One `item_id` per product across all events since 2026-10-21 (Open issues 5, 7, 10, 11), pending local verification.
- The customer-data event slot is single: two `add_to_cart` events before the next section load deliver only the last one; harmless in normal browsing (Open issue 12).
- Multishipping is not supported or tested (no Hyvä UI). If enabled anyway, the server tracks each resulting order separately; there are no client events.
- Zero-value orders: excluded on both sides by default (*Exclude Zero-value Orders*, General group).
