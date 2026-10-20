# Google setup guide: GA4 + GTM + Magento

For someone who has a Magento store with this module installed and **no Google Analytics or Tag Manager account yet**. Google's interface changes from time to time; menu names below match the current layout, but the IDs you need are always the same.

You will end up with two IDs to paste into Magento:

| ID | Looks like | Comes from | Magento field |
|---|---|---|---|
| GTM Container ID | `GTM-XXXXXX` | Tag Manager | **Google Tag ID** |
| GA4 Measurement ID | `G-XXXXXXXXXX` | Analytics | **GA4 Measurement ID** |

They are different things. The GTM container is the script loaded on the page. The GA4 Measurement ID identifies the Analytics data stream that the tags inside the container send data to. Do not swap them.

## 1. Create a GA4 property

1. Sign in at <https://analytics.google.com> with a Google account.
2. If it is a new account, follow *Start measuring*; otherwise go to **Admin** (gear icon, bottom left) > **Create** > **Property**.
3. Enter a property name, reporting time zone and currency (use your store's currency).
4. Choose the business details and objectives you like. They don't affect tracking.

## 2. Create a web data stream and copy the Measurement ID

1. When asked to choose a platform, select **Web**. Later: **Admin > Data streams > Add stream > Web**.
2. Enter the store URL (for example `https://shop.example.com`) and a stream name.
3. Leave *Enhanced measurement* on. Create the stream.
4. On the stream details page copy the **Measurement ID** (`G-XXXXXXXXXX`, top right).

## 3. Create a Tag Manager container and copy the Container ID

1. Go to <https://tagmanager.google.com> and click **Create Account**.
2. Enter an account name and country, then a container name (your store URL), and choose target platform **Web**.
3. Accept the terms. Google shows an install dialog; **you don't need to copy its snippets**, the module injects GTM for you. Close the dialog.
4. The **Container ID** (`GTM-XXXXXX`) is shown at the top of the workspace, next to the container name.

## 3b. (Optional) Create a Measurement Protocol API secret for server-side purchase tracking

Only needed if you enable *Server-side Purchase Tracking* in Magento.

1. In Analytics open **Admin > Data streams** and select the web stream from step 2.
2. Scroll to **Measurement Protocol API secrets** and click **Create**.
3. Enter a nickname (for example `magento`), click **Create**, and copy the **Secret value**.

The secret belongs to this one data stream: it must be used together with that stream's Measurement ID, otherwise Google accepts the data but it never appears in reports.

### Enter the IDs in Magento

1. In the Magento admin open **Stores > Configuration > Mage-OS > Google Tag Manager**.
2. Set **Enable** to *Yes*.
3. Paste the Container ID into **Google Tag ID** and the Measurement ID into **GA4 Measurement ID**. Magento rejects a value in the wrong format (`GTM-…` vs `G-…`).
4. Optional: under **Server-side Purchase Tracking** set **Enable** to *Yes* and paste the **API Secret** from step 3b. Leave *Measurement ID* empty to reuse the one above.
5. Click **Save Config**, then **System > Cache Management** and flush the cache.

## 4. Import the container configuration into GTM

The module generates a container configuration with the GA4 tags, triggers and variables, so you don't need to build them by hand.

1. In Magento, on the same configuration page, open **Configuration Export** and click the export button. The GA4 Measurement ID must already be saved. You get `mage-os_gtm_configuration.json`.
2. In Tag Manager open **Admin > Import Container**.
3. Choose the downloaded file, choose a workspace (*Existing* > Default Workspace), and choose **Merge**. On a brand-new container, either import option gives the same result.
4. Review the tags, triggers and variables to be imported, then confirm.
5. In the workspace, check **Tags**: you should see `GA4 Configuration` plus one tag per event (`GA4 Addtocart`, `GA4 Purchase`, ...).
6. Click **Submit > Publish** to make the container live. Previewing (next step) works before publishing; real visitors only see published versions.

If you change the Measurement ID in Magento later, export again and re-import it (or edit the `GA4 Configuration` tag in GTM).

**Upgrading the module?** Containers imported from older versions used page-load triggers that miss events pushed later (add to cart, checkout steps). Export the container again and import it with *Merge* and *Overwrite conflicting tags, triggers and variables*, then publish. Since 2026-10-20 all exported tag and variable names use the uppercase **GA4** prefix (`GA4-Event`, `GA4-Ecommerce-Items`, `GA4 Viewcart`, ...); older imports had some `Ga4` names. If the import leaves both spellings in the container, delete the old `Ga4…` items (or import with *Overwrite* into a fresh workspace) before publishing.

## 5. Verify that it works

1. **Accept cookies first.** Nothing loads before consent. Open the storefront in a private window, accept the cookie banner for tracking cookies, and check that the Magento config is enabled and cache flushed.
2. In Tag Manager click **Preview**, enter the store URL and connect. In the Tag Assistant window you should see the container load and `GA4 Configuration` fire on the page.
3. In Analytics open **Admin > DebugView** (or **Reports > Realtime**). Browsing the store should show `page_view`.
4. Trigger events and watch them arrive:

   | Action in the store | Event in GA4 |
   |---|---|
   | Open a product page | `view_item` |
   | Open a category, or search | `view_item_list` (and `search`) |
   | Add a product to cart | `add_to_cart` |
   | Change quantity / remove | `update_cart` / `remove_from_cart` |
   | Open the cart page | `view_cart` |
   | Open Hyvä Checkout | `begin_checkout` |
   | Complete an order | `purchase` (with `transaction_id`, value, currency, items) |
   | Log in / out, register | `login` / `logout` / `sign_up` |
   | Add to wishlist | `add_to_wishlist` |
   | Continue from shipping / place order | `add_shipping_info`, `add_payment_info` |

   `add_shipping_info` and `add_payment_info` are sent when you continue from the shipping step and when you place the order, once each (not when the checkout page loads).

5. Reload the order success page. `purchase` must **not** be sent a second time for the same order.
6. Check that `page_view` is counted once per page.

Standard GA4 reports (Monetization) can take up to 24 to 48 hours to show data; DebugView and Realtime are immediate.

## 6. (Optional) Check the server-side purchase

1. Make sure Magento cron is running, then place a test order after accepting the cookie banner.
2. After a minute, open **Analytics > Reports > Realtime** (or the **Explore** section later). You should see both `purchase` (from the browser) and `purchase_server` (from Magento) with the same transaction ID.
3. `purchase_server` is a comparison signal, not revenue: it does not appear in standard Monetization reports. Use a GA4 **Exploration** with the *transaction ID* dimension to compare the two events and see which orders the browser missed.
4. In Magento, **Sales > Orders** shows the status per order in the column *GA4 Server-side Purchase* (add it via *Columns* if hidden): `sent`, `pending`, `failed`, `expired` (too old for Google), `skipped` (no consent or excluded). **Reports > Marketing > GA4 Server-side Events** lists every server-side event (order date, order number, event, status, error) and can be filtered, e.g. by *Status = Error*. Failed, expired and never-tracked orders can be queued again with the order grid action **Re-send GA4 server-side purchase**.
5. To see server-side events in **DebugView**, set *Show in GA4 DebugView* = Yes (events are recorded normally). *Debug Mode* is different: it only validates the payload with Google and records nothing; the result is written to `var/log/system.log`.
6. Refunds (credit memos) and cancellations are sent as `refund_server` and `cancel_server` for orders whose purchase was sent server-side. Like `purchase_server`, they are comparison signals, not GA4's standard refund.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| No GTM script in the page source | Module disabled, wrong store view scope, empty Google Tag ID, or cache not flushed |
| GTM script missing until banner is accepted | Expected: consent is required. Accept tracking cookies |
| GTM loads but nothing arrives in GA4 | Container not published (and Preview not connected), or GA4 Measurement ID wrong |
| Export button says "Module not enabled" / "No information to export" | Enable the module and save a GA4 Measurement ID, then try again |
| Events visible in Preview but not in GA4 | Ad blocker or browser privacy feature blocking Google. Test with a clean browser profile |
| `purchase_server` stays `pending` | Cron is not running, or Google is unreachable (see `last_error`; retries use growing delays) |
| Order grid shows no *GA4 Server-side Purchase* value | No row for that order: placed before the feature was enabled or while it was disabled. Use the *Re-send* mass action |
| `purchase_server` is `expired` | Cron was not running for about 3 days; Google rejects events older than 72 hours, so it is kept for review but never sent |
| `purchase_server` is `skipped` | See `last_error`: consent not given, excluded status/payment method, zero value, or admin order |
| No `begin_checkout` | Hyvä Checkout is required; the default Magento checkout is not wired |
| Revenue is 0 in reports | Check that the order currency is a valid ISO code and the `purchase` event has `value` and `currency` |
