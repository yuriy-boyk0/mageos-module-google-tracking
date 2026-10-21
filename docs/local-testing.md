# Local testing guide (Magento 2.4.9 + Hyvä + Hyvä Checkout)

For a Claude Code session or a person with the real store. Cloud sessions cannot run any of this (see `CLAUDE.md`). Record results in `docs/STATUS.md` ("Needs local testing") as you go: tick items, note failures, and fix code or tell the user.

## 0. Prerequisites
- Clean Magento Open Source 2.4.9 with sample data, Hyvä theme + Hyvä Checkout active. No consent module is needed (the former `mage-os/module-cookie-consent` dependency was dropped, it does not exist yet); consent is simulated with a cookie, see "Simulating cookie consent" below.
- Developer or production mode; cron working (`bin/magento cron:run` twice schedules then executes jobs) or run it manually while testing.
- **Hyvä Checkout must be selected, not just installed.** The default of `hyva_themes_checkout/general/checkout` is the Luma fallback, so `/checkout/` shows "No Checkout module installed" until you run `bin/magento config:set hyva_themes_checkout/general/checkout default` (or pick *Hyvä Checkout* under Stores > Configuration > Hyvä Checkout) and flush the cache.
- If you installed the earlier consent **stub** package (first local run), remove it: `composer remove` it, `bin/magento module:uninstall` or disable `MageOS_CookieConsent`, then `setup:upgrade`.

### Simulating cookie consent
The module reads the consent from **Stores > Configuration > Mage-OS > Google Tag Manager > Cookie Consent** (defaults: cookie `user_allowed_save_cookie`, fallback event `user-allowed-save-cookie`). Without a real banner, set the cookie by hand in the browser console on the storefront (replace `1` with the website ID, `1` on a default install) and reload the page:
```js
// accept
document.cookie = 'user_allowed_save_cookie=' + encodeURIComponent('{"1":1}') + '; path=/';
// decline
document.cookie = 'user_allowed_save_cookie=' + encodeURIComponent('{"1":0}') + '; path=/';
// remove (= banner never answered)
document.cookie = 'user_allowed_save_cookie=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
// the Hyvä banner sets this cookie with a Domain attribute: remove that one too
document.cookie = 'user_allowed_save_cookie=; path=/; domain=' + location.hostname + '; expires=Thu, 01 Jan 1970 00:00:00 GMT';
// event fallback, without any cookie: GTM must load on this page view only
window.dispatchEvent(new CustomEvent('user-allowed-save-cookie'));
```
Expected: with "accept" `gtm.js?id=GTM-…` is requested on every page and server-side purchases carry the `_ga` client id; with "decline" or no cookie, no GTM and the purchase row is `skipped` ("tracking consent not given"). Alternatively enable *Stores > Configuration > General > Web > Default Cookie Settings > Cookie Restriction Mode* and use Hyvä's built-in banner, which writes the same cookie and fires the same event.

Also try a custom setup: *Consent Cookie Name* = `my_consent`, then `document.cookie = 'my_consent=true; path=/'`; and *Cookie Value Meaning "Accepted"* = `statistics:true` with `document.cookie = 'CookieConsent=' + encodeURIComponent("{necessary:true,statistics:true}") + '; path=/'` (cookie name `CookieConsent`).
- Keep the Hyvä Theme module's own **Google Tag Manager** section (`hyva_theme_google`) disabled; GTM would be loaded twice.
- Test in a browser **without an ad blocker** for the site (uBlock-style rules replace `google-analytics_analytics.js`; `gtm.js` then returns 200 but `window.google_tag_manager` stays empty).
- A new GA4 property + GTM container, created by following `docs/google-setup.md` (this doubles as the docs acceptance test). You need: GTM Container ID, GA4 Measurement ID, and a Measurement Protocol API secret of the **same data stream**.

## 0b. First thing to check: admin tab
The module creates the admin tab itself (`etc/adminhtml/system.xml`: tab id `mage_os`, label **Mage-OS**). Open Stores > Configuration and confirm **Mage-OS > Google Tag Manager** appears, also in developer mode. Future Mage-OS modules that should appear under the same tab must use the tab id `mage_os`.

## 1. Install the module
```bash
composer config repositories.mageos-gtm path /path/to/mageos-module-google-tracking
composer require mage-os/module-google-tag-manager:@dev
bin/magento module:enable MageOS_GoogleTagManager
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```
Expected: no errors. If `setup:upgrade` complains about the schema whitelist, regenerate it and commit the result:
```bash
bin/magento setup:db-declaration:generate-whitelist --module-name=MageOS_GoogleTagManager
```
Check: `DESCRIBE mageos_gtm_server_event;` and that the FK to `sales_order` exists.

Run the unit suites from a **separate clone** of the module repository (not inside the Magento tree, so `dev/vendor` is never scanned by `setup:di:compile`): `(cd dev && composer install) && dev/check.sh`.

**Warden (Docker) variant.** The container only sees the project directory, so clone the module into it (for example `packages/mageos-module-google-tracking`, on the branch under test) and use that relative path; Composer symlinks it, so later `git pull` / edits are live (then `bin/magento cache:flush`, plus `setup:upgrade` / `setup:di:compile` when DI, schema or plugins changed). Run everything inside the container, for example:
```bash
warden env exec -T php-fpm composer config repositories.mageos-gtm path packages/mageos-module-google-tracking
warden env exec -T php-fpm composer require mage-os/module-google-tag-manager:@dev
warden env exec -T php-fpm bin/magento module:enable MageOS_GoogleTagManager
warden env exec -T php-fpm bin/magento setup:upgrade
warden env exec -T php-fpm bin/magento setup:di:compile
```
Do **not** run `composer install` in `dev/` of that symlinked clone: `dev/vendor` would sit inside the Magento tree where `setup:di:compile` scans it. If you did, delete `dev/vendor`. Known tooling problem (PHP 8.5.9 Warden container): `php -l` crashes with a segfault under the default ini (it works with `php -n -l`), so `dev/check.sh` fails in its first step there; use a separate clone with the host PHP, or run `dev/vendor/bin/phpunit -c phpunit.xml.dist` and `node --test Test/js/` directly.

Admin URLs contain a secret key. Open Stores > Configuration through the menu (the "Mage-OS" section appears next to a second, unrelated "Google Tag Manager" entry under Hyvä Theme), or copy the link from the page; a hand-typed URL without the key just redirects to the dashboard.

## 2. Configure
Stores > Configuration > Mage-OS > Google Tag Manager.
- General: Enable = Yes, Google Tag ID = `GTM-…`, GA4 Measurement ID = `G-…`.
- Try pasting the `G-…` ID into Google Tag ID: saving must be rejected with a clear message (and vice versa).
- Server-side group: Enable, API Secret (check it is encrypted in `core_config_data`), leave Measurement ID empty to inherit, defaults otherwise.
- Export the container (Configuration Export), import it into GTM (Merge), publish. Confirm GTM accepts the file (the leftover `accountId`/`containerId` fields were removed).

