# Local testing guide (Magento 2.4.9 + Hyvä + Hyvä Checkout)

For a Claude Code session or a person with the real store. Cloud sessions cannot run any of this (see `CLAUDE.md`). Record results in `docs/STATUS.md` ("Needs local testing") as you go: tick items, note failures, and fix code or tell the user.

## 0. Prerequisites
- Clean Magento Open Source 2.4.9 with sample data, Hyvä theme + Hyvä Checkout active, `MageOS_CookieConsent` installed (a required dependency).
- Developer or production mode; cron working (`bin/magento cron:run` twice schedules then executes jobs) or run it manually while testing.
- **Hyvä Checkout must be selected, not just installed.** The default of `hyva_themes_checkout/general/checkout` is the Luma fallback, so `/checkout/` shows "No Checkout module installed" until you run `bin/magento config:set hyva_themes_checkout/general/checkout default` (or pick *Hyvä Checkout* under Stores > Configuration > Hyvä Checkout) and flush the cache.
- **`mage-os/module-cookie-consent` is not on Packagist** (404), so `composer require` of this module cannot resolve it on its own. Add its real source as a Composer repository, or for pure testing use a throw-away stub package that provides the module `MageOS_CookieConsent`, writes the cookie `user_allowed_save_cookie` = URL-encoded `{"<websiteId>":1}` (or `0` when declined) and dispatches the window event `cookie-consent-tracking-approved` on accept. Keep such a stub outside this repository and remove it afterwards. The real module's cookie format, event and admin tab id are still unverified (items 6 and 16 in `STATUS.md`).
- Keep the Hyvä Theme module's own **Google Tag Manager** section (`hyva_theme_google`) disabled; GTM would be loaded twice.
- Test in a browser **without an ad blocker** for the site (uBlock-style rules replace `google-analytics_analytics.js`; `gtm.js` then returns 200 but `window.google_tag_manager` stays empty).
- A new GA4 property + GTM container, created by following `docs/google-setup.md` (this doubles as the docs acceptance test). You need: GTM Container ID, GA4 Measurement ID, and a Measurement Protocol API secret of the **same data stream**.

## 0b. First thing to check: admin tab
The module creates the admin tab itself (`etc/adminhtml/system.xml`: tab id `mage_os`, label **Mage-OS**). Open Stores > Configuration and confirm **Mage-OS > Google Tag Manager** appears, also in developer mode. If `MageOS_CookieConsent` declares a differently named tab, two Mage-OS tabs will show; align the ids then.

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
Accept the cookie banner first. Verify `page_view`, `add_to_cart`, `update_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `login`, `logout`, `sign_up`, `add_to_wishlist`.
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
| Consent declined / banner never accepted | row `skipped`, `last_error` = "tracking consent not given" |
| Admin-created order | `skipped` while "Track Admin-created Orders" = No; with Yes → sent anonymously (default "send anonymously") |
| Configurable product with options and coupon | one item with `item_variant` (e.g. "Black, XS"), `discount`, `coupon` |
| Zero-value order / excluded status / excluded payment method | `skipped` with the matching reason |
| Network blocked (e.g. block `www.google-analytics.com` in hosts/firewall) | checkout still completes at normal speed; row `pending` with growing `next_attempt_at`; after max attempts `failed` |
| Wrong API secret | with the normal endpoint Google still answers 2xx (not detectable); use Debug Mode to validate |
| Debug Mode on | `var/log/system.log` shows `[MageOS_GoogleTagManager]` validation result; nothing in GA4; turn off afterwards |
| Same order twice (re-trigger observer) | still one row (unique key) |
| Cron stopped for 3+ days, then started | old rows become `expired` (message "unrecoverable…"), payload kept, nothing sent |
| Tax in Totals = Excluding / Item ID = Child | `value` = grand total − tax, item prices/shipping without tax; configurable `item_id` is the variant SKU. Compare with Including / Parent |
Also verify:
- The payload's `client_id` equals the browser's `_ga` cookie value (`GA1.1.<this part>`) and `session_id` the `_ga_<ID>` session, so the event lands in the visitor's session. Both cookie formats `GS1` and `GS2`.
- The consent cookie is `user_allowed_save_cookie` with a `{"<websiteId>":1}` map (confirmed in the Hyvä banner source, which exists only when Magento's *cookie restriction mode* is enabled). Check whether `MageOS_CookieConsent`'s tracking category changes anything the server can read, and that the session cookie is `PHPSESSID`. If not, adjust `Model/RequestContextProvider` / `ConsentEvaluator` (+ tests).
- Values in the payload equal the client-side `purchase` for the same order (multi-currency store: switch display currency, compare).
- Order placed through a redirect payment method, and multishipping (the observer receives `orders` there).
- `bin/magento cron:run` logs one line per run with sent/retried/failed counts; a crash mid-run is recovered after 10 minutes (row stuck in `processing`).

## 5. Hyvä Checkout shipping/payment events
Implemented in `view/frontend/templates/gtm/checkout-steps.phtml` from the Hyvä Checkout 1.3.15 sources (see `docs/hyva-checkout-notes.md`); it has **not** been run in a browser. Verify with GTM Preview / DebugView and the console (`dataLayer`):
- Default checkout (login → shipping → payment): nothing is pushed when the shipping step loads with a preselected method; `add_shipping_info` (`shipping_tier` = e.g. `flatrate_flatrate`) is pushed once when you continue to the payment step; `add_payment_info` (`payment_type` = e.g. `checkmo`) once when you place the order.
- Change the shipping method before continuing: only the final method is pushed (or, if you go back and change it, a second push with the new value).
- Mobile checkout (with the summary step) and the one-page checkout (both events at order placement).
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
| Re-save / comment on an existing credit memo | no new row |
| Credit memo for an order whose purchase was `skipped` | refund row `skipped` with the purchase status in `last_error` |
| Cancel an order (admin) | `cancel_server` with the order value; same `client_id` as the purchase payload |
| Order grid | column *GA4 Server-side Purchase* shows statuses; filter by it, combine with the grid's status/date filters and sort: no "ambiguous column" SQL error |
| Mass action on failed/expired orders | "queued" message; rows `pending` without `timestamp_micros`; sent by cron |
| Mass action on an order from before the feature was enabled | new row, anonymous client id, real order date kept if < ~70 h old |
| Mass action on sent / skipped orders | notice "not queued: already sent / skipped on purpose …" |
| Admin user without the new ACL resources | mass action and report hidden / denied |
| Reports > Marketing > GA4 Server-side Events | numbers per order day match the grid; "Not tracked" counts orders without rows |
| *Show in GA4 DebugView* = Yes | server events visible in DebugView (and recorded) |
| Cleanup cron (`bin/magento cron:run`, or call `MageOS\GoogleTagManager\Cron\Cleanup`) | deletes only sent/skipped rows older than the retention; failed/expired kept |

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
