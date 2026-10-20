<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block;

use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template\Context;
use Magento\Search\Model\QueryFactory;
use MageOS\GoogleTagManager\Service\CatalogService;

/**
 * Product page, category listing and search result events.
 *
 * Layout argument `event_type`: product | category | search. For listings the block must render AFTER the
 * product list block (layout: after="-"), so the list has applied its toolbar (page, sort, limit) and loaded
 * the collection before it is read here.
 */
class CatalogTagManager extends TagManager
{
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        private readonly CatalogService $catalogService,
        private readonly CatalogHelper $catalogHelper,
        private readonly QueryFactory $queryFactory,
        private readonly Json $serializer,
        array $data = []
    ) {
        parent::__construct($context, $scopeConfig, $data);
    }

    /**
     * @return string[] JSON encoded events to push, in order
     */
    public function getJsonEvents(): array
    {
        try {
            $events = match ((string) $this->getData('event_type')) {
                'product' => $this->productEvents(),
                'category' => $this->categoryEvents(),
                'search' => $this->searchEvents(),
                default => [],
            };
        } catch (\Throwable $e) {
            // Tracking must never break a storefront page.
            $this->_logger->warning('[MageOS_GoogleTagManager] catalog event: ' . $e->getMessage());
            $events = [];
        }

        return array_map(fn(array $event) => (string) $this->serializer->serialize($event), $events);
    }

    private function productEvents(): array
    {
        $product = $this->catalogHelper->getProduct();

        return $product instanceof Product ? [$this->catalogService->viewItem($product)] : [];
    }

    private function categoryEvents(): array
    {
        $category = $this->catalogHelper->getCategory();
        $products = $this->listedProducts('category.products.list');
        if ($category === null || $products === null) {
            return [];
        }

        return [$this->catalogService->viewItemList(
            $products,
            'category_' . $category->getId(),
            (string) $category->getName()
        )];
    }

    private function searchEvents(): array
    {
        $events = [];
        $term = trim((string) $this->queryFactory->get()->getQueryText());
        if ($term !== '') {
            $events[] = $this->catalogService->search($term);
        }
        $products = $this->listedProducts('search_result_list');
        if ($products !== null) {
            $events[] = $this->catalogService->viewItemList($products, 'search_results', 'Search Results');
        }

        return $events;
    }

    private function listedProducts(string $blockName): ?iterable
    {
        $list = $this->getLayout()->getBlock($blockName);

        return $list instanceof ListProduct ? $list->getLoadedProductCollection() : null;
    }
}
