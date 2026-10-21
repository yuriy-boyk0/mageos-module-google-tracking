<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\ScopeInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ItemIdResolver;

/**
 * Describes a product the same way in every event (client-side browsing, cart, checkout and purchase events, and
 * the server-side purchase and refund): item_id (ItemIdResolver + the *Item ID for Configurable Products* setting),
 * item_variant (selected configurable options) and item_category .. item_category5 (category path).
 */
class ItemDataResolver
{
    public const XML_PATH_ITEM_ID_MODE = 'googletagmanager/server_side/item_id_mode';
    public const XML_PATH_BRAND_ATTRIBUTE = 'googletagmanager/general/brand_attribute';
    public const XML_PATH_AFFILIATION = 'googletagmanager/general/affiliation';
    private const MAX_CATEGORY_LEVELS = 5;

    /** @var array<string, string> */
    private array $categoryNames = [];
    /** @var array<string, array> */
    private array $categoryPaths = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ItemIdResolver $itemIdResolver
    ) {}

    public function itemIdMode(?int $storeId = null): string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ITEM_ID_MODE, ScopeInterface::SCOPE_STORE, $storeId) === ItemIdResolver::CHILD
            ? ItemIdResolver::CHILD
            : ItemIdResolver::PARENT;
    }

    /**
     * A catalog product on its own (product page, listings, add to cart, wishlist).
     * $allowLoad = false for listings: the brand is then only read if the attribute is loaded with the collection
     * ("Used in Product Listing" = Yes), never by loading each product.
     *
     * @return array{item_id: string, item_variant?: string, item_category?: string}
     */
    public function forProduct(Product $product, int $storeId, bool $allowLoad = true): array
    {
        // A configurable added to the cart carries the chosen variant as custom option "simple_product".
        $simple = $product->getCustomOption('simple_product')?->getProduct();
        $data = [
            'item_id' => $this->itemIdResolver->resolve(
                $this->itemIdMode($storeId),
                (string) $product->getTypeId(),
                // getSku() of a configurable with a selected variant returns the variant's SKU; the raw attribute does not
                (string) ($simple ? $simple->getData('sku') : $product->getData('sku')),
                $simple ? (string) $simple->getData('sku') : null,
                (string) $product->getData('sku')
            ),
        ];
        $variant = $this->variant($this->productOptions($product));

        return $data
            + ($variant !== '' ? ['item_variant' => $variant] : [])
            + $this->extras($product, $storeId, $allowLoad)
            + $this->categories($product, $storeId);
    }

    /**
     * A cart row (view_cart, begin_checkout, update/remove_from_cart). Children are folded into this row.
     */
    public function forQuoteItem(QuoteItem $item, int $storeId): array
    {
        $children = $item->getChildren();
        $product = $item->getProduct();

        return $this->describe(
            (string) $item->getProductType(),
            (string) $item->getSku(),
            $children ? (string) reset($children)->getSku() : null,
            (string) $product->getData('sku'),
            $this->productOptions($product),
            $product,
            $storeId
        );
    }

    /**
     * An order row (client purchase, server purchase, refund). Children are folded into this row.
     */
    public function forOrderItem(OrderItemInterface $item, int $storeId, ?string $itemIdMode = null): array
    {
        $children = $item instanceof OrderItem ? $item->getChildrenItems() : [];
        $product = $this->loadProduct((int) $item->getProductId(), $storeId);

        return $this->describe(
            (string) $item->getProductType(),
            (string) $item->getSku(),
            $children ? (string) reset($children)->getSku() : null,
            $product instanceof Product ? (string) $product->getData('sku') : null,
            $item instanceof OrderItem ? (array) $item->getProductOptions() : [],
            $product,
            $storeId,
            $itemIdMode
        );
    }

    public function loadProduct(int $productId, int $storeId): ?ProductInterface
    {
        if ($productId === 0) {
            return null;
        }
        try {
            // Loaded fresh (not the cart's product instance), so getSku() is the product's own SKU.
            return $this->productRepository->getById($productId, false, $storeId);
        } catch (\Throwable $e) {
            return null; // product deleted since: fall back to row data
        }
    }

    /**
     * item_category .. item_category5 from the most specific category of the product.
     */
    public function categories(?ProductInterface $product, int $storeId): array
    {
        if (!$product instanceof Product) {
            return [];
        }
        $cacheKey = $product->getId() . '/' . $storeId;
        if (isset($this->categoryPaths[$cacheKey])) {
            return $this->categoryPaths[$cacheKey];
        }
        try {
            $bestPath = [];
            foreach ($product->getCategoryIds() as $categoryId) {
                $category = $this->categoryRepository->get((int) $categoryId, $storeId);
                // Path is "1/2/10/15": drop the tree root and the store root category.
                $pathIds = array_slice(array_map('intval', explode('/', (string) $category->getPath())), 2);
                if (count($pathIds) > count($bestPath)) {
                    $bestPath = $pathIds;
                }
            }
            $result = [];
            foreach (array_slice($bestPath, 0, self::MAX_CATEGORY_LEVELS) as $index => $id) {
                $key = $index === 0 ? 'item_category' : 'item_category' . ($index + 1);
                $result[$key] = $this->categoryName($id, $storeId);
            }
        } catch (\Throwable $e) {
            $result = [];
        }

        return $this->categoryPaths[$cacheKey] = $result;
    }

    /**
     * "Black, XS" from the selected options of a configurable product (order item product_options format).
     */
    public function variant(array $productOptions): string
    {
        $values = [];
        foreach ($productOptions['attributes_info'] ?? [] as $attribute) {
            if (!empty($attribute['value'])) {
                $values[] = (string) $attribute['value'];
            }
        }

        return implode(', ', $values);
    }

    private function describe(
        string $type,
        string $rowSku,
        ?string $childSku,
        ?string $catalogSku,
        array $productOptions,
        ?ProductInterface $product,
        int $storeId,
        ?string $itemIdMode = null
    ): array {
        $data = [
            'item_id' => $this->itemIdResolver->resolve(
                $itemIdMode ?? $this->itemIdMode($storeId),
                $type,
                $rowSku,
                $childSku,
                $catalogSku
            ),
        ];
        $variant = $this->variant($productOptions);

        return $data
            + ($variant !== '' ? ['item_variant' => $variant] : [])
            + $this->extras($product, $storeId, true)
            + $this->categories($product, $storeId);
    }

    /**
     * item_brand (attribute configured under General > Brand Attribute) and affiliation (General > Affiliation).
     */
    private function extras(?ProductInterface $product, int $storeId, bool $allowLoad): array
    {
        $result = [];
        $affiliation = trim((string) $this->scopeConfig->getValue(self::XML_PATH_AFFILIATION, ScopeInterface::SCOPE_STORE, $storeId));
        if ($affiliation !== '') {
            $result['affiliation'] = $affiliation;
        }
        $brand = $this->brand($product, $storeId, $allowLoad);
        if ($brand !== '') {
            $result['item_brand'] = $brand;
        }

        return $result;
    }

    private function brand(?ProductInterface $product, int $storeId, bool $allowLoad): string
    {
        $code = trim((string) $this->scopeConfig->getValue(self::XML_PATH_BRAND_ATTRIBUTE, ScopeInterface::SCOPE_STORE, $storeId));
        if ($code === '' || !$product instanceof Product) {
            return '';
        }
        $brand = $this->attributeValue($product, $code);
        // Cart rows and some listings carry a product without the attribute loaded: read it from the full product.
        if ($brand === '' && $allowLoad && !$product->hasData($code) && $product->getId()) {
            $full = $this->loadProduct((int) $product->getId(), $storeId);
            $brand = $full instanceof Product ? $this->attributeValue($full, $code) : '';
        }

        return $brand;
    }

    private function attributeValue(Product $product, string $code): string
    {
        try {
            $text = $product->getAttributeText($code);
            if (is_array($text)) {
                $text = implode(', ', $text);
            }
            if ($text !== false && $text !== null && (string) $text !== '') {
                return (string) $text;
            }
        } catch (\Throwable $e) {
            // not an option attribute
        }
        // Option attributes (select / multiselect) without a label stay empty instead of sending the option id.
        $attribute = $product->getResource()->getAttribute($code);
        if ($attribute && $attribute->usesSource()) {
            return '';
        }
        $raw = $product->getData($code);

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * The options Magento would store on the order item (same source as Quote\Item\ToOrderItem).
     */
    private function productOptions(Product $product): array
    {
        // Only configurables have selectable attributes (item_variant); other types would cost a lot for nothing.
        if ($product->getTypeId() !== 'configurable') {
            return [];
        }
        try {
            return (array) $product->getTypeInstance()->getOrderOptions($product);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function categoryName(int $categoryId, int $storeId): string
    {
        return $this->categoryNames[$categoryId . '/' . $storeId]
            ??= (string) $this->categoryRepository->get($categoryId, $storeId)->getName();
    }
}