## 3. Client-side events (GTM Preview + GA4 DebugView)
Give consent first (banner or the console cookie from "Simulating cookie consent"). Verify `page_view`, `add_to_cart`, `update_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `login`, `logout`, `sign_up`, `add_to_wishlist`.
- `page_view` must be counted **once** per page (possible duplicate from `head.phtml` `gtag('config')` + the GA4 Configuration tag).
- Place an order: exactly one `purchase`; reload the success page: none. Check that the idle `GA4 Addshippinginfo` / `GA4 Addpaymentinfo` tags do **not** fire on the success page any more.

## 4. Server-side purchase
```sql
SELECT entity_id, order_id, increment_id, status, attempts, next_attempt_at, last_error FROM mageos_gtm_server_event;
```
Scenarios (place each, run cron, query the table):
| Scenario | Expected |
|---|---|
| Guest order, consent accepted | row `pending` → `sent`; GA4 Realtime shows `purchase_server` with the same `transaction_id`, value, currency, tax, shipping, items |
| Logged-in order, consent accepted | as above, plus `user_id` in the payload (inspect payload column) |
| Consent declined / banner never accepted (console cookie `{"1":0}` or no cookie) | row `skipped`, `last_error` = "tracking consent not given" |
| *Require Consent* = No | GTM loads without any cookie; purchase uses the `_ga` client id |
| Event-only setup (*Consent Cookie Name* empty) | GTM loads after the event; purchase row uses the *When Consent Cannot Be Determined* action (default: sent anonymously) |
| Admin-created order | `skipped` while "Track Admin-created Orders" = No; with Yes → sent anonymously (default "send anonymously") |
| Configurable product with options and coupon | one item with `item_variant` (e.g. "Black, XS"), `discount`, `coupon` |
| Zero-value order (*Exclude Zero-value Orders* = Yes, General group) | server row `skipped` ("excluded: zero value order") **and no client `purchase`** on the success page |
| Excluded payment method | `skipped` with the matching reason |
| Excluded status, e.g. *Exclude Order Statuses* = `pending_payment` + a redirect payment (Stripe Checkout) or an order set to an excluded status | row `waiting` ("waiting: order status … is excluded"), payload already contains the visitor's `_ga` client id; after the payment is confirmed / an invoice moves the order to `processing` the row becomes `pending` ("released: order status changed to …") without `timestamp_micros`, and cron sends it. Cancel a waiting order instead: it stays `waiting` forever. A refund created while waiting is `skipped` and re-queued on release |
| Network blocked (e.g. block `www.google-analytics.com` in hosts/firewall) | checkout still completes at normal speed; row `pending` with growing `next_attempt_at`; after max attempts `failed` |
| Wrong API secret | the normal endpoint answers 2xx and the row becomes `sent`; Debug Mode does **not** catch it either (Google's validation endpoint does not check the secret). The only check is that the event shows up in GA4 Realtime / DebugView |
| Debug Mode on | `var/log/system.log` shows `[MageOS_GoogleTagManager]` validation result; nothing in GA4; turn off afterwards |
| Same order twice (re-trigger observer) | still one row (unique key) |
| Cron stopped for 3+ days, then started (simulate: set the row's `created_at` and `updated_at` to 80 h ago, then run the sender) | the age is measured from `created_at`; the row becomes `expired` ("unrecoverable: older than Google accepts (72 h) …"), payload kept, nothing sent. A credit memo for such an order is `skipped` ("purchase was not sent (expired)"); the mass re-send action then queues it again without a timestamp |
| Tax in Totals = Excluding / Item ID = Child | `value` = grand total − tax; item prices and shipping are **without tax in both modes** (the same lines as the client-side purchase) and `tax` is always the order tax; configurable `item_id` is the variant SKU. Compare with Including / Parent |
Also verify:
- The payload's `client_id` equals the browser's `_ga` cookie value (`GA1.1.<this part>`) and `session_id` the `_ga_<ID>` session, so the event lands in the visitor's session. Both cookie formats `GS1` and `GS2`.
- Browser gate and server agree: the same console cookie that loads GTM makes the purchase row use the visitor's `_ga` client id, and a declining cookie skips it. The session cookie name is assumed to be `PHPSESSID` (`Model/RequestContextProvider`).
- Values in the payload equal the client-side `purchase` for the same order (multi-currency store: switch display currency, compare).
- Order placed through a redirect payment method. Multishipping is **not supported or tested** (no Hyvä UI); do not spend test time on it.
- `bin/magento cron:run` logs one line per run with sent/retried/failed counts; a crash mid-run is recovered after 10 minutes (row stuck in `processing`).

## 5. Hyvä Checkout shipping/payment events
Implemented in `view/frontend/templates/gtm/checkout-steps.phtml` from the Hyvä Checkout 1.3.15 sources (see `docs/hyva-checkout-notes.md`); it has **not** been run in a browser. Verify with GTM Preview / DebugView and the console (`dataLayer`):
- Default checkout (login → shipping → payment): nothing is pushed when the shipping step loads with a preselected method; `add_shipping_info` (`shipping_tier` = e.g. `flatrate_flatrate`) is pushed once when you continue to the payment step; `add_payment_info` (`payment_type` = e.g. `checkmo`) once when you place the order.
- Change the shipping method before continuing: only the final method is pushed (or, if you go back and change it, a second push with the new value).
- Mobile checkout (steps shipping → payment → summary): `add_shipping_info` when leaving the shipping step, `add_payment_info` when leaving the payment step (*Proceed to Summary*), nothing new at *Place Order*. One-page checkout: both events once, at *Place Order*. To test without a phone, set *Hyvä Checkout > General > Checkout* to `mobile` / `onepage` (the same layouts Hyvä picks for mobile user agents), then back to `default`.
- Virtual-only cart (create a temporary virtual product): no shipping step, `begin_checkout` then `add_payment_info` only (no `add_shipping_info`), purchase `shipping` 0.
- Virtual-only cart: only `add_payment_info`.
- Failed validation (e.g. missing address): no push. Rejected navigation after a passing validation is a known edge (docs/hyva-checkout-notes.md).
- Confirm `window.dispatchEvent` logging still shows the events as documented, in case the installed Hyvä Checkout is newer than 1.3.15:
```js
(() => { const o = window.dispatchEvent.bind(window); window.dispatchEvent = e => { console.log('[evt]', e.type, e.detail); return o(e); }; })();
```
- Also check the same GA4 tags fire (`GA4 Addshippinginfo`, `GA4 Addpaymentinfo`) and the success page no longer triggers them.

## 6. Client-side fixes and catalog events
- Re-export and re-import the GTM container (Merge, overwrite conflicts) and publish.
- GTM Preview: every GA4 event tag fires on its *Custom Event* trigger, including events pushed after load (add to cart via AJAX, checkout steps). `page_view` once per page.
- Product page → `view_item`; category page → `view_item_list` with the products of the current page (change sort/page and check the items follow); search → `search` (`search_term`) and `view_item_list` (`search_results`).
- With full page cache on: second (logged-out) visitor to the same category gets the same events; logged-in customer group pricing shows its own prices.
- Cart / checkout / purchase: `items` is a flat list, quantities correct (purchase used to send 0), configurable products appear once with the price paid; purchase `currency` = order currency on a multi-currency store.

## 7. Refunds, cancellations, re-send, grid, report
| Scenario | Expected |
|---|---|
| Full credit memo on a sent order | row `refund` (reference = credit memo id) `pending` → `sent`; `refund_server` in GA4 with value, items, `refund_type=full` |
| Two partial credit memos | two refund rows, `refund_type=partial` |
| Re-save of an existing credit memo (API or code, with a changed field) | no new row (unique key); a memo whose event was lost gets its row. A comment in the admin is **not** a re-save: Magento saves only the comment and fires no save event (it also skips the event when nothing changed) |
| Credit memo / cancel for an order whose purchase was `skipped`, `failed` or `expired` | refund / cancel row `skipped`, `last_error` "skipped: server-side purchase was not sent (<status>)" ("no server-side purchase recorded" when there is no row). When the purchase is re-sent from the order grid, these skipped rows are deleted and the refund / cancel events are queued again (the message says how many) |
| Cancel an order (admin) | `cancel_server` with the order value; same `client_id` as the purchase payload |
| Order grid | column *GA4 Server-side Purchase* shows statuses; filter by it, combine with the grid's status/date filters and sort: no "ambiguous column" SQL error |
| Mass action on failed/expired orders | "queued" message; rows `pending` without `timestamp_micros`; sent by cron |
| Mass action on an order from before the feature was enabled | new row, anonymous client id, real order date kept if < ~70 h old |
| Mass action on sent / skipped orders | notice "not queued: already sent / skipped on purpose …" |
| Admin user without the new ACL resources | mass action and report hidden / denied |
| Reports > Marketing > GA4 Server-side Events | intro text with clear spacing above a standard admin grid; one row per event (order date, order # linking to the order, event, status, error/reason), newest first; filters (date range, status, event, order #, error text), sorting and paging work without SQL errors |
| *Show in GA4 DebugView* = Yes | server events visible in DebugView (and recorded) |
| Cleanup cron (`bin/magento cron:run`, or call `MageOS\GoogleTagManager\Cron\Cleanup`) | deletes only sent/skipped rows older than the retention; failed/expired kept |

## 7b. Same item description everywhere (2026-10-21)
For a configurable (e.g. Chaz Kangeroo Hoodie Black / XS) and a bundle (Sprite Yoga Companion Kit), check `item_id`, `item_variant`, `item_category…` and `discount` in `view_item`, `add_to_cart`, `view_cart`, `begin_checkout`, client `purchase`, `purchase_server` and `refund_server`:
- *Item ID* = Parent: `MH01` everywhere; = Child: `MH01-XS-Black` everywhere (also `add_to_cart` and the refund). `item_variant` "Black, XS" in cart, checkout and purchase events.
- Bundle: `24-WG080` everywhere (not the composed SKU); refund of one bundle: `quantity` 1.
- Category path (`item_category` = top level, `item_category2` …) identical on client and server.
- Coupon: client `purchase` has a positive per-item `discount`, no event-level `discount`.

### Grouped products and several adds
- Grouped product (e.g. Set of Sprite Yoga Straps): add two of the children with different quantities → one `add_to_cart` with two items (child SKUs `24-WG085`, `24-WG086`…, their own prices and quantities), `value` = sum.
- On a category page, add two different products with the AJAX "Add to Cart" buttons quickly, before the minicart refreshes → one `add_to_cart` with both items and the summed value (previously only the last one arrived).

## 7c. Consent Mode, select_item, promotions, brand, debug (2026-10-21)
Turn on *Allow dataLayer Debug Logging* and open the shop with `?gtm_debug=1`: the console then shows every push, including `consent default` / `consent update`.
| Scenario | Expected |
|---|---|
| Consent Mode = Off | no `consent` commands; GTM after consent (as before) |
| Basic, no consent | `consent default` (all four denied, `wait_for_update` 500), no `gtm.js` request |
| Basic, accept (console cookie or banner) | `consent update` (all granted) **before** `gtm.js`; GA4 requests carry `gcs=G111` |
| Advanced, no consent | `gtm.js` loads at once, `consent default` denied; GA4 requests are cookieless pings (`gcs=G100`, no `_ga` cookie set) |
| Advanced, then accept | `consent update` granted, `_ga` cookie appears, normal hits |
| Signals Granted = Analytics only | update: only `analytics_storage` granted |
| Banner Sends Consent Mode Signals = Yes | no module `consent` commands |
| Server side with Basic/Advanced | `purchase_server` payload has `consent: {ad_user_data, ad_personalization}` = GRANTED after accept (all signals), DENIED with Analytics only or anonymous sends; refunds copy it |
| Category page, click a product link | `select_item` with `item_list_id` `category_<id>`, `item_list_name`, `index`; then `add_to_cart` and `purchase` items carry the same list fields |
| Category page, *Add to Cart* button in the list | no `select_item`, but the `add_to_cart` item carries the list |
| CMS block with `<a data-gtm-promotion-id="T1" data-gtm-promotion-name="Test" href="#">` | `view_promotion` once when visible, `select_promotion` on click; GA4 *Promotions* reports after a day |
| Brand Attribute = `manufacturer` (set a value on a product; *Used in Product Listing* = Yes for listings), Affiliation = "Test store" | `item_brand` and `affiliation` on items of all events and in `purchase_server` |
| Re-imported container | new tags *GA4 Selectitem*, *GA4 Viewpromotion*, *GA4 Selectpromotion* fire in GTM Preview; *GA4 Search* now sends `search_term` |

## 7d. Page context, groups, limits, compare, minicart, categories, custom parameters (2026-10-21)
| Scenario | Expected |
|---|---|
| Any page (debug logger on) | first push (after a `consent default` if Consent Mode is on) is `{page_type, customer_group, logged_in}`; `page_type` matches the page (home, category, product, search, cart, checkout, success, account, cms, 404, other); logged in → `logged_in: 'yes'` and the group name |
| FPC: same category page as guest and as a logged-in customer of another group | each gets its own `customer_group` (separate cache entries) |
| Re-imported container, GTM Preview | GA4 Configuration tag sends `page_type` and user properties `customer_group`, `logged_in` (GA4 request: `ep.page_type`, `up.customer_group`) |
| *Custom GTM Script URL* = an address that serves gtm.js (e.g. a server-side GTM or a simple proxy) | `gtm.js` requested from that host; no CSP violation in the console (host added to script-src, connect-src, img-src, frame-src); empty = Google again |
| *Don't Track Customer Groups* = NOT LOGGED IN | guest pages have no GTM script, no head/body dataLayer code; a guest order is `skipped` ("excluded: customer group"); a logged-in General customer is tracked |
| *Maximum Transaction Value* = 50, order of 100 | no client `purchase` on the success page, server row `skipped` ("excluded: above the maximum transaction value") |
| Add to compare (product page / listing) | `add_to_compare` with the item; GTM tag *GA4 Addtocompare* fires |
| *view_cart When the Minicart Opens* = Yes | opening the cart drawer pushes `view_cart` with the current cart; after removing in the drawer `remove_from_cart` arrives and the next opening shows the new cart (the sidebar controllers refresh the `gtm` section); Hyvä's drawer has no quantity control, so a drawer `update_cart` cannot be tested (run 21) |
| *Category Depth* = 2 | items have only `item_category` and `item_category2` |
| *Excluded Categories* = id of a category the product is in (e.g. a "Sale" category) | that category never appears, the next level moves up |
| *Custom Item Parameters* = `item_material:material` (an attribute that is set on the product itself, e.g. Strive Shoulder Pack) | `item_material` with the option labels on view_item, cart, purchase items and in `purchase_server`. An attribute that is only set on the variant of a configurable (`color` of the Chaz Kangeroo Hoodie) is **not** sent (STATUS.md Open issue 17, run 21); the configurable's own attributes (`material` "Wool") are |

## 8. After testing
Update `docs/STATUS.md`: move verified items to Done, list failures with the fix or the open question. Commit fixes with the tests that would have caught them (pure-logic bugs get a unit test in `Test/Unit/`).

## 9. Tips for scripted browser testing
- Magento's *Use system value* checkboxes only enable their field through the page's click handler: set the field values with real clicks (or call `.click()` on the checkbox and dispatch `change` on selects), otherwise the disabled fields are not submitted and Save reports "You saved the configuration" with nothing stored. Check with `SELECT path, value FROM core_config_data WHERE path LIKE 'googletagmanager/%'`.
- `dataLayer` is lost when the page navigates (order placement redirects to the success page). Record pushes across the redirect by wrapping `dataLayer.push` and mirroring to `sessionStorage` before clicking *Place Order*.
- Cron: the job runs every minute and `cron:run` only executes rows that are due, so after `cron:run` wait for the next minute and run it again; then check `mageos_gtm_server_event` and `var/log/system.log` (`[MageOS_GoogleTagManager] queue run: {...}`). To retry a row, set `status='pending', attempts=0, next_attempt_at=1, last_error=NULL`.
- After changing config or templates run `bin/magento cache:flush`; the admin shows the stale-cache notice otherwise.

## 10. Run log
### 2026-10-20: first local run (guest order, default Hyvä Checkout)
Environment: Magento 2.4.9, Hyvä 1.5.3, Hyvä Checkout 1.3.15, PHP 8.5.9, developer mode, sample data, Warden on WSL2; cookie consent **stub** (see Prerequisites). Details and the item-by-item state are in `docs/STATUS.md` ("Verified locally").
- Section 1 (install): passed; table and FK present.
- Section 2 (configure): validation passed in both directions; API secret encrypted; container export generated with the GA4 Measurement ID. Not yet confirmed: GTM accepting the import without errors.
- Section 3 (client-side events): `view_item_list`, `view_item`, `add_to_cart`, `view_cart`, `begin_checkout`, `purchase` seen in `dataLayer`; `add_to_cart` was duplicated on the next page until fixed. GTM Preview / DebugView and the remaining events (`update_cart`, `remove_from_cart`, `login`, `logout`, `sign_up`, wishlist, `search`) not checked yet.
- Section 4 (server-side): guest order with consent accepted: `pending` → `sent` after the `curl_close()` fix. Everything else in the table not run yet.
- Section 5 (Hyvä Checkout events): default checkout as guest: `add_shipping_info` once with `shipping_tier` = `tablerate_bestway`, `add_payment_info` once at Place Order, nothing on method selection. Mobile, one-page, virtual cart not run.
- Section 7: grid column and report render with correct numbers; refunds, cancellations, mass action, ACL, cleanup not run.

### 2026-10-20: second local run (after dropping the cookie-consent dependency)
Same environment, no consent module or stub. Module at `c94fbcd` plus the two fixes below.
- Update: `composer remove` of the stub package refreshed the module's requirements; `setup:upgrade` / `setup:di:compile` clean.
- Consent: console cookie (accept / decline / none), the fallback event and Hyvä's built-in banner (Cookie Restriction Mode on) behave as documented in "Simulating cookie consent"; the guest order with accepted consent was `sent` with the `_ga` client id, the one with `{"1":0}` was `skipped` ("tracking consent not given"). Turn Cookie Restriction Mode off again afterwards if you want the baseline back (`bin/magento config:set web/cookie/cookie_restriction 0`).
- Section 7: a partial credit memo produced one `refund_server` row (value, `partial`, same client id) that cron sent, a cancelled order with a skipped purchase produced a skipped `cancel` row, the mass action showed the confirmation and the correct messages, and the event log grid rendered with working filters. Two bugs were found and fixed (credit memo items arrive as strings; order # link). Debug Mode logged "Payload valid (debug endpoint, event not recorded)".
- Tip: in the Orders grid the mass action asks for confirmation first; the result messages appear after you confirm.
- Not run yet: see the end of the "Verified locally, run 2" block in `docs/STATUS.md`.
- Console / CSP check (Hyvä Checkout enforces CSP and uses the CSP build of Alpine): open `/checkout/` with a cart, walk the shipping and payment steps with the browser console open; there must be no Alpine "unable to interpret the following expression" warnings, no `Refused to ...` CSP violations and no exceptions. Found and fixed: the dataLayer component used `x-data="initGtmDatalayer()"`. In Hyvä all Alpine code must be CSP-style (named constructor registered with `Alpine.data()` on `alpine:init`, no calls or operators in directives, `$hyvaCsp->registerInlineScript()` after every inline script); `Test/js/phtml-scripts.test.js` lints the directives. Note the `collect` requests to `region1.google-analytics.com` answered 503 in this browser (not a CSP block: no violation was reported and CSP allows `*.google-analytics.com`); the server-side events are unaffected.

### 2026-10-20: third local run (mobile, one-page and virtual-only checkout)
Same environment. Checkouts selected through *Hyvä Checkout > General > Checkout* (`mobile`, `onepage`; the user agent based switch itself was not tried, the browser tool cannot change it), a temporary virtual product (created with a script, deleted afterwards), everything reset to `default` at the end.
- One-page: both events once at Place Order (`shipping_tier` = `tablerate_bestway`, `payment_type` = `checkmo`), one `purchase`, clean console.
- Mobile (shipping, payment, summary): `add_shipping_info` on *Proceed to Payment*, `add_payment_info` on *Proceed to Summary*, nothing more at Place Order, one `purchase`, clean console. This differs from the earlier note "both events at order placement": for the mobile checkout the payment event fires when the payment step is left.
- Virtual-only cart: no shipping step, `begin_checkout` (value 10), `add_payment_info` once, no `add_shipping_info`, `purchase` (value 10); the server-side row has `shipping` 0 and was sent.
- Also seen: `remove_from_cart` fires when removing a cart item.
- Server side: the three orders were `sent` by cron with the visitor's `_ga` client id.

### 2026-10-20: fourth local run (consent variants)
Same environment, defaults restored afterwards. See "Verified locally, run 4" in `docs/STATUS.md` for the table.
- *Require Consent* = No, event-only (with *When Consent Cannot Be Determined* = Send and Skip), custom cookie name and the Cookiebot-style accepted value all behave as described in "Simulating cookie consent" and in the order table of section 4; one order was enough per server-side case.
- Gotcha: a cookie written by Hyvä's banner carries `Domain=<host>`; deleting it from the console needs `domain=<host>` as well, otherwise it silently stays and the next "no cookie" check is wrong.

### 2026-10-20: fifth local run (FPC, search, ACL, cleanup cron, blocked network, wrong secret)
Same environment; results in `docs/STATUS.md` ("Verified locally, run 5"), and the full tested-vs-missing list is in "Local test coverage" there. Methods that worked:
- Search / FPC: open `/catalogsearch/result/?q=bag` and read `dataLayer`; for FPC use `fetch(url, {credentials: 'omit'})` twice and compare the `x-magento-cache-debug` header (MISS, then HIT) and the `view_item_list` payload; vary `product_list_order` and `p`.
- Blocked network: as root in the container append `127.0.0.1 www.google-analytics.com` to `/etc/hosts` (`warden env exec -T -u root php-fpm ...`), and remove it with `grep -v ... > tmp && cat tmp > /etc/hosts` (`sed -i` fails on the bind-mounted file). Lower *Maximum Attempts* (e.g. 3) to reach `failed` quickly; set `next_attempt_at=1` between runs instead of waiting. Run the sender directly with a small script that creates `MageOS\GoogleTagManager\Cron\SendPurchases` in the `crontab` area, or `bin/magento cron:run` twice a minute apart.
- Cleanup cron: insert synthetic rows (distinct `reference_id`, old `updated_at`, every status) and run `MageOS\GoogleTagManager\Cron\Cleanup` the same way; delete the rows afterwards.
- Wrong secret: write an encrypted wrong value with `EncryptorInterface::encrypt()` and `Magento\Config\Model\ResourceModel\Config::saveConfig()`, **back up the original encrypted value first** and restore it verbatim with SQL.
- ACL: create two roles by script (`Magento\Authorization\Model\RoleFactory`, `RulesFactory::saveRel()` with the wanted resource ids) and two admin users; log in as each (the login form may autofill: select all and retype), check the menu, the mass-action list (`uiRegistry` component `sales_order_grid.sales_order_grid.listing_top.listing_massaction`) and what the mass action returns. Delete users and roles afterwards and sign back in as the real admin.

### 2026-10-20: sixth local run (credit memos, cancellation, expiry, re-send; Hyvä one-page checkout)
Results in `docs/STATUS.md` ("Verified locally, run 6"). Methods that worked, and traps:
- The checkout in use is now **Hyvä one-page** (`bin/magento config:set hyva_themes_checkout/general/checkout onepage`); set it back to `default` for the multi-step flows.
- In this one-page checkout fill the form by name: `email_address`, `firstname`, `lastname`, `street[0]`, `postcode`, `city`, `telephone`, `select[name=region]` (Texas = `57`) and the radios `shipping-method-option` / `payment-method-option`. Setting `.value` and dispatching `input`, `change`, `blur` (and `focusout`) works and Magewire keeps the values; the region must be set explicitly or *Place Order* silently stays on the page ("State/Province field is required"). `find`-style element lookups may return the hidden login-form email field instead of the visible one: check with `getBoundingClientRect()`.
- Items can be put in the cart with `fetch('/checkout/cart/add/product/<id>/', {method: 'POST', body: form_key, product, qty, redirect: 'manual'})` using `hyva.getFormKey()`.
- Admin order actions by script of clicks: `#order_invoice`, the *Submit Invoice* button, `#order_creditmemo`, the quantity inputs `creditmemo[items][<id>][qty]` plus *Update Qty's*, *Refund Offline*, `#order-view-cancel-button` and its modal. The credit memo list and view URLs have their own secret key (read it from the menu link).
- Mass action from a script: set the selection with `uiRegistry` (`...sales_order_columns.ids` `.selected([id])`) and call `...listing_massaction` `.applyAction('mageos_gtm_resend')`, then confirm the modal.
- Browser-tool note: after logging in with a password the tool's clicks and typing stopped reaching the page for a while; setting values and clicking through JS kept working.

