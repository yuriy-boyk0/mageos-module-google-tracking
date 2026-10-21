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
        protected readonly CategoryRepositoryInterface $categoryRepository
    ) {}

    protected function buildProductData(Product $product, float $quantity = 1.0): array
    {
        return [
            'item_id' => $product->getSku(),
            'item_name' => $product->getName(),
            'price' => $this->unitPrice($product),
            'quantity' => $quantity,
            'item_category' => $this->resolveTopCategoryName($product),
        ];
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
        try {
            return round((float) $product->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getBaseAmount(), 2);
        } catch (\Throwable $e) {
            return $this->toDisplayCurrency((float) $product->getFinalPrice());
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

    private function resolveTopCategoryName(Product $product): string
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        foreach ($product->getCategoryIds() as $categoryId) {
            try {
                $category = $this->categoryRepository->get((int) $categoryId, $storeId);
                if ($category->getLevel() > 1) {
                    return (string) $category->getName();
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        return '';
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
