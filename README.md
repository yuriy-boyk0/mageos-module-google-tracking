# MageOS_GoogleTagManager

Google Tag Manager (GTM) and Google Analytics 4 (GA4) e-commerce tracking for **Hyvä** based Magento / Mage-OS stores.

The module injects the GTM container (after cookie consent), pushes GA4 e-commerce events to the `dataLayer`, and can export a ready-made GTM container configuration that reads those events and forwards them to GA4.

> Setting up Google from scratch? Follow **[docs/google-setup.md](docs/google-setup.md)**: it covers creating the GA4 property and GTM container, and exactly which IDs go in which Magento field.

---

## Requirements

* Magento Open Source / Mage-OS 2.4.x
* [Hyvä Theme](https://hyva.io) (storefront templates are Hyvä-only; Luma is not supported)
* [Hyvä Checkout](https://hyva.io/hyva-checkout.html) for the `begin_checkout` event
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

Go to **Stores > Configuration > MageOS > Google Tag Manager**. Use the store view scope if different store views need different containers.

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
| `add_to_cart` | Product added to cart |
| `remove_from_cart` | Product removed from cart |
| `update_cart` | Cart item quantities updated |
| `view_cart` | Cart page viewed |
| `begin_checkout` | Hyvä Checkout opened |
| `purchase` | Checkout success page, **once per order** (a reload or back navigation does not push it again) |
| `add_to_wishlist` / `remove_from_wishlist` | Wishlist changes |
| `login` / `logout` / `sign_up` | Customer account events |

`page_view` is sent by the GA4 configuration tag of the exported GTM container, not by the module. The exported container also contains tags for `view_item`, `view_item_list`, `search`, `add_shipping_info` and `add_payment_info`; the module does not push those events yet, so those tags stay idle. `add_shipping_info` and `add_payment_info` are planned for Hyvä Checkout (see [docs/ga4-gtm-plan.md](docs/ga4-gtm-plan.md), Phase 1b).

---

## Server-side purchase tracking (`purchase_server`)

Optional. When an order is placed, Magento queues a second purchase event and a cron job sends it to GA4 through the Measurement Protocol, independent of whether the visitor's browser ever reaches the success page. It uses the event name `purchase_server` (configurable), **not** `purchase`.

It is a **comparison signal**: it is not counted as revenue in standard GA4 reports. Compare `purchase` and `purchase_server` for the same `transaction_id` (for example in a GA4 Exploration) to see how many orders client-side tracking misses because of ad blockers or abandoned tabs. Unique revenue is the union of the transaction IDs seen on either event, not their sum. Whether to use it beyond that comparison is up to whoever manages the GA4/GTM account.

Setup: **Stores > Configuration > MageOS > Google Tag Manager > Server-side Purchase Tracking**. You need a Measurement Protocol **API secret** from the same GA4 data stream as the Measurement ID (see [docs/google-setup.md](docs/google-setup.md)). Magento **cron must be running**.

* Checkout is never blocked or slowed: the order only inserts a row into `mageos_gtm_server_event`; sending happens in cron with retries (growing delays, configurable maximum).
* Delivery status per order (`pending`, `sent`, `failed`, `expired`, `skipped` with the reason) is stored in that table.
* Visitors who declined tracking cookies are never sent. When consent cannot be determined (admin-created orders, payment-gateway callbacks) the default is to send **anonymously** (no visitor identifiers); change it under *When Consent Cannot Be Determined*.
* Exclusions: zero-value orders, order statuses, payment methods, admin-created orders.
* **Tax in Totals**: send totals including tax (default, order grand total like the client-side event) or excluding tax (grand total minus tax; item prices and shipping without tax). `tax` is always sent separately.
* **Item ID for Configurable Products**: send the parent (configurable) SKU (default) or the child (variant) SKU as `item_id`; the selected options are sent as `item_variant` either way. Other product types always send their own SKU.
* An event that could not be delivered within about 71 hours is marked `expired` and never sent (Google rejects older events). The row stays in `mageos_gtm_server_event` with its payload so you can review it.
* Debug Mode sends to Google's validation endpoint and logs the result in `var/log`; nothing is recorded in GA4 while it is on.

---

## Verifying the setup

Use GTM **Preview** mode and GA4 **Admin > DebugView**. See [docs/google-setup.md](docs/google-setup.md#5-verify-that-it-works).

---

## Plans

Design and roadmap: [docs/ga4-gtm-plan.md](docs/ga4-gtm-plan.md). Current progress and what still needs testing: [docs/STATUS.md](docs/STATUS.md). Developers: see [CLAUDE.md](CLAUDE.md) and [docs/local-testing.md](docs/local-testing.md).