### 2026-10-20: seventh local run (default retry schedule, follow-ups after a failed purchase)
Results in `docs/STATUS.md` ("Verified locally, run 7"). Method: block `www.google-analytics.com` in the container (`/etc/hosts`, as root, remove with `grep -v ... > tmp && cat tmp > /etc/hosts`), place orders, then run the sender (`MageOS\GoogleTagManager\Cron\SendPurchases` from a small script in the `crontab` area) eight times; after each run read `next_attempt_at - updated_at` for the pending rows (that is the computed delay) and set `next_attempt_at = 1` to make them due. With the defaults the delays are 60, 120, 240, 480, 960, 1920 and 3840 s and the 8th failure ends in `failed`. Then invoice + credit memo one order and cancel the other, and finally unblock the host and use the mass re-send action.

### 2026-10-20: eighth local run: multi-store environment prepared and smoke-tested
A second website exists for multi-currency / multi-tax testing: Italy at `https://ita.magento249.test/` (EUR, 22 % VAT included in prices, `it_IT`), next to the US store at `https://app.magento249.test/` (USD, state taxes, prices exclude tax). Use it like this:
- Open the Italian storefront, set the consent cookie with the **website id** of that store: `document.cookie = 'user_allowed_save_cookie=' + encodeURIComponent('{"2":1}') + '; path=/'` (the US store uses `{"1":1}`).
- One-page checkout, Italian address: country `IT` (the only one), region = a province (for example Roma), postcode `00186`, city `Roma`, phone `3331234567`, shipping *Flat Rate - Fixed*, payment *Check / Money order*; the button reads "Effettua l'ordine". Order numbers start with `2` (`2000000001`).
- Expected numbers for `24-MB04` ($32 base): €29.44 including VAT (net €24.13, VAT €5.31), shipping €4.60 including VAT, order total €34.04 (VAT €6.14), base total $37.00. US store, Texas address: tax 2.64, shipping 5.00, total $39.64 (state rates in `CLAUDE.md`).
- Module settings are inherited by the Italy website (same GTM and GA4 IDs); to test per-website settings change them at website scope.
- The first multi-currency findings (client events used the base-currency price) were fixed in run 9, see `docs/STATUS.md`.
- Results of the smoke test (tax and currency numbers, the Italian order, consent per website) and the open issues are in `docs/STATUS.md` ("Verified locally, run 8" and "Open issues").
- Method notes: tax and currency totals can be checked without a browser by collecting totals on an unsaved quote for each store (a script in the `frontend` area that sets the current store, adds the product, sets a shipping address and calls `collectTotals()`); do not trust its *shipping includes tax* line, check that in the real checkout. A new store view needs product and category URL rewrites generated (a plain reindex only creates part of them), products assigned to the website (`catalog_product_website`) and the website added to the stock's sales channel (`inventory_stock_sales_channel`), otherwise the store is empty or 404s.

