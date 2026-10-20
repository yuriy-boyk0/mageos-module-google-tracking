# Local testing guide (Magento 2.4.9 + Hyvä + Hyvä Checkout)

For a Claude Code session or a person with the real store. Cloud sessions cannot run any of this (see `CLAUDE.md`). Record results in `docs/STATUS.md` ("Needs local testing") as you go: tick items, note failures, and fix code or tell the user.

## 0. Prerequisites
- Clean Magento Open Source 2.4.9 with sample data, Hyvä theme + Hyvä Checkout active, `MageOS_CookieConsent` installed (a required dependency).
- Developer or production mode; cron working (`bin/magento cron:run` twice schedules then executes jobs) or run it manually while testing.
- A new GA4 property + GTM container, created by following `docs/google-setup.md` (this doubles as the docs acceptance test). You need: GTM Container ID, GA4 Measurement ID, and a Measurement Protocol API secret of the **same data stream**.

## 0b. First thing to check: admin configuration loads
`etc/adminhtml/system.xml` declares the tab id `mage-os`, which Magento 2.4.9's XSD does not allow (`[a-zA-Z0-9_]` only). In developer mode open Stores > Configuration > MageOS > Google Tag Manager. If the admin errors with an invalid-XML message, rename the tab id to `mage_os` (the `<tab id>` and the section's `<tab>` reference) and keep it consistent with `MageOS_CookieConsent`.

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

## 2. Configure
Stores > Configuration > MageOS > Google Tag Manager.
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
- The consent cookie really is `user_allowed_save_cookie` with a `websiteId → 1` map after accepting tracking in `MageOS_CookieConsent` (`document.cookie`), and the session cookie is `PHPSESSID`. If not, adjust `Model/RequestContextProvider` and `ConsentEvaluator` (+ tests).
- Values in the payload equal the client-side `purchase` for the same order (multi-currency store: switch display currency, compare).
- Order placed through a redirect payment method, and multishipping (the observer receives `orders` there).
- `bin/magento cron:run` logs one line per run with sent/retried/failed counts; a crash mid-run is recovered after 10 minutes (row stuck in `processing`).

## 5. Hyvä Checkout shipping/payment events (open item)
Goal: push `add_shipping_info` (`shipping_tier`) and `add_payment_info` (`payment_type`) once per submitted choice. The base is ready: `view/frontend/templates/gtm/checkout-steps.phtml` exposes `window.mageosGtmCheckout.push(eventName, extra)` with the checkout's items/value/currency; listeners are disabled (`HYVA_EVENTS` are `null`).

Discover the events. In the browser console on the checkout page, before interacting:
```js
(() => { const o = window.dispatchEvent.bind(window); window.dispatchEvent = e => { console.log('[evt]', e.type, e.detail); return o(e); }; })();
```
Select a shipping method, continue, select a payment method, continue; note which event names fire and what `detail` contains. Also search the Hyvä Checkout package:
```bash
grep -rn "dispatchEvent\|\$dispatch\|dispatch(" vendor/hyva-themes/magento2-hyva-checkout/src/view | head -50
```
Docs worth reading (not reachable from cloud): Hyvä Checkout "Frontend API Events" (`https://docs.hyva.io/hyva-checkout/devdocs/frontend-api/events.html`) and "Checkout Magento Events". Known: `checkout:step:loaded` (`detail.route`, `detail.subsequent`) and `checkout:navigation:success`.

Then: fill `HYVA_EVENTS` and `EXTRACTORS` in the template, make sure the push happens on *submit/continue* and not on every toggle, add a case to `Test/js/phtml-scripts.test.js`, verify in GTM Preview/DebugView, and update `docs/STATUS.md`, `README.md`, `docs/google-setup.md`.
If a Magento-side approach turns out to be better (a plugin on shipping/payment save feeding `Service/EventQueue`), prefer it only if the JS events prove unreliable.

## 6. After testing
Update `docs/STATUS.md`: move verified items to Done, list failures with the fix or the open question. Commit fixes with the tests that would have caught them (pure-logic bugs get a unit test in `Test/Unit/`).
