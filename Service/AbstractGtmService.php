<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
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
            'price' => round((float) $product->getFinalPrice(), 2),
            'quantity' => $quantity,
            'item_category' => $this->resolveTopCategoryName($product),
        ];
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
