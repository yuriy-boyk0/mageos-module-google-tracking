# CLAUDE.md

Guidance for Claude Code sessions working on `MageOS_GoogleTagManager` (Composer package `mage-os/module-google-tag-manager`): a Magento 2 / Mage-OS module for **Hyvä** storefronts that injects GTM, pushes GA4 e-commerce events to the dataLayer, exports a GTM container, and (new) sends a server-side `purchase_server` event via the GA4 Measurement Protocol.

## Read these first
- `docs/STATUS.md`: what is done, what still needs local testing, user testing, or a decision. **Keep it updated in every session.**
- `docs/ga4-gtm-plan.md`: the design and phases.
- `docs/hyva-checkout-notes.md`: Hyvä Checkout events/structure facts used by the tracking.
- `docs/local-testing.md`: how to test on a real Magento 2.4.9 + Hyvä + Hyvä Checkout install (this is the target environment).
- `docs/google-setup.md`: end-user guide (GA4/GTM accounts, which ID goes where). Keep it in sync with config changes.

## Two environments, different capabilities
| | Claude cloud session | Local session (real Magento + Hyvä + Hyvä Checkout) |
|---|---|---|
| Magento / Hyvä installed | **No install**, but the Magento **source** can be cloned read-only: `add_repo` for `magento/magento2` says it is available, then `GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 --branch 2.4.9 https://github.com/magento/magento2 /home/user/magento/magento2` (about 1 min). Use it to check real class/method names and XSDs; grep it before relying on any Magento API. PHPStan: `git clone --depth 1 --branch 1.12.34 https://github.com/phpstan/phpstan /home/user/tools/phpstan` (Composer cannot download from GitHub in the sandbox). Hyvä Checkout 1.3.15 + Hyvä theme sources were provided by the user as a zip (extracted to `/home/user/hyva/src/hyva-themes/` in that session; commercial, never commit; ask the user to re-upload in a new session); findings are in `docs/hyva-checkout-notes.md`; docs.hyva.io and the Mage-OS/Magento Composer repos are blocked | Yes |
| Outbound to Google | Blocked | Yes |
| Can verify | PHP syntax, XML, JSON, Magento-free unit tests, inline JS | Everything: DI compile, setup:upgrade, admin config, real orders, cron, GA4 DebugView |

So: write logic as **pure PHP with no Magento imports** wherever possible and unit test it; keep Magento-dependent code thin, and record what could not be verified in `docs/STATUS.md` (section "Needs local testing") and `docs/local-testing.md`. Never claim Magento-dependent code works because the unit tests pass; static checks against the 2.4.9 source reduce risk but do not replace local testing.

## Commands
```bash
(cd dev && composer install)   # once per fresh clone: installs PHPUnit into dev/vendor (gitignored)
dev/check.sh                   # PHP lint, XML, JSON, PHPUnit, JS tests; run before every commit
dev/check-magento.sh           # PHPStan + XSD validation against the real Magento 2.4.9 sources (needs the clones below)
dev/vendor/bin/phpunit -c phpunit.xml.dist --filter ClientTest   # a single test class
node --test Test/js/phtml-scripts.test.js                        # inline <script> tests
```
PHPUnit tests live in `Test/Unit/` and only cover `Service/MeasurementProtocol/` (Magento-free). The JS tests extract the inline `<script>` from the `.phtml` templates and run them in a Node `vm` with stubs, so if you change a script's variable names or the `<?= ... ?>` placeholders, update the test's placeholder strings.

