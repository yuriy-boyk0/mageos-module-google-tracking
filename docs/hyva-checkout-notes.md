# Hyvä Checkout notes (for tracking work)

Facts read from the Hyvä Checkout **1.3.15** source (`hyva-themes/magento2-hyva-checkout`, provided by the user as a zip, extracted in cloud sessions to `/home/user/hyva/src/hyva-themes/`; commercial code, never commit it) and the Hyvä theme modules in the same zip. Re-check against the installed version when upgrading; paths below are relative to `magento2-hyva-checkout/src/`.

## Page and layout structure
- The checkout page gets the layout handle `hyva_checkout` (Model/Config/Converter.php), plus `hyva_checkout_<checkout name>` and `hyva_checkout_<checkout name>_<step>`. This module's `view/frontend/layout/hyva_checkout.xml` therefore applies to every Hyvä Checkout variant.
- Built-in checkouts (etc/hyva_checkout.xml): `default` (steps `login`, `shipping`, `payment`), `mobile` (adds `summary`), `onepage` (single step `onepage`). Merchants can define more.
- The success page is Magento's normal `checkout/onepage/success`, so `checkout_onepage_success.xml` (the purchase event) applies. Hyvä only adds its own `hyva_checkout_onepage_success.xml` layout on top.
- Orders are placed through `Magento\Quote\Api\CartManagementInterface::placeOrder()` (Model/Magewire/Payment/AbstractPlaceOrderService.php), so `checkout_submit_all_after` fires, inside a Magewire AJAX request that carries the visitor's cookies.

## Frontend events (all dispatched on `window` as `CustomEvent`, payload in `event.detail`)
| Event | Detail | When |
|---|---|---|
| `checkout:init:*` (`api`, `main`, `config`, `storage`, `navigation`, `payment`, `shipping`, `order`, ... `done`) | none | API initialisation phases |
| `checkout:step:loaded` | step data (`name`, `route`, `label`, `position`) plus `subsequent` (false on page load, true after navigation) | A step is shown |
| `checkout:navigation:success` | step data of the new step | After a successful step change (server dispatched) |
| `checkout:shipping:method-activate` | `method` = carrier_method code, e.g. `flatrate_flatrate` | Customer picks a shipping method **and** when the component loads with a method already on the quote |
| `checkout:payment:method-activate` | `method` = payment code, e.g. `checkmo` | Same two cases for payment |
| `checkout:validation:success` / `:failure` / `:exception` | `step` (step name), `options` (`executeAfter`), `results` | After `hyvaCheckout.validation.validate()`, which runs when navigating forward and when placing the order (and again with `executeAfter: true` after the order was placed) |
| `checkout:payment:method-activate:deprecated`, `payment:method:registered`, `shipping:method:update`, ... | | Internal; do not depend on them |

Magewire (server → browser) events: `shipping_method_selected` (`method`, `carrier`, `code`) and `payment_method_selected` (`method`) are emitted by `Magewire/Checkout/Shipping/MethodList.php` and `Payment/MethodList.php` only when the customer changes the selection (`updatedMethod`), not on load.

JS API (global `hyvaCheckout`): `hyvaCheckout.navigation.addTask(fn, {name, executeAfter, navigationDirections, stackPosition})` runs code before/after forward navigation and when placing the order; `hyvaCheckout.api.after(cb)` waits for initialisation. Prefer the events above; tasks are cleared after navigation and must be re-registered.

## How this module uses them (view/frontend/templates/gtm/checkout-steps.phtml)
- The two activate events only report the **current selection**. They are stored, not pushed, because they also fire on load for a preselected default method.
- `add_shipping_info` / `add_payment_info` are pushed on `checkout:validation:success` (ignoring `executeAfter`): that is the moment the customer submits a step or places the order. Multi-step checkouts push shipping when leaving the shipping step and payment when placing the order; one-page checkouts push both at order placement. Identical consecutive pushes are dropped, so repeated validations do not duplicate.
- Known limitation: the validation event fires before the server confirms the step change, so a navigation that is rejected after validation (rare) would still have pushed.

## Cookie consent facts (Hyvä theme)
- `user_allowed_save_cookie` is Magento's cookie (`Magento\Cookie\Helper\Cookie::IS_USER_ALLOWED_SAVE_COOKIE`); the Hyvä banner (`Magento_Cookie/templates/notices.phtml`) sets it to a JSON map `{"<websiteId>": 1}` from `getAcceptedSaveCookiesWebsiteIds()`. The server-side `ConsentEvaluator` parses exactly this.
- The banner and cookie exist only when Magento's **cookie restriction mode** is enabled (Stores > Configuration > General > Web > Default Cookie Settings). With it off there is no consent cookie, and a browser order is then treated as "consent declined".
- This is a generic "may save cookies" consent. The module's defaults match it (*Consent Cookie Name* `user_allowed_save_cookie`, fallback event `user-allowed-save-cookie`, which `notices.phtml` dispatches on accept). Per-category banners are connected through the *Cookie Consent* settings (cookie name, "accepted" value, events).