### 2026-10-21: ninth local run: client-side prices with another display currency and tax-inclusive prices
Results and root cause in `docs/STATUS.md` ("Verified locally, run 9"). How to repeat it, per store:
- Open a product, read `dataLayer` for `view_item`, add to cart and read `add_to_cart`, open the cart for `view_cart`, the checkout for `begin_checkout`, remove the item for `remove_from_cart`. Compare `currency` and `price`/`value` with the shop: item prices must be **tax-excluding, in the displayed currency** (Italy: 24.13 EUR for €29.44 incl. 22 % VAT; US: 32 USD).
- Switch the display currency of the US store with `fetch('/directory/currency/switch/', {method: 'POST', body: new URLSearchParams({form_key: hyva.getFormKey(), currency: 'EUR', uenc: hyva.getUenc()}), credentials: 'include', redirect: 'manual'})` (reload the page afterwards), and switch back with `USD`. Expect 29.44 EUR per $32 item.
- Pitfalls found on the way: Magento's price info is already in the current currency while `Product::getFinalPrice()` and a quote item's `price` are in the **base** currency (use `converted_price` or the price info); a product whose tax class is "None" shows no VAT removal (sample data has some).
- Infrastructure: the two stores answered "404 page not found" after a Docker restart because the nginx container could not start (single-file bind mounts); fixed by generating the nginx config in the container's start command, see the project `CLAUDE.md`. If it happens: `warden env up -d --force-recreate nginx`.

