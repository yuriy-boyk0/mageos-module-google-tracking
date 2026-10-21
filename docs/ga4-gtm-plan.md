# Server-Side Implementation Plan: GA4 Measurement Protocol for Purchase Tracking

## Context

This module (`mageos-module-google-tracking`) is a Magento 2 / Hyvä GA4 + GTM tracking module. Today it tracks events **client-side only**: it injects the GTM snippet, builds a `dataLayer` via a customer-data section, and pushes events from Alpine.js templates on the storefront (cart add/remove, view_cart, begin_checkout, purchase, login, sign_up, add_to_wishlist).

Client-side-only tracking has one specific, high-impact problem this plan addresses: **the purchase event**. A client-side purchase push only fires if the customer's browser successfully loads the success page and executes JS. Ad blockers and browser privacy features (Brave Shields, Safari ITP, Firefox ETP, uBlock Origin) commonly strip client-side GA4 hits, and abandoned tabs, network drops, or payment-gateway redirects that skip the success page cause the event to never fire at all. Because purchase is the event that revenue reporting depends on, this is the one gap worth closing with server-side tracking.

## Architecture rule: purchase only goes server-side; everything else stays client-side

**Only the `purchase` event is sent server-side, via GA4 Measurement Protocol (MP).** Every other event in the module (`add_to_cart`, `remove_from_cart`, `view_cart`, `view_item`, `view_item_list`, `select_item`, `search`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `login`, `sign_up`, `add_to_wishlist`, `add_to_compare`, etc.) is tracked **client-side only**, the way the module already does it (dataLayer push via Alpine/GTM). This is a deliberate scope reduction from earlier server-side proposals: building a Measurement Protocol pipeline, a cache-observability workaround, and a beacon endpoint for every event was unnecessary complexity when the actual business risk is concentrated in one event. Cart, browsing, and account events are low-stakes if occasionally lost to an ad blocker; **lost revenue reporting on purchase is not**.

This also means the "cached page can't be observed by PHP" problem that motivated a JS beacon in earlier drafts of this plan **doesn't apply here**: all other events stay client-side, so they're triggered by real browser JS execution regardless of whether the page HTML came from cache — no beacon, no dedicated uncacheable endpoint, no beacon-endpoint naming/obfuscation is needed at all. Order placement (the trigger for the server-side purchase event) is itself never a cached response — checkout submission is always a live POST request — so the server observer for purchase needs no such workaround either.

### Purchase is tracked twice, deliberately, with two different event names

**Decision (confirmed):** `purchase_server` is a **comparison/diagnostic signal only**. It is intentionally not a standard GA4 `purchase`, so it does not feed monetization reports or Ads conversions. Its purpose is to show how many orders the client-side `purchase` misses (ad blockers, abandoned tabs, redirects). Whoever manages the GTM/GA4 account decides manually whether to use it (for example in explorations or a custom tag); the module does not try to merge or deduplicate it. This must be documented clearly (Phase 5, "Server-side purchase").

Purchase is the one event that gets **both** a client-side and a server-side implementation, on purpose:

1. **Client-side** (existing, kept as-is other than a naming fix): fires immediately when the success page renders, pushed to the dataLayer under its own event name — e.g. `purchase` — so GTM tags, real-time Google Ads conversion tags, and any live debugging/DebugView usage keep working exactly as they do today, with no dependency on server round-trips.
2. **Server-side** (new): fires from the order-placement observer in PHP, sent via Measurement Protocol under a **different** event name — e.g. `purchase_server` — as close to the moment the order is actually placed in Magento as possible, independent of whether the browser ever reaches or renders the success page.

