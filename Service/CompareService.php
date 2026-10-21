<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;

class CompareService extends AbstractGtmService
{
    public function __construct(
        StoreManagerInterface $storeManager,
        CategoryRepositoryInterface $categoryRepository,
        ItemDataResolver $itemData,
        private readonly EventQueue $eventQueue
    ) {
        parent::__construct($storeManager, $categoryRepository, $itemData);
    }

    /**
     * add_to_compare (not a GA4 recommended event; the exported container sends it as a custom GA4 event).
     */
    public function addToCompare(Product $product): void
    {
        $items = [$this->buildProductData($product, 1.0)];
        $this->eventQueue->appendSessionEvent(
            EventQueue::SESSION_KEY_ADD_TO_COMPARE,
            [
                'event' => 'add_to_compare',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value' => (float) $items[0]['price'],
                    'items' => $items,
                ],
            ]
        );
    }
}
