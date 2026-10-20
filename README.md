# MageOS_GoogleTagManager

Google Tag Manager (GTM) and Google Analytics 4 (GA4) e-commerce tracking for **Hyvä** based Magento / Mage-OS stores.

The module injects the GTM container (after cookie consent), pushes GA4 e-commerce events to the `dataLayer`, and can export a ready-made GTM container configuration that reads those events and forwards them to GA4.

> Setting up Google from scratch? Follow **[docs/google-setup.md](docs/google-setup.md)**: it covers creating the GA4 property and GTM container, and exactly which IDs go in which Magento field.

---

## Requirements

* Magento Open Source / Mage-OS 2.4.x
* [Hyvä Theme](https://hyva.io) (storefront templates are Hyvä-only; Luma is not supported)
* [Hyvä Checkout](https://hyva.io/hyva-checkout.html) for the checkout events (`begin_checkout`, `add_shipping_info`, `add_payment_info`)
* `mage-os/module-cookie-consent` (tracking only starts after consent)
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

## Consent handling

GTM is **not loaded** until the visitor accepts third-party tracking cookies in the `MageOS_CookieConsent` banner, for the current website. Until then nothing is sent to Google, and no events are visible in GTM Preview or GA4.

---

## Events pushed to the dataLayer

| Event | When |
|---|---|
| `view_item` | Product page viewed |
| `view_item_list` | Category page or search results viewed (products of the current page, up to 50, with `item_list_id` / `item_list_name`) |
| `search` | Search results page (`search_term`) |
| `add_to_cart` | Product added to cart |
| `remove_from_cart` | Product removed from cart |
| `update_cart` | Cart item quantities updated |
| `view_cart` | Cart page viewed |
| `begin_checkout` | Hyvä Checkout opened |
| `add_shipping_info` / `add_payment_info` | Hyvä Checkout: the customer submits the step / places the order with a shipping or payment method selected (`shipping_tier`, `payment_type` = method codes) |
| `purchase` | Checkout success page, **once per order** (a reload or back navigation does not push it again) |
| `add_to_wishlist` / `remove_from_wishlist` | Wishlist changes |
| `login` / `logout` / `sign_up` | Customer account events |

`page_view` is sent by the GA4 configuration tag of the exported GTM container (on *All Pages*), not by the module.

All ecommerce events carry a flat GA4 `items` list (one entry per visible cart/order row, with the price actually paid), and `purchase` uses the order's own currency. The exported container fires every GA4 event tag on a **Custom Event** trigger matching the dataLayer event name, so events pushed after the page has loaded (cart, wishlist, checkout steps) are picked up. **If you imported the container before this version, export and import it again** (GTM: Import Container > Merge > Overwrite conflicting tags).

`view_item`, `view_item_list` and `search` are rendered into the page HTML, which the full page cache stores and serves to every visitor of that page; they therefore only contain catalog data (prices as shown for the visitor's customer group, which the cache already varies by).

---

## Server-side purchase tracking (`purchase_server`, refunds, cancellations)

Optional. When an order is placed, Magento queues a second purchase event and a cron job sends it to GA4 through the Measurement Protocol, independent of whether the visitor's browser ever reaches the success page. It uses the event name `purchase_server` (configurable), **not** `purchase`.

It is a **comparison signal**: it is not counted as revenue in standard GA4 reports. Compare `purchase` and `purchase_server` for the same `transaction_id` (for example in a GA4 Exploration) to see how many orders client-side tracking misses because of ad blockers or abandoned tabs. Unique revenue is the union of the transaction IDs seen on either event, not their sum. Whether to use it beyond that comparison is up to whoever manages the GA4/GTM account.

Setup: **Stores > Configuration > Mage-OS > Google Tag Manager > Server-side Purchase Tracking**. You need a Measurement Protocol **API secret** from the same GA4 data stream as the Measurement ID (see [docs/google-setup.md](docs/google-setup.md)). Magento **cron must be running**.

* Checkout is never blocked or slowed: the order only inserts a row into `mageos_gtm_server_event`; sending happens in cron with retries (growing delays, configurable maximum).
* Delivery status per order (`pending`, `sent`, `failed`, `expired`, `skipped` with the reason) is stored in that table.
* Visitors who declined tracking cookies are never sent. When consent cannot be determined (admin-created orders, payment-gateway callbacks) the default is to send **anonymously** (no visitor identifiers); change it under *When Consent Cannot Be Determined*.
* Exclusions: zero-value orders, order statuses, payment methods, admin-created orders.
* **Tax in Totals**: send totals including tax (default, order grand total like the client-side event) or excluding tax (grand total minus tax; item prices and shipping without tax). `tax` is always sent separately.
* **Item ID for Configurable Products**: send the parent (configurable) SKU (default) or the child (variant) SKU as `item_id`; the selected options are sent as `item_variant` either way. Other product types always send their own SKU.
* An event that could not be delivered within about 71 hours is marked `expired` and never sent (Google rejects older events). The row stays in `mageos_gtm_server_event` with its payload so you can review it.
* Debug Mode sends to Google's validation endpoint and logs the result in `var/log`; nothing is recorded in GA4 while it is on. **Show in GA4 DebugView** instead sends normally and adds `debug_mode`, so the events appear in Analytics > Admin > DebugView (they are recorded).
* **Refunds and cancellations** (on by default when server-side tracking is enabled): every credit memo queues a `refund_server` event (refunded value and items, `refund_type` = `full`/`partial`) and a cancelled order queues a `cancel_server` event (order value). They reuse the purchase's anonymous or consented visitor id, and are only sent when the order's purchase was sent server-side; otherwise they are recorded as skipped. Event names are configurable.
* **Order grid**: Sales > Orders has a column *GA4 Server-side Purchase* (sent, pending, failed, expired, skipped; filterable) and a mass action **Re-send GA4 server-side purchase**. It queues failed and expired orders again (the event is then dated at the time of sending, because Google rejects events older than 72 hours) and creates the event for orders that have none (for example placed before the feature was enabled; sent anonymously, as consent is unknown, unless configured to skip). Already sent, queued or deliberately skipped orders are left alone.
* **Event log**: Reports > Marketing > **GA4 Server-side Events** is a standard admin grid with one row per server-side event: order date, order number (links to the order), event (purchase, refund, cancellation), status (sent, pending, error, skipped) and the error or skip reason. Filter by date, status or event; newest orders first.
* Sent and skipped rows older than 90 days (configurable, 0 = forever) are deleted by a daily cron; failed and expired rows are kept.

---

## Verifying the setup

Use GTM **Preview** mode and GA4 **Admin > DebugView**. See [docs/google-setup.md](docs/google-setup.md#5-verify-that-it-works).

---

## Plans

Design and roadmap: [docs/ga4-gtm-plan.md](docs/ga4-gtm-plan.md). Current progress and what still needs testing: [docs/STATUS.md](docs/STATUS.md). Developers: see [CLAUDE.md](CLAUDE.md) and [docs/local-testing.md](docs/local-testing.md).
