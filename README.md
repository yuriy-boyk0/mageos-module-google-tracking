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

## Verifying the setup

Use GTM **Preview** mode and GA4 **Admin > DebugView**. See [docs/google-setup.md](docs/google-setup.md#5-verify-that-it-works).

---

## Plans

The roadmap for server-side purchase tracking (GA4 Measurement Protocol) is in [docs/ga4-gtm-plan.md](docs/ga4-gtm-plan.md). It is not implemented yet.
