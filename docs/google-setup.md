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

### Enter the IDs in Magento

1. In the Magento admin open **Stores > Configuration > MageOS > Google Tag Manager**.
2. Set **Enable** to *Yes*.
3. Paste the Container ID into **Google Tag ID** and the Measurement ID into **GA4 Measurement ID**.
4. Click **Save Config**, then **System > Cache Management** and flush the cache.

## 4. Import the container configuration into GTM

The module generates a container configuration with the GA4 tags, triggers and variables, so you don't need to build them by hand.

1. In Magento, on the same configuration page, open **Configuration Export** and click the export button. The GA4 Measurement ID must already be saved. You get `mage-os_gtm_configuration.json`.
2. In Tag Manager open **Admin > Import Container**.
3. Choose the downloaded file, choose a workspace (*Existing* > Default Workspace), and choose **Merge**. On a brand-new container, either import option gives the same result.
4. Review the tags, triggers and variables to be imported, then confirm.
5. In the workspace, check **Tags**: you should see `GA4 Configuration` plus one tag per event (`GA4 Addtocart`, `GA4 Purchase`, ...).
6. Click **Submit > Publish** to make the container live. Previewing (next step) works before publishing; real visitors only see published versions.

If you change the Measurement ID in Magento later, export again and re-import it (or edit the `GA4 Configuration` tag in GTM).

## 5. Verify that it works

1. **Accept cookies first.** Nothing loads before consent. Open the storefront in a private window, accept the cookie banner for tracking cookies, and check that the Magento config is enabled and cache flushed.
2. In Tag Manager click **Preview**, enter the store URL and connect. In the Tag Assistant window you should see the container load and `GA4 Configuration` fire on the page.
3. In Analytics open **Admin > DebugView** (or **Reports > Realtime**). Browsing the store should show `page_view`.
4. Trigger events and watch them arrive:

   | Action in the store | Event in GA4 |
   |---|---|
   | Add a product to cart | `add_to_cart` |
   | Change quantity / remove | `update_cart` / `remove_from_cart` |
   | Open the cart page | `view_cart` |
   | Open Hyvä Checkout | `begin_checkout` |
   | Complete an order | `purchase` (with `transaction_id`, value, currency, items) |
   | Log in / out, register | `login` / `logout` / `sign_up` |
   | Add to wishlist | `add_to_wishlist` |

   `add_shipping_info` and `add_payment_info` are not sent yet (Hyvä Checkout support is pending).

5. Reload the order success page. `purchase` must **not** be sent a second time for the same order.
6. Check that `page_view` is counted once per page. If you see it twice, tell the maintainers: the module's head script also issues a `gtag('config', ...)` call.

Standard GA4 reports (Monetization) can take up to 24 to 48 hours to show data; DebugView and Realtime are immediate.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| No GTM script in the page source | Module disabled, wrong store view scope, empty Google Tag ID, or cache not flushed |
| GTM script missing until banner is accepted | Expected: consent is required. Accept tracking cookies |
| GTM loads but nothing arrives in GA4 | Container not published (and Preview not connected), or GA4 Measurement ID wrong |
| Export button says "Module not enabled" / "No information to export" | Enable the module and save a GA4 Measurement ID, then try again |
| Events visible in Preview but not in GA4 | Ad blocker or browser privacy feature blocking Google. Test with a clean browser profile |
| No `begin_checkout` | Hyvä Checkout is required; the default Magento checkout is not wired |
| Revenue is 0 in reports | Check that the order currency is a valid ISO code and the `purchase` event has `value` and `currency` |
