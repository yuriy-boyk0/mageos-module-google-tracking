# MageOS_GoogleTagManager

Google Tag Manager (GTM) and Google Analytics 4 (GA4) e-commerce tracking for **Hyvä** based Magento / Mage-OS stores.

The module injects the GTM container (after cookie consent), pushes GA4 e-commerce events to the `dataLayer`, and can export a ready-made GTM container configuration that reads those events and forwards them to GA4.

> Setting up Google from scratch? Follow **[docs/google-setup.md](docs/google-setup.md)**: it covers creating the GA4 property and GTM container, and exactly which IDs go in which Magento field.

---

## Requirements

* Magento Open Source / Mage-OS 2.4.x
* [Hyvä Theme](https://hyva.io) (storefront templates are Hyvä-only; Luma is not supported)
* [Hyvä Checkout](https://hyva.io/hyva-checkout.html) for the checkout events (`begin_checkout`, `add_shipping_info`, `add_payment_info`)
* A cookie consent banner: Hyvä's built-in banner (Magento *Cookie Restriction Mode*) works out of the box; any other banner can be connected by cookie name and/or browser event (see *Cookie consent* below). No consent module is required.
* `hyva-themes/magento2-theme-module`
* `liquid/liquid` (used by the GTM export)

These are declared in `composer.json` and installed automatically by Composer.

---

## Installation

### 1. Install via Composer

```bash
composer require mage-os/module-google-tag-manager
```

To test a local copy instead, add a path repository to the project's `composer.json` first:

```bash
composer config repositories.mageos-gtm path /path/to/mageos-module-google-tracking
composer require mage-os/module-google-tag-manager:@dev
```

### 2. Enable the module and update the database

```bash
bin/magento module:enable MageOS_GoogleTagManager
bin/magento setup:upgrade
```

### 3. Compile and deploy (production mode only)

```bash
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
```

### 4. Clear the cache

```bash
bin/magento cache:flush
```

---

## Configuration

Go to **Stores > Configuration > Mage-OS > Google Tag Manager**. Use the store view scope if different store views need different containers.

| Field | Value | Example |
|---|---|---|
| Enable | Yes | |
| Google Tag ID | GTM **Container ID** | `GTM-XXXXXX` |
| GA4 Measurement ID | GA4 web data stream **Measurement ID** | `G-XXXXXXXXXX` |

The module is **disabled by default**. Where to find these IDs in Google is explained in [docs/google-setup.md](docs/google-setup.md).

### Export the GTM container

Under **Configuration Export**, click the export button (the module must be enabled and the GA4 Measurement ID saved). The file `mage-os_gtm_configuration.json` is downloaded. It contains the GA4 configuration tag (using your Measurement ID), one GA4 event tag per event, and the matching triggers and variables. Import it into your GTM container as described in [docs/google-setup.md](docs/google-setup.md#4-import-the-container-configuration-into-gtm).

---

## Cookie consent

GTM is **not loaded** until the visitor has accepted cookies. Until then nothing is sent to Google, and no events are visible in GTM Preview or GA4. Configure it under **Stores > Configuration > Mage-OS > Google Tag Manager > Cookie Consent**:

| Field | Default | Meaning |
|---|---|---|
| Require Consent | Yes | No = GTM always loads (only where tracking needs no consent) |
| Consent Cookie Name | `user_allowed_save_cookie` | Cookie the banner writes when the visitor accepts. The default is Magento's own cookie, written by Hyvä's built-in banner (enable *Stores > Configuration > General > Web > Default Cookie Settings > Cookie Restriction Mode*); its value is `{"<websiteId>":1}` |
| Cookie Value Meaning "Accepted" | empty | Optional text the cookie must contain, e.g. `statistics:true` for Cookiebot's `CookieConsent` cookie. Empty = automatic: a JSON map needs a truthy entry for the current website ID; a plain value must be `1`, `true`, `yes`, `granted`, `accepted` or `allow` |
| Consent Event Names (fallback) | `user-allowed-save-cookie` | Comma-separated window events that mean "accepted", for banners that announce consent with an event (Hyvä's banner fires `user-allowed-save-cookie` the moment the visitor accepts). GTM then loads on that page, unless the consent cookie is present and says "declined" |

The same cookie rules decide server-side whether `purchase_server` may use the visitor's GA identifiers. With an event-only banner (no cookie name), the server cannot see consent and applies *When Consent Cannot Be Determined*. Without a consent requirement the GTM `<noscript>` iframe is also output; with consent required it is not, since a visitor without JavaScript cannot consent.

---

## Google Consent Mode v2

Setting: **Cookie Consent > Google Consent Mode v2** (default **Off**). Consent Mode is Google's standard way to tell its tags what the visitor allowed, with four signals: `analytics_storage` (Analytics cookies), `ad_storage` (advertising cookies), `ad_user_data` (send user data to Google for ads) and `ad_personalization` (personalised ads / remarketing). Each is `granted` or `denied`.

| Option | Before consent | After consent | Use it when |
|---|---|---|---|
| **Off** | GTM is not loaded, no signals | GTM loads, no signals (Google treats tags as fully consented) | You do not use Google Ads in the EEA and do not need Consent Mode; this is the original behaviour |
| **Basic** | GTM is not loaded; the four signals are set to `denied` | Signals updated to `granted`, then GTM loads | You want Consent Mode reported to Google (required for Google Ads features with EEA traffic since March 2024) while nothing reaches Google before consent. Recommended default for EU stores |
| **Advanced** | GTM **loads at once** with all signals `denied`: Google tags set no cookies and send anonymous "cookieless pings" | Signals updated to `granted`, tags work normally | You want GA4 to **model** the visitors who decline (behavioural / conversion modelling, needs enough traffic). Note: requests reach Google before consent; some EU data-protection authorities consider that problematic, so check with your privacy adviser first |

Related settings:
- **Signals Granted on Consent**: *All signals* (default), or *Analytics only*: `analytics_storage` becomes `granted`, the three ad signals stay `denied` (stores that do not use Google Ads).
- **Banner Sends Consent Mode Signals**: set to Yes if your banner (Cookiebot, OneTrust, Usercentrics, ...) already sends Consent Mode commands itself. The module then sends none and only decides when GTM loads (Basic: after consent, Advanced: at once). Point *Consent Cookie Name* / *Cookie Value Meaning "Accepted"* at the banner's cookie so the server side reads the same decision.
- Consent itself is detected exactly as before (consent cookie / fallback events, section *Cookie consent*).
- **Server side**: with Basic or Advanced, `purchase_server`, `refund_server` and `cancel_server` carry the Measurement Protocol `consent` object (`ad_user_data`, `ad_personalization`): `GRANTED` when the visitor accepted and all signals are granted, otherwise `DENIED` (also for anonymous sends). Visitors who declined are still never sent server-side.

---

## Events pushed to the dataLayer

| Event | When |
|---|---|
| `view_item` | Product page viewed |
| `view_item_list` | Category page or search results viewed (products of the current page, up to 50, with `item_list_id` / `item_list_name`) |
| `select_item` | Click on a product link in a category / search listing (with `item_list_id`, `item_list_name`, `index`); see *Item list attribution* |
| `search` | Search results page (`search_term`) |
| `view_promotion` / `select_promotion` | A banner or link marked up as a promotion becomes visible / is clicked; see *Promotions* |
| `add_to_cart` | Product added to cart. A **grouped** product sends one item per child added (child SKU, price, quantity), not the group. Several adds before the next page load are combined into one event with all items and the total `value` |
| `remove_from_cart` | Product removed from cart |
| `update_cart` | Cart item quantities updated |
| `view_cart` | Cart page viewed |
| `begin_checkout` | Hyvä Checkout opened |
| `add_shipping_info` / `add_payment_info` | Hyvä Checkout: the customer submits the step / places the order with a shipping or payment method selected (`shipping_tier`, `payment_type` = method codes) |
| `purchase` | Checkout success page, **once per order** (a reload or back navigation does not push it again) |
| `add_to_wishlist` / `remove_from_wishlist` | Wishlist changes |
| `login` / `logout` / `sign_up` | Customer account events |

`page_view` is sent by the GA4 configuration tag of the exported GTM container (on *All Pages*), not by the module.

All ecommerce events describe a product the same way as the server-side purchase: the same `item_id` (see *Item ID for Configurable Products*), `item_variant` for configurable options, the category path in `item_category` … `item_category5`, and a positive per-unit `discount` on the item (no event-level discount). All ecommerce events carry a flat GA4 `items` list (one entry per visible cart/order row, with the price actually paid), and `purchase` uses the order's own currency. The exported container fires every GA4 event tag on a **Custom Event** trigger matching the dataLayer event name, so events pushed after the page has loaded (cart, wishlist, checkout steps) are picked up. **If you imported the container before this version, export and import it again** (the 2026-10-21 version adds tags for `select_item`, `view_promotion`, `select_promotion`, list fields on `view_item_list`, and fixes the search term variable) (GTM: Import Container > Merge > Overwrite conflicting tags).

`view_item`, `view_item_list` and `search` are rendered into the page HTML, which the full page cache stores and serves to every visitor of that page; they therefore only contain catalog data (prices as shown for the visitor's customer group, which the cache already varies by).

---

## Item list attribution (`select_item`)

On category and search result pages, a click on a product link pushes `select_item` with the list (`item_list_id` = `category_<id>` or `search_results`, `item_list_name`) and the product's position (`index`). The module remembers the list for that product in the browser (7 days, last 100 products) and adds it to the product's items in later `add_to_cart` and client `purchase` events, which feeds GA4's item list reports. A click on the listing's own *Add to Cart* button also remembers the list (without `select_item`). Works with Hyvä's product list markup (each item is a form with the hidden input `product`). Limits: other lists (related products, widgets) are not tracked; with *Item ID for Configurable Products* = Child, an `add_to_cart` of a configurable uses the variant SKU and therefore does not pick up the list remembered for the parent.

## Promotions (`view_promotion`, `select_promotion`)

Mark banners or links in CMS content with data attributes; nothing else is needed:

```html
<a href="/sale"
   data-gtm-promotion-id="SUMMER26"
   data-gtm-promotion-name="Summer sale"
   data-gtm-creative-name="hero-banner"
   data-gtm-creative-slot="home-top"
   data-gtm-promotion-items="MH01,WS12">...</a>
```

`view_promotion` is pushed once per element when at least half of it is visible, `select_promotion` on click. Only `data-gtm-promotion-id` or `data-gtm-promotion-name` is required; `data-gtm-promotion-items` (comma-separated SKUs) is optional. WeltPixel's attributes `data-track-promo-id`, `-name`, `-creative`, `-position` are understood as well, so existing content keeps working after a switch. Elements are collected once when the page has loaded; content inserted later (e.g. by a slider after load) is not observed.

## Brand and affiliation

- **Brand Attribute** (General): product attribute code sent as `item_brand` on every item, client and server side (e.g. `manufacturer`). For listings, set the attribute's *Used in Product Listing* = Yes; listings never load products one by one, so without it they send no brand.
- **Affiliation** (General): text sent as `affiliation` on every item, e.g. your store name, to tell stores apart in one GA4 property.

## Debugging

Set **Allow dataLayer Debug Logging** = Yes (General). Then open any storefront page with `?gtm_debug=1`: every dataLayer push (events, ecommerce resets, Consent Mode commands) is logged to the browser console, in that browser only, until you open a page with `?gtm_debug=0`. Other visitors see nothing, and the full page cache is not affected. Use GTM Preview and GA4 DebugView for what reaches Google.

---

## Server-side purchase tracking (`purchase_server`, refunds, cancellations)

Optional. When an order is placed, Magento queues a second purchase event and a cron job sends it to GA4 through the Measurement Protocol, independent of whether the visitor's browser ever reaches the success page. It uses the event name `purchase_server` (configurable), **not** `purchase`.

It is a **comparison signal**: it is not counted as revenue in standard GA4 reports. Compare `purchase` and `purchase_server` for the same `transaction_id` (for example in a GA4 Exploration) to see how many orders client-side tracking misses because of ad blockers or abandoned tabs. Unique revenue is the union of the transaction IDs seen on either event, not their sum. Whether to use it beyond that comparison is up to whoever manages the GA4/GTM account.

Setup: **Stores > Configuration > Mage-OS > Google Tag Manager > Server-side Purchase Tracking**. You need a Measurement Protocol **API secret** from the same GA4 data stream as the Measurement ID (see [docs/google-setup.md](docs/google-setup.md)). Magento **cron must be running**.

* Checkout is never blocked or slowed: the order only inserts a row into `mageos_gtm_server_event`; sending happens in cron with retries (growing delays, configurable maximum).
* Delivery status per order (`pending`, `sent`, `failed`, `expired`, `waiting`, `skipped` with the reason) is stored in that table.
* Visitors who declined tracking cookies are never sent. When consent cannot be determined (admin-created orders, payment-gateway callbacks) the default is to send **anonymously** (no visitor identifiers); change it under *When Consent Cannot Be Determined*.
* Exclusions: payment methods, admin-created orders, zero-value orders (the general setting *Exclude Zero-value Orders*, which also suppresses the client-side `purchase`), and order statuses.
* **Excluded order statuses are a hold, not a drop.** An order placed with an excluded status (typically `pending_payment` of redirect payment methods such as Stripe Checkout) gets its purchase built at once, with the visitor's consent and GA ids, and stored as `waiting`. As soon as the order reaches a status that is not excluded (payment confirmed, e.g. `processing`), the purchase is queued and sent, dated at that moment; refunds or a cancellation recorded meanwhile follow it. An order that is cancelled, closed or stays in `pending_payment` / `payment_review` is never sent. Add `pending_payment` to *Exclude Order Statuses* to track redirect payments only once they are paid. (The client-side `purchase` on the success page cannot wait for the payment and is sent at placement.)
* **Tax in Totals**: only affects `value`: the order grand total (default, like the client-side event) or the grand total minus tax. Item prices and shipping are always sent without tax and `tax` is always sent separately, so the server-side event describes the order with the same lines as the client-side `purchase`.
* **Item ID for Configurable Products**: send the parent (configurable) SKU (default) or the child (variant) SKU as `item_id`; the selected options are sent as `item_variant` either way. The setting applies to **every** event, client and server side, so a product has the same `item_id` from `view_item` to `purchase` and refund. Bundles always use the bundle's own SKU (not the SKU Magento composes from the selected options). Other product types always send their own SKU.
* An event that could not be delivered within about 71 hours is marked `expired` and never sent (Google rejects older events). The row stays in `mageos_gtm_server_event` with its payload so you can review it.
* Debug Mode sends to Google's validation endpoint and logs the result in `var/log`; nothing is recorded in GA4 while it is on. **Show in GA4 DebugView** instead sends normally and adds `debug_mode`, so the events appear in Analytics > Admin > DebugView (they are recorded).
* **Refunds and cancellations** (on by default when server-side tracking is enabled): every credit memo queues a `refund_server` event (refunded value and items, `refund_type` = `full`/`partial`) and a cancelled order queues a `cancel_server` event (order value). They reuse the purchase's anonymous or consented visitor id, and are only sent when the order's purchase was sent server-side; otherwise they are recorded as skipped. Event names are configurable.
* **Order grid**: Sales > Orders has a column *GA4 Server-side Purchase* (sent, pending, failed, expired, skipped; filterable) and a mass action **Re-send GA4 server-side purchase**. It queues failed and expired orders again (the event is then dated at the time of sending, because Google rejects events older than 72 hours) and creates the event for orders that have none (for example placed before the feature was enabled; sent anonymously, as consent is unknown, unless configured to skip). Already sent, queued or deliberately skipped orders are left alone.
* **Event log**: Reports > Marketing > **GA4 Server-side Events** is a standard admin grid with one row per server-side event: order date, order number (links to the order), event (purchase, refund, cancellation), status (sent, pending, error, skipped) and the error or skip reason. Filter by date, status or event; newest orders first.
* Sent and skipped rows older than 90 days (configurable, 0 = forever) are deleted by a daily cron; failed and expired rows are kept.

---

## Not supported: multishipping

Checkout with multiple addresses (Magento multishipping) is **not supported or tested**. Hyvä has no multishipping templates, so it is normally not available on a Hyvä store at all. If it is enabled anyway, the server side records one purchase per resulting order, but no client-side events are pushed on the multishipping pages or its success page.

---

## Verifying the setup

Use GTM **Preview** mode and GA4 **Admin > DebugView**. See [docs/google-setup.md](docs/google-setup.md#5-verify-that-it-works).

---

## Plans

Design and roadmap: [docs/ga4-gtm-plan.md](docs/ga4-gtm-plan.md). Current progress and what still needs testing: [docs/STATUS.md](docs/STATUS.md). Developers: see [CLAUDE.md](CLAUDE.md) and [docs/local-testing.md](docs/local-testing.md).