### 2026-10-21: tenth local run: re-run of the client events after the price fix; follow-ups after a re-send
Results in `docs/STATUS.md` ("Verified locally, run 10"). Methods: for `update_cart` add the product with `fetch('/checkout/cart/add/product/2/', ...)`, open the cart, set the quantity input to 3 and click *Update Shopping Cart* through a script, then read `dataLayer`. For the follow-ups: take an order with a credit memo (or a canceled order) whose purchase was sent, set the purchase row to `failed` in the table (or let it fail with a blocked network), make sure the refund / cancel row is `skipped` ("purchase was not sent (failed)"), run the mass action *Re-send GA4 server-side purchase*, then check that the refund / cancel row is `pending` with a payload and that cron sends it. Use Debug Mode so the repeated events are not recorded in GA4, and switch it off again.

### 2026-10-21: eleventh local run: client and server purchase lines aligned
Results in `docs/STATUS.md` ("Verified locally, run 11"). Method: Italian one-page checkout order with quantity 3 (the shipping amount is per item), compare `dataLayer` `purchase` (`currency`, `value`, `tax`, `shipping`, `items[].price`/`quantity`) with the `purchase_server` payload (`SELECT JSON_EXTRACT(payload, '$.events[0].params...') FROM mageos_gtm_server_event`), then invoice, full credit memo and compare `refund_server`. They must be identical in lines; only the server adds `item_variant`, categories, `discount`. Use Debug Mode so the repeated events are not recorded in GA4.

