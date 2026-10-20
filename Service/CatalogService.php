<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Model\Product;

/**
 * Browsing events: view_item, view_item_list, search. Rendered into the (full-page-cacheable) page HTML,
 * so they must only contain data that is the same for every visitor of that page.
 */
class CatalogService extends AbstractGtmService
{
    public const MAX_LIST_ITEMS = 50;

    public function viewItem(Product $product): array
    {
        $item = $this->buildProductData($product, 1.0);

        return [
            'event' => 'view_item',
            'ga4_event' => 'view_item',
            'ecommerce' => [
                'currency' => $this->getCurrencyCode(),
                'value' => $item['price'],
                'items' => [$item],
            ],
        ];
    }

    /**
     * @param iterable<Product> $products the products shown on the current page of the listing
     */
    public function viewItemList(iterable $products, string $listId, string $listName): array
    {
        $items = [];
        $index = 0;
        foreach ($products as $product) {
            if (!$product instanceof Product || $index >= self::MAX_LIST_ITEMS) {
                continue;
            }
            $items[] = $this->buildProductData($product, 1.0) + [
                'index' => $index++,
                'item_list_id' => $listId,
                'item_list_name' => $listName,
            ];
        }

        return [
            'event' => 'view_item_list',
            'ga4_event' => 'view_item_list',
            'ecommerce' => [
                'item_list_id' => $listId,
                'item_list_name' => $listName,
                'currency' => $this->getCurrencyCode(),
                'items' => $items,
            ],
        ];
    }

    public function search(string $term): array
    {
        return [
            'event' => 'search',
            'ga4_event' => 'search',
            'search_term' => $term,
        ];
    }
}
