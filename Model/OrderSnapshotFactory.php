<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ItemIdResolver;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;

/**
 * Turns a Magento order into the plain array consumed by PayloadBuilder.
 *
 * Needs a real Magento install to test (see docs/local-testing.md).
 */
class OrderSnapshotFactory
{
    private const MAX_CATEGORY_LEVELS = 5;

    /** @var array<int, string> */
    private array $categoryNames = [];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly TotalsResolver $totalsResolver,
        private readonly ItemIdResolver $itemIdResolver
    ) {}

    /**
     * @param string $currencyBasis Config::CURRENCY_*
     * @param string $taxBasis      TotalsResolver::INCLUDING_TAX / EXCLUDING_TAX
     * @param string $itemIdMode    ItemIdResolver::PARENT / CHILD
     */
    public function create(OrderInterface $order, string $currencyBasis, string $taxBasis, string $itemIdMode): array
    {
        $useBase = $currencyBasis === Config::CURRENCY_BASE;
        $storeId = (int) $order->getStoreId();

        $allItems = $order->getItems();
        $childrenByParent = [];
        foreach ($allItems as $item) {
            if ($item->getParentItemId()) {
                $childrenByParent[(int) $item->getParentItemId()][] = $item;
            }
        }

        $items = [];
        foreach ($allItems as $item) {
            // Child rows (e.g. the simple product behind a configurable) are folded into their parent row.
            if ($item->getParentItemId()) {
                continue;
            }
            $children = $childrenByParent[(int) $item->getItemId()] ?? [];
            $items[] = $this->buildItem($item, $children[0] ?? null, $useBase, $itemIdMode, $storeId);
        }

        $totals = $this->totalsResolver->resolveOrder([
            'grand_total' => (float) ($useBase ? $order->getBaseGrandTotal() : $order->getGrandTotal()),
            'tax' => (float) ($useBase ? $order->getBaseTaxAmount() : $order->getTaxAmount()),
            'shipping' => (float) ($useBase ? $order->getBaseShippingAmount() : $order->getShippingAmount()),
        ], $taxBasis);

        $customerId = $order->getCustomerId();
        $createdAt = strtotime((string) $order->getCreatedAt());

        return [
            'transaction_id' => (string) $order->getIncrementId(),
            'currency' => $useBase ? (string) $order->getBaseCurrencyCode() : (string) $order->getOrderCurrencyCode(),
            'value' => $totals['value'],
            'tax' => $totals['tax'],
            'shipping' => $totals['shipping'],
            'coupon' => $order->getCouponCode(),
            'created_at_micros' => $createdAt ? $createdAt * 1000000 : null,
            'user_id' => $customerId ? (string) $customerId : null,
            'items' => $items,
        ];
    }

    /**
     * Facts used by ExclusionPolicy.
     */
    public function describeForPolicy(OrderInterface $order, bool $isAdminOrder): array
    {
        $payment = $order->getPayment();

        return [
            'grand_total' => (float) $order->getBaseGrandTotal(),
            'status' => (string) $order->getStatus(),
            'payment_method' => $payment ? (string) $payment->getMethod() : '',
            'is_admin_order' => $isAdminOrder,
        ];
    }

    private function buildItem(
        OrderItemInterface $item,
        ?OrderItemInterface $child,
        bool $useBase,
        string $itemIdMode,
        int $storeId
    ): array {
        $qty = max(1.0, (float) $item->getQtyOrdered());
        $discount = (float) ($useBase ? $item->getBaseDiscountAmount() : $item->getDiscountAmount());
        $product = $this->loadProduct((int) $item->getProductId(), $storeId);

        $row = [
            'item_id' => $this->itemIdResolver->resolve(
                $itemIdMode,
                (string) $item->getProductType(),
                (string) $item->getSku(),
                $child ? (string) $child->getSku() : null,
                $product ? (string) $product->getSku() : null
            ),
            'item_name' => (string) $item->getName(),
            // Without tax, like the order item prices of the client-side purchase event.
            'price' => round((float) ($useBase ? $item->getBasePrice() : $item->getPrice()), 2),
            'quantity' => $qty,
            'discount' => $discount > 0 ? round($discount / $qty, 2) : 0,
            'item_variant' => $this->resolveVariant($item),
        ];

        return $row + $this->resolveCategories($product, $storeId);
    }

    private function loadProduct(int $productId, int $storeId): ?ProductInterface
    {
        if ($productId === 0) {
            return null;
        }
        try {
            return $this->productRepository->getById($productId, false, $storeId);
        } catch (\Throwable $e) {
            return null; // product deleted since the order: fall back to order-row data
        }
    }

    /**
     * "Black, XS" from the selected options of a configurable product.
     */
    private function resolveVariant(OrderItemInterface $item): string
    {
        $options = $item instanceof OrderItem ? $item->getProductOptions() : [];
        $values = [];
        foreach ($options['attributes_info'] ?? [] as $attribute) {
            if (!empty($attribute['value'])) {
                $values[] = (string) $attribute['value'];
            }
        }

        return implode(', ', $values);
    }

    /**
     * item_category .. item_category5 from the most specific category of the product.
     */
    private function resolveCategories(?ProductInterface $product, int $storeId): array
    {
        if ($product === null) {
            return [];
        }
        try {
            $bestPath = [];
            foreach ($product instanceof Product ? $product->getCategoryIds() : [] as $categoryId) {
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

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function categoryName(int $categoryId, int $storeId): string
    {
        return $this->categoryNames[$categoryId]
            ??= (string) $this->categoryRepository->get($categoryId, $storeId)->getName();
    }
}