### 2026-10-21: twelfth local run: follow-ups, client events, exclusion rules, configurable, coupons
Results and the new findings (Open issues 5 to 9) are in `docs/STATUS.md` ("Verified locally, run 12"). Methods that worked, and traps:
- **Coupons:** create cart price rules with `Magento\SalesRule\Model\RuleFactory` (`coupon_type` 2, `coupon_code`, `website_ids`, `customer_group_ids`, `simple_action` `by_percent`, `discount_amount`, `simple_free_shipping` 1 for the 100 % rule); apply with `fetch('/checkout/cart/couponPost/', {method: 'POST', body: form_key, coupon_code, remove: 0})`. Delete the rules afterwards. Existing automatic rules (shipping free from $50, 20 % from $200) also apply to larger carts.
- **Configurable product:** `fetch('/checkout/cart/add/product/62/', {method: 'POST', body: form_key, product: 62, qty: 1, 'super_attribute[93]': 49, 'super_attribute[143]': 166})` (Chaz Kangeroo Hoodie, Black / XS); empty the cart first with `update_cart_action: 'empty_cart'` on `/checkout/cart/updatePost/`.
- **Zero-value order:** apply the 100 % coupon; the one-page checkout then offers *Check / Money order* and *No Payment Information Required* (`free`); choose `free`, otherwise the place-order click is refused ("missing service").
- **Exclusion rules:** set `googletagmanager/server_side/excluded_payment_methods` (`checkmo`), `excluded_statuses` (`pending`), `include_admin_orders` with `bin/magento config:set`, flush the cache, place an order, read the row; restore (`NULL` / delete the row) afterwards.
- **Admin-created order:** build the quote and submit it through `Magento\Sales\Model\AdminOrder\Create::createOrder()` or call `PurchaseEnqueuer::enqueue($order, true, time())` in the `adminhtml` area. A plain `QuoteManagement::submit()` never fires `checkout_submit_all_after`, so no row is created; `AdminOrder\Create` needs totals collected (`setTotalsCollectedFlag(false)->collectTotals()`) or the order is created with total 0.
- **Settings without new orders:** build the snapshot of an existing order in each mode with `Model\OrderSnapshotFactory::create($order, 'order'|'base', 'including'|'excluding', 'parent'|'child')` from a small script and compare `value`, `tax`, `shipping`, `item_id`, `price`.
- **Refund before purchase:** create the credit memo while the purchase is still `pending`, push the purchase row's `next_attempt_at` an hour ahead, run cron: the refund is sent first; then make the purchase due.
- **Customer events:** register through the storefront form (submit the `createpost` form), read `dataLayer` on the account page (`login` then `sign_up`); wishlist via the product page button and the trash icon; `logout` is delivered on `/customer/account/logoutSuccess/` (read `dataLayer` right after the navigation, the page redirects home after a few seconds); count `page_view` requests with the network tool (`collect`, `en=page_view`).
- Everything temporary (coupons, customer, config rows, scripts) was removed; orders 000000021 to 29 and their events remain.

### 2026-10-21: thirteenth local run: no consent cookie, product types, multishipping, Stripe
Results and findings (Open issues 10 to 14) are in `docs/STATUS.md` ("Verified locally, run 13"). Methods and traps:
- **No consent cookie:** delete `user_allowed_save_cookie` including the `domain=<host>` variant, keep the session, place a guest order: `skipped` ("tracking consent not given"), GTM script absent.
- **Bundle:** `fetch('/checkout/cart/add/product/46/', {method: 'POST', body: form_key, product: 46, qty: 1, 'bundle_option[<optionId>]': <selectionId>})` (options and selections in `catalog_product_bundle_option` / `_selection`). **Grouped:** `product: 45` with `'super_group[<childId>]': qty`. Two adds in a row before a page load deliver only the last `add_to_cart`.
- **Downloadable:** none in the sample data. Create one with the product repository (`TypeId` downloadable, `links_purchased_separately` 0, a link of type `url`, extension attribute `downloadable_product_links`) after `bin/magento downloadable:domains:add example.com` (this edits `app/etc/env.php`, so remove it again with `downloadable:domains:remove` and delete the product). Guest checkout is disabled for downloadables: register a customer first.
- **Multishipping:** Hyvä has no multishipping UI. Log a customer in through `Customer\Model\Session::setCustomerDataAsLoggedIn()`, take the checkout session's quote, call `Multishipping\Model\Checkout\Type\Multishipping::setShippingItemsInformation([[quoteItemId => ['address' => customerAddressId, 'qty' => n]], ...])`, `setShippingMethods([quoteAddressId => 'flatrate_flatrate'])`, `setPaymentMethod(['method' => 'checkmo'])`, `createOrders()`; the customer needs two addresses. (`QuoteManagement::submit()` alone does not fire `checkout_submit_all_after`.)
- **Stripe (test mode):** with the test keys already in the configuration, select *Pay online (Stripe)* in the one-page checkout and type Stripe's published test card into the Payment Element (card number, `1234` for 12/34, `123`); `4242 4242 4242 4242` succeeds, `4000 0027 6000 3184` opens the 3D Secure test page in an iframe with **FAIL** / **COMPLETE** buttons. The order only exists after a successful authentication. Typing into the Stripe iframe needs real coordinates clicks and `type` (not JS). Place the order with a `click()` on the button after arming a `dataLayer` recorder.
- **Redirect mode:** `bin/magento config:set payment/stripe_payments/payment_flow 1` switches the method to `stripe_payments_checkout` (hosted Stripe Checkout); put it back to `0` afterwards. No card data is typed on pages outside the shop.
- Cleanup list: Stripe flow back to 0, excluded statuses `NULL`, downloadable product, whitelisted domain, test customer; orders 30 to 38 remain (two of them `pending_payment`).