**Why two different event names instead of one deduplicated event:** GA4's own deduplication (matching `transaction_id` under the *same* event name across gtag.js and MP) is designed for the case where you want exactly one counted purchase. Here we deliberately want both signals to remain independently visible and queryable — comparing `purchase` vs `purchase_server` counts for the same `transaction_id` is itself useful (it's a live measurement of how much revenue ad blockers are hiding from the client-side number). Reporting/analysis in GA4 (exploration, custom report) should treat "true unique revenue" as the **union of transaction_ids seen on either event**, not a sum of both — the two events aren't meant to be added together, since a portion of purchases will legitimately appear on both.

**Why the server-side call must never block the customer:** the server-side send happens synchronously in terms of *triggering* (right after the order is placed) but must be **dispatched asynchronously** to Google's API. If the Measurement Protocol endpoint is slow, times out, or returns an error, the customer's checkout must complete normally regardless — tracking is never allowed to be a point of failure in the order flow. This means the server-side purchase event is placed on a queue and retried on failure rather than sent inline and awaited.

## Current state (what already exists in the module, for reference)

- GTM container snippet injection (head + noscript), admin-configurable per store view.
- Client-side dataLayer events: `add_to_cart`, `remove_from_cart`, cart quantity update, `begin_checkout` (Hyvä Checkout only — shipping/payment steps not wired), a purchase-like event on the success page (bug: hard-coded event name `checkout_success` instead of GA4-standard `purchase`, no dedup on reload), `view_cart`, `login`/`logout`, `sign_up`, `add_to_wishlist`.
- A basic single-boolean consent gate before GTM loads — not full Google Consent Mode V2. Originally tied to a sibling `MageOS_CookieConsent` module; since that module does not exist yet the dependency was dropped (2026-10-20) and the gate is configurable (consent cookie name, optional "accepted" value, fallback window events, or no consent requirement).
- Full Page Cache / Varnish compatible architecture (customer-data section + non-cacheable blocks).
- Hyvä CSP compatibility (all inline scripts registered, GTM domain whitelisted).
- A GTM container **export** tool (admin button generates tags/triggers/variables as importable JSON via Liquid templates) — this is the one existing piece of "automation" tooling, but it's JSON-file-only, no OAuth/API push.
- **Nothing server-side exists**: no Measurement Protocol client, no refund/cancellation tracking, no order-grid tracking status, no consent audit log, no Google Ads API integration.

---

## Target test environment and acceptance testing

The module is developed in Claude cloud (unit tests only, no Magento install, no outbound access to Google), and is **ultimately validated by hand on a local environment**:

