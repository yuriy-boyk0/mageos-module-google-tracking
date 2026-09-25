# MageOS_GoogleTagManager

The **MageOS_GoogleTagManager** module integrates **Google Tag Manager (GTM)** with **Google Analytics 4 (GA4)** to enable advanced e-commerce tracking in Hyvä-based Magento stores.

It provides a structured data layer and event tracking system tailored for performance, scalability, and compliance.

---

## Overview

This module is designed specifically for **Hyvä themes**, ensuring lightweight and efficient tracking of user interactions across the storefront.

Tracking is initialized **only after user consent is granted**, thanks to its dependency on the `MageOS_CookieConsent` module.

---

## Features

### User Interaction

* Page impressions (page views)
* Click tracking
* Scroll tracking

### Cart Events

* Product added to cart
* Product removed from cart
* Cart item quantity updates

### Wishlist Events

* Product added to wishlist
* Product removed from wishlist

### Checkout & Purchase

* Checkout initiation
* Completed purchases (transaction tracking)

---

## Key Characteristics

* Built specifically for **Hyvä frontend architecture**
* Compatible with **Google Tag Manager (GTM)**
* Implements **GA4 e-commerce tracking standards**
* Structured **dataLayer** event system
* Cookie-compliant tracking via `MageOS_CookieConsent`
* Lightweight and performance-oriented

---

## Installation

### 1. Install via Composer

```bash
composer require mage-os/module-google-tag-manager
```

### 2. Enable the Module

```bash
bin/magento module:enable MageOS_GoogleTagManager
```

### 3. Run Setup Upgrade

```bash
bin/magento setup:upgrade
```

### 4. Deploy Static Content (if needed)

```bash
bin/magento setup:static-content:deploy
```

### 5. Clear Cache

```bash
bin/magento cache:flush
```

---

## Configuration

1. Configure your **Google Tag Manager container ID** in Magento admin (if applicable).
2. Ensure your **GA4 tags** are properly set up inside GTM.
3. Map GTM triggers to the dataLayer events emitted by this module.

---

## Consent Handling

Tracking is **disabled by default**.

Events will only be pushed to the dataLayer after the user accepts **third-party tracking cookies**, managed by the `MageOS_CookieConsent` module.

This ensures compliance with privacy regulations such as GDPR.

---

## Data Layer & Events

The module pushes structured events to the `dataLayer`, which can be consumed by GTM.

Typical events include:

* `page_view`
* `add_to_cart`
* `remove_from_cart`
* `update_cart`
* `add_to_wishlist`
* `remove_from_wishlist`
* `begin_checkout`
* `purchase`

Make sure your GTM configuration listens for these events and forwards them to GA4 accordingly.

---

## Notes

* Ensure GTM is properly installed in your theme.
* Verify events using GTM Preview mode.
* Extend or customize events if needed for your business logic.