## Architecture
- **Client-side**: Observers (`Observer/`) put events in session via `Service/EventQueue`; `CustomerData/Gtm` delivers them through a customer-data section; `view/frontend/templates/body.phtml` (Alpine) pushes them to `dataLayer`. Page-level events (`view_cart`, `begin_checkout`, `purchase`) are inline scripts in `view/frontend/templates/gtm/`. GTM loads only after cookie consent (`head.phtml`), configured under `googletagmanager/consent/*` (required, cookie_name, granted_value, event_names); the browser gate and `Service/MeasurementProtocol/ConsentEvaluator` apply the same cookie rules, keep them in sync (JS tests cover `head.phtml`). There is no consent-module dependency.
- **GTM export**: `etc/tags.json`, `triggers.json`, `variables.json`, `build_in_var.json` are Liquid-templated and rendered by `Service/Export`. The `\{\{ \}\}` escapes are intentional (undone in the export controller). Event triggers are *Custom Event* triggers on `{{_event}}` (the dataLayer `event` name); GA4 Configuration fires on *All Pages*. Changing them means users must re-import the container: say so in the docs.
- **Server-side purchase (Measurement Protocol)**, purchase only, by design:
  `checkout_submit_all_after` → `Observer/EnqueueServerSidePurchase` (consent, exclusions, builds payload, inserts a row) → table `mageos_gtm_server_event` → cron `Cron/SendPurchases` every minute → `Service/MeasurementProtocol/QueueProcessor` → `Client` → `Model/CurlTransport`. The observer must **never** call Google or throw; all failures are swallowed and logged so checkout cannot break.
  - Pure, unit-tested core: `Service/MeasurementProtocol/*` (PayloadBuilder, Client, QueueProcessor, ConsentEvaluator, GaCookieParser, ExclusionPolicy, RetryPolicy, ConfigIdValidator, TotalsResolver, ItemIdResolver, FollowUpPayloadBuilder, FollowUpPolicy, ResendPolicy, HoldPolicy, RefundQuantity).
  - Magento adapters, **not testable in cloud**: `Model/Config`, `Model/DbQueueStorage`, `Model/PurchaseEnqueuer` (shared by the observer and the re-send backfill), `Model/FollowUpEnqueuer`, `Model/PurchaseResender`, `Model/OrderSnapshotFactory`, `Model/RequestContextProvider`, `Model/ResourceModel/ServerEvent/Grid/Collection` (event log grid), `Ui/Component/Listing/Column/OrderLink`, `Model/CurlTransport`, `Model/Log`, observers, crons (`SendPurchases`, `Cleanup`), `Plugin/OrderGridDeliveryStatus`, admin controllers, `etc/db_schema*.xml`, `etc/adminhtml/*`, `view/adminhtml/*`.
  - Phase 3: refunds (`sales_order_creditmemo_save_after`, one row per credit memo via `reference_id`) and cancellations (`order_cancel_after`) as `refund_server` / `cancel_server`, only when the purchase was sent; order grid mass action re-send. Phase 4: order grid column, Reports > Marketing event log grid (UI listing `mageos_gtm_server_event_listing`), DebugView flag, retention cron.
- **Item description** (`Service/ItemDataResolver`): one place for `item_id` (ItemIdResolver + *Item ID* setting, bundles = own catalog SKU), `item_variant`, category path; used by every client event, the server purchase (`OrderSnapshotFactory`) and refunds (`FollowUpEnqueuer`, bundle quantity via `RefundQuantity`). Keep client and server identical.
- **Consent Mode v2** (`googletagmanager/consent/consent_mode` off/basic/advanced, `granted_signals`, `cmp_managed`): `head.phtml` pushes `gtag('consent', …)`; server MP `consent` via `Service/MeasurementProtocol/ConsentModeSignals`.
- **List attribution / select_item** (`gtm/list-attribution.phtml` defines `window.mageosGtmListAttribution`, used by `gtm/catalog-events.phtml`, `body.phtml`, `gtm/checkout-purchase.phtml`), **promotions** (`gtm/promotions.phtml`, data attributes), **debug logger** (`gtm/debug.phtml`, `?gtm_debug=1`). All covered by `Test/js/phtml-scripts.test.js`.
- **Page / visitor context** (`Service/DataLayer/PageContext`, `PageTypeResolver`): `{page_type, customer_group, logged_in}` pushed in `head.phtml` before GTM; customer group / login come from Magento's HTTP context (part of the FPC key, so safe in cached HTML). `TagManager::isEnabled()` is false for *Don't Track Customer Groups*. Custom GTM script URL + `Model/Csp/GtmScriptHostCollector`.
- **Item options** in `Service/ItemDataResolver`: category depth / excluded categories (`Service/DataLayer/CategoryPathPicker`), up to five custom item parameters (`Service/DataLayer/CustomItemAttributes`, passed through by `PayloadBuilder`). Exclusions for customer groups and maximum transaction value apply client (`Block/CheckoutTagManager`) and server (`ExclusionPolicy`).
- **Pending client events** in the session are merged, not overwritten (`EventQueue::appendSessionEvent`, `Service/DataLayer/EcommerceEventMerger`); grouped products add one item per child (`CartService::addGroupedToCart`).
- **Excluded order status = hold**: the purchase row is stored as `waiting` with its payload and released by `Observer/ReleaseWaitingPurchase` (`sales_order_save_after`) → `Model/PurchaseReleaser` (`HoldPolicy`) when the status is no longer excluded. Canceled / closed while held → `skipped` (same observer, `HoldPolicy::closeReason`); held longer than `server_side/max_hold_days` (default 30) → `expired` by `Cron/Cleanup`. Optional `server_side/skip_client_purchase_when_held` leaves out the client `purchase` while the status is excluded (`Block/CheckoutTagManager`).
- **Catalog events** `view_item`, `view_item_list`, `search`: `Block/CatalogTagManager` + `Service/CatalogService`, rendered into FPC-cached HTML (catalog data only) and placed `after="-"` so the list block has loaded its collection first.
  - `purchase_server` is a deliberately different event name from the client-side `purchase`: a comparison signal, not revenue. Do not "fix" this by deduplicating or renaming.