- **Magento Open Source 2.4.9**, clean installation with the **sample data** deployed (provides products with multiple categories, configurables, customers and coupons to exercise the item builder)
- **Hyvä theme + Hyvä Checkout** (the module's templates and layout handles target Hyvä only; Luma is not tested)
- A **new Google Analytics 4 property and Google Tag Manager container**, created during the tests by following the module documentation (see Phase 5). The documentation itself is therefore part of the test: a tester starting from zero should be able to finish the setup using only the docs.
- PHP/DB/search stack as required by 2.4.9; cron running (`bin/magento cron:run`) or the queue consumer started manually, since the purchase send is asynchronous

### Split of responsibilities

| Where | What is verified |
|---|---|
| Claude cloud | PHPUnit unit tests for payload builder, MP client (mocked HTTP), retry/backoff, consent/exclusion rules, JS dedup guard; XML well-formedness checks; docs written and reviewed |
| Local 2.4.9 + Hyvä install | `setup:upgrade` / `di:compile` succeed with no errors; admin config renders and saves; GTM snippet and dataLayer appear; real orders trigger the observer, queue and consumer; order grid column and mass action work; GA4 DebugView / Realtime receive `purchase` and `purchase_server` |

### Manual acceptance checklist (local install)

1. Install the module via Composer (path repository or VCS), run `module:enable`, `setup:upgrade`, `di:compile`, `cache:flush`; confirm no errors.
2. Create the GA4 property and GTM container following the docs; paste IDs into **Stores > Configuration > Mage-OS > Google Tag Manager**.
3. Import the exported GTM container JSON (from the admin export button) into the new container; publish it.
4. With GTM Preview and GA4 DebugView: verify `page_view`, `add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout` (Hyvä Checkout), `login`, `sign_up`, `add_to_wishlist` arrive in GA4.
5. Place a guest order and a logged-in order with sample-data products (including a configurable product and one with a coupon). Verify the client-side `purchase` event fires once, and does not re-fire on success-page reload.
6. Verify `purchase_server` reaches GA4 for the same `transaction_id`, with correct value, currency, tax, shipping, coupon and items; confirm it is attributed to the same session (matching `client_id`).
7. Failure paths: block outbound traffic to Google (or use a wrong API secret) and confirm checkout still completes, the delivery status goes pending, then failed after the retry limit; restore access and re-send via the mass action.
8. Consent: decline third-party cookies, place an order, confirm no server-side event is sent. Accept, place an order, confirm it is sent.
9. Exclusions: zero-value order, excluded status, excluded payment method, admin-created order toggle.
10. Run the MP debug-mode config and confirm the validation endpoint response is logged.

---

## Phase 0: Measurement Protocol foundation (purchase-only)

Nothing else in this plan works without this. Scoped specifically to what the purchase event needs — no generic multi-event MP framework.

| Feature | Work |
|---|---|
| MP HTTP client | `Service/MeasurementProtocol/Client.php`: sends signed POST requests to `https://www.google-analytics.com/mp/collect` using a Measurement ID + API secret |
| Client ID / session ID capture | Read the `_ga` / `_ga_<PROPERTY>` cookies (and GA4 session cookie) server-side at order-placement time, so the MP hit attributes to the correct browser session and keeps attribution intact — without this, the server-side event becomes a "new" unattributed session in GA4 |
| Config UI | New `system.xml` group: enable/disable server-side purchase tracking, Measurement ID, API secret (per store view), the server-side event name (defaults to `purchase_server`) |
| **Async queue with retry** | `Service/MeasurementProtocol/PurchaseQueue.php` (built on Magento's message queue / a scheduled `cron` consumer, or extending the existing `Service/EventQueue`): the order-placement observer only *enqueues* the payload, never calls the HTTP client inline. A queue consumer sends it, with exponential backoff retry on failure (network error, timeout, non-2xx response) up to a configurable attempt limit. This is the mechanism that guarantees the customer's checkout is never blocked or slowed by Google's API being unavailable |
| Payload builder | `Service/MeasurementProtocol/PurchaseEventBuilder.php`: builds the GA4 MP JSON payload for the purchase event only (`client_id`, `user_id`, event name = configured server-side name, `transaction_id`, `value`, `currency`, `tax`, `shipping`, `coupon`, `items[]`) |
| Delivery status | Persist a per-order delivery status (pending / sent / failed-exhausted) so the queue consumer and admin tooling (Phase 2) both know the state of each order's server-side send |

## Phase 1: Client-side purchase event fix

Small, focused fix to the existing client-side push so it plays correctly with the new server-side event, rather than a rebuild.

| Feature | Work |
|---|---|
| Correct client-side event name | Fix the current hard-coded `checkout_success` event name to the proper GA4-standard `purchase`, so GTM triggers and Google Ads conversion tags keyed on the standard name work as expected |
| Duplicate-push prevention on reload | Guard against the success page firing `purchase` more than once per order on a page reload/back-navigation (e.g. a client-side flag keyed to `transaction_id` in session/local storage) — this was already a known gap and is unrelated to the new server-side event, but is the natural place to fix it while touching this code |
| No other changes | This event otherwise keeps its existing client-side-only behavior (fires on success page render, depends on the browser reaching that page) — it remains the fast, real-time signal; it is not merged with or replaced by the server-side event |

## Phase 1b: Hyvä Checkout `add_shipping_info` / `add_payment_info` (client-side)

**Status:** implemented against the Hyvä Checkout 1.3.15 source, awaiting local verification. The exported `Add Shipping Info` / `Add Payment Info` triggers fire on `ga4_event` (they used to fire on the order success URL).

**How it works** (details and event reference: `docs/hyva-checkout-notes.md`; code: `view/frontend/templates/gtm/checkout-steps.phtml`):
- Hyvä dispatches `checkout:shipping:method-activate` and `checkout:payment:method-activate` (`detail.method`) both when the customer picks a method and when the component loads with a preselected one. They are therefore only used to remember the current selection.
- The events are pushed on `checkout:validation:success` (fires when the customer moves to the next step or places the order): `add_shipping_info` with `shipping_tier` = shipping method code, `add_payment_info` with `payment_type` = payment method code, plus the checkout's currency/value/items. This covers multi-step, mobile and one-page checkouts and customers who keep the preselected default. Identical consecutive pushes are dropped.
- Local test: events fire once per submission (not on load or on every toggle), carry the right method codes, and work for guest/logged-in, virtual carts (payment only), and the `default`, `mobile` and `onepage` checkouts.

---

## Phase 2: Server-side purchase event

The core new work: firing `purchase_server` (configurable name) via MP from the order-placement lifecycle, independent of the browser.

| Feature | Work |
|---|---|
| **Server-side purchase event** | Observer on `sales_order_place_after` (or `checkout_submit_all_after`) builds the payload via `PurchaseEventBuilder` and enqueues it via `PurchaseQueue` — never calls the MP client synchronously in the observer, so a slow/unreachable Google API cannot delay order placement or the checkout response |
| Currency handling | **Current implementation:** the purchase is tracked in the currency of the order (or optionally the store's base currency), always paired with matching totals. **Future improvement:** let the admin configure one *reporting currency* for all server-side purchases. Example: a store selling in EU and US whose main market is the EU wants every order tracked in EUR instead of a EUR + USD mix. That needs a conversion step (Magento's currency rates for the order date, or a fixed rate set in the backend) applied to `value`, `tax`, `shipping`, item prices and discounts, and the same choice should be considered for the client-side events so the two signals stay comparable. Not started. |
| Tax/shipping handling in totals | Reads order tax/shipping/grand total with a config option for inclusive vs. exclusive totals |
| Exclude zero-value orders | Config flag checked before enqueueing. Since 2026-10-21 a General setting that also suppresses the client-side `purchase` (user decision) |
| Exclude orders by status | Config multiselect of order statuses to skip (e.g. suspected-fraud) |
| Exclude orders by payment method | Config multiselect of payment methods to skip (e.g. financing/offline methods that shouldn't count as GA4 revenue) |
| Track offline/admin-created orders | Same `sales_order_place_after` hook covers admin-created orders too (these have no browser session and were previously untrackable at all); add a config toggle to include or exclude them |
| Multi-category product handling | `PurchaseEventBuilder` resolves the full category path (up to `item_category5`) from each order item's product |
| Variant / parent-child SKU handling | Config toggle for parent vs. child SKU as `item_id`, and `item_variant` resolution from the order item's selected options. Since 2026-10-21 the client-side events use the same rules (`Service/ItemDataResolver`): same `item_id`, `item_variant`, category path and per-item discount everywhere; bundles always use their own catalog SKU |
| Excluded order statuses = hold (2026-10-21, user decision) | A purchase whose order status is excluded at placement is built and stored as `waiting`; `sales_order_save_after` releases it into the queue when the order reaches a status that is not excluded (`Service/MeasurementProtocol/HoldPolicy`, `Model/PurchaseReleaser`); never released for canceled / closed / pending_payment / payment_review states. Follow-ups skipped meanwhile are re-queued |
| Consent gate | The queue consumer checks the same consent-approved flag/cookie already used to gate the client-side GTM snippet before sending — GDPR compliance must hold for the server-side send too, not just the browser script. If consent was declined, the enqueued payload is dropped, not retried |
| No beacon, no other events | This phase covers `purchase` only. `view_item`, `view_item_list`, `search`, `select_item`, `add_shipping_info`, `add_payment_info`, and everything else stay exactly as they are today (client-side dataLayer pushes) and are explicitly out of scope for server-side tracking |

## Phase 3: Order-lifecycle follow-ups (refunds, cancellations)

**Status: implemented (2026-10-20), awaiting local verification.** Reuses the Phase 0 queue, client and cron.

| Feature | Implementation |
|---|---|
| Refund tracking | `Observer/EnqueueServerSideRefund` on `sales_order_creditmemo_save_after` → `Model/FollowUpEnqueuer::enqueueRefund`. One row per credit memo (`reference_id` = credit memo id, part of the unique key, so re-saves do not duplicate). Event `refund_server` (configurable) with `transaction_id`, refunded `value` (same currency and tax settings as the purchase), refunded `items`, `refund_type` = `full` / `partial`. |
| Cancellation | `Observer/EnqueueServerSideCancellation` on `order_cancel_after` → event `cancel_server` (configurable) with the order value. |
| Attribution and consent | Follow-ups copy `client_id` / `user_id` from the stored purchase payload, so they belong to the same visitor (or the same anonymous id), and are only queued when the purchase row is `pending`, `processing` or `sent` (`Service/MeasurementProtocol/FollowUpPolicy`); otherwise a `skipped` row records why. No `timestamp_micros`: they happen now. |
| Manual / mass re-send | Order grid mass action *Re-send GA4 server-side purchase* (`Controller/Adminhtml/Serverside/MassResend`, ACL `MageOS_GoogleTagManager::server_side_resend`, `Model/PurchaseResender`, `Service/MeasurementProtocol/ResendPolicy`). `failed` / `expired` rows are reset with the stored payload minus `timestamp_micros` (Google would reject old timestamps; the event is dated at sending). Orders without a row (placed before the feature was enabled) get one built now, with consent *unknown* (configured action applies) and the real order date if Google still accepts it. `sent`, queued and `skipped` rows are left alone. |

## Phase 4: Admin visibility and reconciliation

**Status: implemented (2026-10-20), awaiting local verification.**

| Feature | Implementation |
|---|---|
| Order grid column | *GA4 Server-side Purchase* in Sales > Orders (`view/adminhtml/ui_component/sales_order_grid.xml`, filterable select). `Plugin/OrderGridDeliveryStatus` joins the queue table as a derived table exposing only `mageos_gtm_order_id` / `mageos_gtm_status`, so the grid's own unqualified columns (status, created_at, ...) never become ambiguous. |
| Event log (replaces the reconciliation report) | Reports > Marketing > *GA4 Server-side Events*: a standard admin UI grid (`view/adminhtml/ui_component/mageos_gtm_server_event_listing.xml`, `Model/ResourceModel/ServerEvent/Grid/Collection`) with one row per server-side event: order date, order # (link to the order), event, status (Sent / Pending / Error / Skipped) and the error or skip reason; filters, paging, newest first. Changed on user request (2026-10-20): the earlier per-day summary table was replaced by this simpler list. Comparing with the client-side `purchase` is done in a GA4 Exploration by transaction id (Magento cannot know whether the browser event fired). |
| MP debug mode | Done in Phase 0 (validation endpoint + log). Added **Show in GA4 DebugView**: adds `debug_mode=1` at send time so recorded server events appear in DebugView. |
| Retention | Daily cron `mageos_gtm_cleanup` deletes `sent` / `skipped` rows older than *Keep Sent/Skipped Rows* days (default 90, 0 = forever). `failed` / `expired` rows are kept for review. |

## Phase 5: Module documentation (install, configure, set up Google accounts)

Basic documentation, written for someone who has a fresh Magento 2.4.9 + Hyvä + Hyvä Checkout store and **no** Google Analytics or Tag Manager account yet. It lives in the repo (`README.md` for the overview and install, `docs/` for the longer guides) and replaces the current README configuration section, which is a three-line stub. It must be written alongside each phase (not at the end) so the local acceptance test can run from it.

| Document | Content |
|---|---|
| **Installation** | Requirements (Magento 2.4.9, PHP version, Hyvä theme, Hyvä Checkout, a cookie banner, `liquid/liquid`); `composer require` plus the path/VCS repository option for pre-release testing; `module:enable`, `setup:upgrade`, `di:compile`, `setup:static-content:deploy`, `cache:flush`; cron/queue consumer note for server-side purchase; uninstall steps |
| **Creating the Google accounts** | Step by step: (1) create a Google Analytics account and a **GA4 property**; (2) add a **Web data stream** for the store URL and copy the **Measurement ID** (`G-XXXXXXXXXX`); (3) create a **Measurement Protocol API secret** (Data stream > Measurement Protocol API secrets); (4) create a Tag Manager account and a **Web container** and copy the **Container ID** (`GTM-XXXXXX`); (5) optionally link nothing else, since Ads and other platforms are out of scope |
| **Which ID goes where** | Table mapping each Google value to its Magento field (see below) |
| **GTM container setup** | Use the admin **Configuration Export** button to download the generated JSON; in GTM go to Admin > Import Container, choose the file, pick a workspace, select *Merge* (not overwrite) on a fresh container; review the imported tags/triggers/variables; **Preview** and then **Submit/Publish** |
| **Verifying it works** | GTM Preview mode, GA4 Admin > DebugView and Reports > Realtime; what to expect for each event, including `purchase` and `purchase_server`; how to compare the two for the same `transaction_id` |
| **Consent behavior** | The *Cookie Consent* settings (cookie name, accepted value, fallback events, require consent), what they gate, and why events do not appear until consent is accepted |
| **Server-side purchase** | State up front that `purchase_server` is a comparison-only event, not counted as revenue in standard GA4 reports, and that the GTM/GA4 admin decides manually whether to use it. Then: what it is, why it has a separate event name, the config fields, the delivery status in the order grid, retrying via mass action, debug mode, and the reporting caveat that unique revenue is the union of both events' `transaction_id`s |
| **Troubleshooting** | No GTM snippet (module disabled / wrong store scope / full page cache), no events (consent not given, ad blocker), `purchase_server` stuck pending (cron or consumer not running), MP 2xx but nothing in reports (wrong Measurement ID or API secret; use debug endpoint), revenue shown as 0 (currency) |

### IDs to copy from Google into Magento

Configuration path: **Stores > Configuration > Mage-OS > Google Tag Manager** (store-view scope supported).

| Google value | Where it comes from | Magento field | Status |
|---|---|---|---|
| GTM **Container ID** `GTM-XXXXXX` | Tag Manager > Workspace, top right of the container (or Admin > Container Settings) | General > **Google Tag ID** (`tag_id`) | exists today |
| GA4 **Measurement ID** `G-XXXXXXXXXX` | Analytics > Admin > Data streams > select the web stream | General > **GA4 Measurement ID** (`ga4_measurement_id`) | exists today |
| GA4 **Measurement Protocol API secret** | Analytics > Admin > Data streams > select stream > Measurement Protocol API secrets > Create | Server-side > **API Secret** (stored encrypted) | new, Phase 0 |
| GA4 Measurement ID (for MP) | same as above | Server-side > **Measurement ID** (may default to the General value) | new, Phase 0 |

Notes the docs must make explicit:
- The Container ID and the GA4 Measurement ID are different things: the GTM container loads on the page, and the GA4 tag inside it (imported from the export) uses the Measurement ID. The README currently mixes these up.
- The Measurement ID and the API secret must belong to the **same data stream**, otherwise Google accepts the hit but it never shows in reports.
- Do not paste the GTM Container ID into the GA4 field or the reverse; add field validation (`GTM-` and `G-` prefix patterns) to the admin config in Phase 0 so the tester gets an immediate error instead of silent no-data.

### Documentation acceptance

A person who has never set up GA4/GTM follows only the docs on the clean local install and reaches a working state (events visible in GA4 DebugView and a server-side purchase received). Anything they have to guess or look up elsewhere is a docs bug and is fixed before release.

---

## Client-side fixes outside the original phases (2026-10-20)

Found while reviewing the existing module; fixed because they distort the data the comparison relies on:
- `items` was keyed by SKU and nested one level too deep; it is now a flat GA4 list, one entry per visible cart/order row with the row price (child rows skipped). The purchase used `getQty()` on order items (does not exist) and now uses the ordered quantity.
- Client-side `purchase` used the store's current display currency with the order's totals; it now uses the order currency.
- Double `page_view`: `head.phtml` no longer calls `gtag('config')`; GA4 is configured only by the container's GA4 Configuration tag.
- Exported GTM triggers were *Window Loaded* + `ga4_event` filters, which miss events pushed after load; every event trigger is now a *Custom Event* on the event name, and the GA4 Configuration tag fires on *All Pages*. Containers must be re-imported.
- `view_item` (product page), `view_item_list` (category and search results) and `search` are now pushed (`Block/CatalogTagManager`, `Service/CatalogService`), so the tags already in the export are no longer idle. Still client-side only, per the architecture rule.

## Explicit non-goals of this plan

- **Multishipping** (checkout with multiple addresses) is not supported or tested (user decision 2026-10-21; Hyvä has no multishipping UI). The server side happens to create one purchase per resulting order; no client-side events exist for it.

- **No server-side tracking for any event other than purchase** (and optionally refunds/cancellations in Phase 3, which are order-lifecycle siblings of purchase). `view_item`, `view_item_list`, `select_item`, `search`, `add_shipping_info`, `add_payment_info`, cart events, login, sign_up, and wishlist all remain client-side only, unchanged from how the module already implements them.
- **No cache-observability workaround (beacon endpoint, endpoint-naming obfuscation) is needed or built.** That entire class of problem only existed because earlier drafts of this plan tried to move browsing-stage events server-side; with those events staying client-side, there is nothing server-side that a Varnish/FPC cache hit could interfere with.
- **No automatic GA4-side deduplication between `purchase` and `purchase_server`.** This is intentional — see the architecture rule above. If a future requirement calls for a single deduplicated "true purchase count," that would be a reporting-layer (GA4 Exploration) concern, not something this module needs to solve by merging the two events.
- **Frontend/theme work is out of scope.** Hyvä Checkout step wiring, CSP, deferred/lazy loading, and other purely client-side concerns for the events that remain client-side are a separate workstream.
- **Google Ads, other ad platforms, GTM API automation, and consent-platform integrations** are not covered by this plan — it is scoped strictly to closing the purchase-tracking reliability gap.

## Suggested build order

1. **Phase 0** — Measurement Protocol foundation, scoped to purchase only, with the async queue+retry as a first-class requirement (not an afterthought) since it's what guarantees checkout is never blocked.
2. **Phase 1** — Client-side purchase event name/dedup fix. Small, low-risk, can ship independently and immediately.
3. **Phase 2** — Server-side purchase event itself. The core deliverable of this plan.
4. **Phase 4** — Admin visibility (order grid column, MP debug mode) — needed early enough to validate Phase 2 is actually delivering in production, not after the fact.
5. **Phase 3** — Refunds/cancellations, as a natural follow-on once the purchase pipeline is proven reliable.
6. **Phase 5** — Documentation is drafted incrementally with each phase above (install and Google-account setup guides can be written immediately, in parallel with Phase 0/1), and finalized before the local Magento 2.4.9 + Hyvä acceptance run described in "Target test environment and acceptance testing". Unit tests are written alongside the code for everything Magento-free (the earlier "defer tests" note was superseded).
