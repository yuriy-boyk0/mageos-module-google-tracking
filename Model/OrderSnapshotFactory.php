<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

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
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {}

    public function create(OrderInterface $order, string $currencyBasis): array
    {
        $useBase = $currencyBasis === Config::CURRENCY_BASE;
        $storeId = (int) $order->getStoreId();

        $items = [];
        foreach ($order->getItems() as $item) {
            // Child rows (e.g. the simple product behind a configurable) are folded into their parent row.
            if ($item->getParentItemId()) {
                continue;
            }
            $items[] = $this->buildItem($item, $useBase, $storeId);
        }

        $customerId = $order->getCustomerId();
        $createdAt = strtotime((string) $order->getCreatedAt());

        return [
            'transaction_id' => (string) $order->getIncrementId(),
            'currency' => $useBase ? (string) $order->getBaseCurrencyCode() : (string) $order->getOrderCurrencyCode(),
            'value' => (float) ($useBase ? $order->getBaseGrandTotal() : $order->getGrandTotal()),
            'tax' => (float) ($useBase ? $order->getBaseTaxAmount() : $order->getTaxAmount()),
            'shipping' => (float) ($useBase ? $order->getBaseShippingAmount() : $order->getShippingAmount()),
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

    private function buildItem(OrderItemInterface $item, bool $useBase, int $storeId): array
    {
        $qty = max(1.0, (float) $item->getQtyOrdered());
        $discount = (float) ($useBase ? $item->getBaseDiscountAmount() : $item->getDiscountAmount());

        $row = [
            'item_id' => (string) $item->getSku(),
            'item_name' => (string) $item->getName(),
            'price' => (float) ($useBase ? $item->getBasePrice() : $item->getPrice()),
            'quantity' => $qty,
            'discount' => $discount > 0 ? round($discount / $qty, 2) : 0,
            'item_variant' => $this->resolveVariant($item),
        ];

        return $row + $this->resolveCategories((int) $item->getProductId(), $storeId);
    }

    /**
     * "Black, XS" from the selected options of a configurable product.
     */
    private function resolveVariant(OrderItemInterface $item): string
    {
        $options = $item->getProductOptions();
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
    private function resolveCategories(int $productId, int $storeId): array
    {
        if ($productId === 0) {
            return [];
        }
        try {
            $product = $this->productRepository->getById($productId, false, $storeId);
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