- Only `purchase` goes server-side. Do not move other events server-side (see plan, non-goals).

## Conventions
- PHP 8.2+, `declare(strict_types=1)`, constructor promotion with `readonly`, namespace `MageOS\GoogleTagManager`.
- Secrets (API secret) are stored encrypted and read at send time; never write them into the queue table or logs.
- Inline scripts in templates must be followed by `$hyvaCsp->registerInlineScript()` (Hyvä CSP).
- `etc/db_schema_whitelist.json` was written by hand; regenerate it locally with `bin/magento setup:db-declaration:generate-whitelist --module-name=MageOS_GoogleTagManager` after changing `db_schema.xml`.
- Config paths: `googletagmanager/general/*` (tag_id, ga4_measurement_id, enabled), `googletagmanager/consent/*`, `googletagmanager/server_side/*`.
- After every local test run, update `docs/STATUS.md` ("Verified locally, run N" and "Local test coverage: tested vs still missing") and `docs/local-testing.md`: what was tested, the result, what is still missing.
- Commit messages: imperative summary + short body. Push to the branch the session was assigned; no PRs unless asked.

## Decisions already made (do not re-ask)
- Queue = own DB table + cron, not Magento MQ.
- When consent cannot be determined (admin orders, gateway callbacks) the default is **send anonymously** (no client_id link, no user_id); configurable. Visitors who declined are never sent.
- `add_shipping_info` / `add_payment_info` on Hyvä Checkout: pushed on `checkout:validation:success` from the remembered selection (`view/frontend/templates/gtm/checkout-steps.phtml`, `docs/hyva-checkout-notes.md`); needs local verification.
- Purchase currency = the order's currency for now; an admin-configured reporting currency is a noted future improvement (see `docs/STATUS.md`).
- `purchase_server` stays comparison-only and is documented as such.

- No dependency on `mage-os/module-cookie-consent` (it does not exist yet): consent cookie name, "accepted" value and fallback window events are configurable; defaults match Magento's/Hyvä's built-in banner.

- Multishipping is not supported or tested (no Hyvä UI); documented, no work planned.
- *Exclude Zero-value Orders* is a General setting and also suppresses the client-side `purchase`.
- Client items match the server items (variant, category path, item_id, per-item discount).
- Excluded order statuses hold the purchase (`waiting`) until the order reaches an allowed status; canceled / closed while held → `skipped`; held > *Maximum Hold* (30 days) → `expired`.
- Client `purchase` for an excluded (unpaid) status: sent by default; optional setting to skip it (never sent later from the browser).

## Open questions
Listed in `docs/STATUS.md`. Ask the user when blocked by one; do not guess Hyvä Checkout event names.