### 2026-10-21: fourteenth local run: pull of the four cloud commits, regenerating the GTM container
Results are in `docs/STATUS.md` ("Verified locally, run 14"). Methods and traps:
- **Export without the admin button:** a throw-away script in `var/tmp_gtm/` that bootstraps Magento (`Bootstrap::create(BP, $_SERVER)`, `State::setAreaCode('adminhtml')`), calls `Service\Export::generateLiquidTemplate()`, undoes the `\{\{` escapes (as `Controller/Adminhtml/Export/Index` does) and `createFile('mage-os_gtm_configuration.json', ...)`; the file ends up in `var/gtm/`. Delete the script afterwards. The Measurement ID must be configured, otherwise nothing is generated.
- **Check the file before importing:** `python3 -c "import json; json.load(open(...))"`, compare tag names and parameter keys with the previous export. JSON validity does not guarantee GTM accepts it: GTM validates parameter keys per tag type (`gaawc` `fieldsToSet` entries use `name` / `value`, not `fieldName`).
- **Import:** GTM > Admin > Import Container (the file is reachable from Windows through the `\\wsl$` share under the project's `var/gtm/`); overwrite when renamed tags exist.

### 2026-10-21: fifteenth local run: same item description in every event (Parent / Child, bundle, coupon, Italian store)
Results are in `docs/STATUS.md` ("Verified locally, run 15"). Methods:
- **Flow per order, all from one tab:** product page (read `view_item` from `dataLayer`, set the consent cookie `user_allowed_save_cookie={"<websiteId>":1}` first), then `fetch` POSTs with the page's `form_key` and `X-Requested-With: XMLHttpRequest`: `/checkout/cart/updatePost/` `update_cart_action=empty_cart`, `/checkout/cart/add/product/62/` (`super_attribute[93]=49`, `[143]=166`), `/checkout/cart/add/product/46/` with `bundle_option[1]=1`, `[2]=4`, `[3]=5`, `[4]=8` (option → selection ids from `catalog_product_bundle_selection`), `/checkout/cart/couponPost/`. Navigate to the cart (read `view_cart` and the delivered `add_to_cart` after ~2.5 s) and to `/checkout/` (`begin_checkout`).
- **One-page checkout by script:** set values with the native setter plus `input` / `change` / `blur` events (`email_address`, `firstname`, `lastname`, `street[0]`, `country_id`, wait 1.5 s, `region` = 57 for Texas or the option whose text is "Roma", `postcode`, `city`, `telephone`), wait 3 s, `click()` the `checkmo` radio, then click the *Place Order* button in a **separate** call (a long sleep spanning the redirect to the success page fails with "Inspected target navigated"). Read the client `purchase` on the success page, the server row from `mageos_gtm_server_event`.
- **Coupon rule:** `Magento\SalesRule\Model\Rule` saved from a throw-away script (`coupon_type` 2, `coupon_code` `GTM10`, `by_percent` 10, both websites); delete the `salesrule` row afterwards.
- **Invoice + full credit memo by script:** `InvoiceService::prepareInvoice` (offline capture), `CreditmemoFactory::createByOrder($order, [])`, `CreditmemoManagementInterface::refund($cm, true)`; then `bin/magento cron:run` (the refund row is sent on the next run). Orders need an invoice before a credit memo.
- **Item ID mode:** `bin/magento config:set googletagmanager/server_side/item_id_mode child`, flush the cache; the refund uses the mode in force at refund time. Remove the row afterwards (`config:set` cannot unset it).
- **Trap:** the Italian bundle shows "From 56,12 €" in the shop (gross) while the cart row is 48.33 net: the sample bundle's children have different tax classes (`24-WG085` none), so net and gross do not differ by 22 % for the whole bundle.

### 2026-10-21: sixteenth local run: grouped products and merged cart events
Results are in `docs/STATUS.md` ("Verified locally, run 16"). Methods:
- **Grouped add through the real form:** open the product page, set the `super_group[<childId>]` inputs with the native value setter plus `input` / `change` events, click the *Add to Cart* button; the add is delivered in the page (read `dataLayer` a moment later, `add_to_cart` is already there). Children and prices: `catalog_product_link` (link type 3) for product 45.
- **Two quick adds:** on `/gear/bags.html` click the first two *Add to Cart* buttons 300 ms apart from one script call, then read `dataLayer`. A global set before the clicks was gone afterwards, so the listing may reload the page after an add.
- **Drawer removals:** `window.dispatchEvent(new CustomEvent('toggle-cart', {detail: {isOpen: true}}))`, then click the buttons whose `aria-label` is `Remove product "<name>" from cart`. A gap of 400 ms gives two events (the first was already delivered), both clicks in the same tick give one merged event. A page-global set before the clicks survived, so the drawer removal is AJAX only.
- Reading `document.cookie` through the browser tool is blocked, check the consent through `gtm.js` in `dataLayer` instead. A freshly opened tab can answer "JavaScript execution is not allowed on chrome:// pages" for a second; retry after a screenshot.

### 2026-10-21: seventeenth local run: zero-value orders
Results are in `docs/STATUS.md` ("Verified locally, run 17"). Methods: 100 % rule with free shipping (`simple_action` `by_percent`, `discount_amount` 100, `simple_free_shipping` 1, code `GTM100`) from a throw-away script, applied through `couponPost`; in the one-page checkout choose the `free` payment method (the radio is only offered at a zero total); change the setting with `bin/magento config:set googletagmanager/general/exclude_zero_value 0` plus a cache flush and remove the row afterwards. Wait about 3 s after choosing the payment method before clicking *Place Order* (otherwise the click is swallowed while "Saving method" runs and no order is created).

### 2026-10-21: eighteenth local run: held purchases (excluded status)
Results are in `docs/STATUS.md` ("Verified locally, run 18"). Methods:
- Excluded status without a redirect payment: `bin/magento config:set googletagmanager/server_side/excluded_statuses pending` (Check / Money order is `pending` at placement), flush the cache, place an order; the row is `waiting`. Restore the row to `NULL` with SQL afterwards.
- **Orders by script** (frontend area): `CartManagementInterface::createEmptyCart`, quote with store 1, guest email, `addProduct`, billing and shipping address (Texas `region_id` 57), `setShippingMethod('flatrate_flatrate')`, `getPayment()->importData(['method' => 'checkmo'])`, `collectTotals`, `CartManagementInterface::placeOrder`. `checkout_submit_all_after` fires, the visitor has no cookies so the client id is anonymous.
- **State changes by script** (adminhtml area): invoice offline with `setIsInProcess(true)` (otherwise the state stays `new`), `setState('processing')->setStatus('processing')`, `setState('pending_payment')->setStatus('pending_payment')`, `cancel()`, shipping-only credit memo (`createByOrder($order, ['qtys' => [itemId => 0], 'shipping_amount' => 5])`). Release a waiting row by saving the order after changing a real field (`setCustomerNote`), not just a status-history comment.
- Refund while the purchase waits: set the excluded statuses to `pending,processing`, invoice (the order is `processing`, still excluded), create the partial memo (row `skipped`), then set the excluded statuses to `pending` and save the order: purchase released and the refund re-queued. A full refund would close the order (state `closed`, never released), so use a partial memo.
- Not done: the real Stripe Checkout redirect (`payment/stripe_payments/payment_flow` 1) needs a person on Stripe's page.

### 2026-10-21: nineteenth local run: Consent Mode v2
Results are in `docs/STATUS.md` ("Verified locally, run 19"). Methods:
- Settings with `bin/magento config:set googletagmanager/consent/consent_mode off|basic|advanced`, `.../granted_signals all|analytics`, `.../cmp_managed 0|1`, then `cache:flush` (the consent config is part of the cached head HTML). Delete the rows afterwards (`config:set` cannot unset).
- **No consent:** delete `user_allowed_save_cookie` with and without `domain=app.magento249.test`; **consent:** `document.cookie = 'user_allowed_save_cookie=' + encodeURIComponent('{"1":1}') + '; path=/'`; to accept without a reload also dispatch `window.dispatchEvent(new CustomEvent('user-allowed-save-cookie'))`.
- **What to read:** `window.dataLayer` entries (consent commands are `Arguments` objects, `entry[0] === 'consent'`), and the network tool filtered on `en=page_view` or `collect` for `gcs` (G111 all granted, G101 analytics only, G100 all denied), `npa`, `cid`. `read_network_requests` returns the earlier page's requests too unless `clear` was used; pass `urlPattern: 'en=page_view'` after a reload. The first read in a tab only starts recording.
- **Server side without a browser:** a script that sets `$_COOKIE['user_allowed_save_cookie']`, `$_COOKIE['_ga']` (`GA1.1.<id>`) and `$_COOKIE['_ga_<ID>']` (`GS1.1.<ts>...`) before `Bootstrap::create`, then places an order through `CartManagementInterface::placeOrder`; read `json_extract(payload, '$.consent')`.
- A navigation right before a `javascript_tool` call sometimes answers on the previous page (empty `dataLayer`): wait a few seconds or read again.

### 2026-10-21: twentieth local run: select_item, list attribution, promotions, brand / affiliation, debug logger
Results are in `docs/STATUS.md` ("Verified locally, run 20"). Methods:
- **select_item without leaving the page:** add a capturing `click` listener that calls `preventDefault()` on links (the module's handler still runs), click the product link, read `dataLayer`; the list is in `localStorage` key `mageos_gtm_item_lists`.
- **Promotions:** a temporary CMS page created from a script (`Magento\Cms\Model\Page`, `identifier` `gtm-promo-test`, `stores` [0], `page_layout` 1column) with `<a data-gtm-promotion-id=... data-gtm-promotion-name=... data-gtm-creative-name=... data-gtm-creative-slot=... data-gtm-promotion-items="SKU1,SKU2">`; WeltPixel's `data-track-promo-id` / `-name` / `-creative` / `-position`. The attribute names are `data-gtm-creative-name` and `-slot` (not `data-gtm-promotion-creative-...`). Delete the page afterwards (its URL rewrite goes with it).
- **Brand:** `googletagmanager/general/brand_attribute material` (any attribute code; option labels are joined with a comma), `.../affiliation "Test store"`.
- **Debug logger:** `googletagmanager/general/debug_logger 1`, open any page with `?gtm_debug=1`, read the console with the console tool (pattern `GTM dataLayer`); `?gtm_debug=0` clears the flag; `curl` the page HTML and `grep -c mageos_gtm_debug` for the setting on/off check.

### 2026-10-21: twenty-first local run: page context, customer groups, limits, compare, minicart, categories, custom parameters, custom GTM URL
Results are in `docs/STATUS.md` ("Verified locally, run 21"). Methods:
- **Page types without navigating:** `fetch()` each URL from one tab and read the inline `var pageContext = {...};` from the HTML (`/`, a category, a product, `/catalogsearch/result/?q=bag`, `/checkout/cart/`, `/customer/account/login/`, a CMS page, a missing page). `/checkout/` and `/checkout/onepage/success/` redirect to the cart without a cart / order: read their context in the browser.
- **Customers by script:** `CustomerRepositoryInterface::save($customer, $passwordHash)` takes a password **hash** (a plain password makes the login fail with "account sign-in was incorrect"): use `AccountManagementInterface::createAccount($customer, $password)` or set `customer_entity.password_hash` from `EncryptorInterface::getHash($password, true)`. Group ids: 0 NOT LOGGED IN, 1 General, 2 Wholesale, 3 Retailer. Log in through the form (set the two fields with the native setter, click the submit button) or `fetch('/customer/account/loginPost/', {method: 'POST', body: form_key + login[username] + login[password]})`; log out with `fetch('/customer/account/logout/')`. FPC check: `x-magento-cache-debug` MISS / HIT of the same URL as guest, General and Wholesale. Delete the customers afterwards (`isSecureArea` registry flag for the repository delete).
- **Excluded groups / max value:** `config:set googletagmanager/general/excluded_customer_groups 0` (comma list of group ids), `.../max_transaction_value 50`; guests are checked with `curl` (no GTM code in the HTML), server rows with scripted guest orders (`CartManagementInterface::placeOrder`, `$_COOKIE['user_allowed_save_cookie']` set before bootstrapping), the client `purchase` with one real browser order.
- **Minicart:** `window.dispatchEvent(new CustomEvent('toggle-cart', {detail: {isOpen: true}}))` opens the drawer; the drawer's remove button is a real click (screenshot coordinates). Hyvä's drawer has no quantity input (the `input[name="qty"]` on a product page is the product form's).
- **Compare:** the *Add to Compare* button posts and reloads; read `dataLayer` after the reload.
- **Custom GTM URL stand-in:** `pub/gtm.js` that sets `window.__customGtmStub` and appends Google's `gtm.js?id=<id>`; `pub/ns.html` an empty page; set `googletagmanager/general/gtm_script_url` with `--scope=stores --scope-code=default` to `https://ita.magento249.test` (a second host that already routes to the same Magento), flush the cache. Read the network tool (`gtm.js`), the CSP header with `curl -skI` (`content-security-policy-report-only` on the storefront, enforced `content-security-policy` on the checkout), and on the checkout inject `<script src>` elements for the custom host (loads) and for an unlisted host (`securitypolicyviolation` with `script-src-elem`). Remove the two files and the config rows afterwards.
- **Settings used:** `view_cart_on_minicart 1`, `category_depth 2`, `excluded_categories 4`, `custom_item_attributes` with one `name:code` per line (`item_material:material`, `item_activity:activity`).
