# Local testing guide (Magento 2.4.9 + Hyvä + Hyvä Checkout)

For a Claude Code session or a person with the real store. Cloud sessions cannot run any of this (see `CLAUDE.md`). Record results in `docs/STATUS.md` ("Needs local testing") as you go: tick items, note failures, and fix code or tell the user.

## 0. Prerequisites
- Clean Magento Open Source 2.4.9 with sample data, Hyvä theme + Hyvä Checkout active, `MageOS_CookieConsent` installed (a required dependency).
- Developer or production mode; cron working (`bin/magento cron:run` twice schedules then executes jobs) or run it manually while testing.
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

## 6. After testing
Update `docs/STATUS.md`: move verified items to Done, list failures with the fix or the open question. Commit fixes with the tests that would have caught them (pure-logic bugs get a unit test in `Test/Unit/`).
