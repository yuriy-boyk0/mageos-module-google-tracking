<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Store\Model\StoreManagerInterface;

abstract class AbstractGtmService
{
    public function __construct(
        protected readonly StoreManagerInterface $storeManager,
        protected readonly CategoryRepositoryInterface $categoryRepository,
        protected readonly ItemDataResolver $itemData
    ) {}

    /**
     * Item of a catalog product. item_id / item_variant / item_category.. come from ItemDataResolver, the same rules
     * as the server-side purchase, so one product is one GA4 item in every event.
     */
    protected function buildProductData(Product $product, float $quantity = 1.0, bool $allowLoad = true): array
    {
        return $this->itemData->forProduct($product, $this->currentStoreId(), $allowLoad) + [
            'item_name' => $product->getName(),
            'price' => $this->unitPrice($product),
            'quantity' => $quantity,
        ];
    }

    protected function currentStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Unit price of a product as the item `price` of the browsing and add-to-cart events: excluding tax, in the
     * currency shown to the customer. Magento's price info already returns amounts in the current currency, and its
     * base amount is the price without the tax that is included in the catalog price (when "Catalog Prices" include
     * tax), the same basis as the order item prices of the `purchase` event. Only the fallback, the raw catalog price,
     * is in the base currency and needs converting.
     */
    protected function unitPrice(Product $product): float
    {
        if ($product->getTypeId() === 'bundle') {
            $bundlePrice = $this->bundleNetPrice($product);
            if ($bundlePrice > 0) {
                return $bundlePrice;
            }
        }
        try {
            return round((float) $product->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getBaseAmount(), 2);
        } catch (\Throwable $e) {
            return $this->toDisplayCurrency((float) $product->getFinalPrice());
        }
    }

    /**
     * Minimal price of a bundle without tax. The price info amount of a bundle keeps the tax of a tax-inclusive
     * catalog in its base amount (a dynamic bundle's own tax class is not its options' tax class), so it is gross
     * where every other event is net. The bundle price model removes the tax per option with the option's own tax
     * class. The price index values (min_price / max_price on listing products) are gross and taxed with the bundle's
     * class, so they are left out and the options are summed instead. Base currency: converted for display.
     */
    private function bundleNetPrice(Product $product): float
    {
        try {
            $priceModel = $product->getPriceModel();
            if (!method_exists($priceModel, 'getTotalPrices')) {
                return 0.0;
            }
            $copy = clone $product;
            $copy->unsetData('min_price');
            $copy->unsetData('max_price');
            $min = $priceModel->getTotalPrices($copy, 'min', false);

            return is_numeric($min) && (float) $min > 0 ? $this->toDisplayCurrency((float) $min) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Converts an amount from the base currency to the store's current (display) currency, rounded to 2 decimals.
     */
    protected function toDisplayCurrency(float $baseAmount): float
    {
        try {
            $store = $this->storeManager->getStore();
            $baseAmount = (float) $store->getBaseCurrency()->convert($baseAmount, $store->getCurrentCurrency());
        } catch (\Throwable $e) {
            // no rate for the current currency: keep the base amount rather than lose the event
        }

        return round($baseAmount, 2);
    }

    protected function getCurrencyCode(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            return '';
        }
    }
}
