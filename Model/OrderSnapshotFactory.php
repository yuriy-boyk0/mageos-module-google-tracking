<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use MageOS\GoogleTagManager\Service\ItemDataResolver;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ItemIdResolver;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;

/**
 * Turns a Magento order into the plain array consumed by PayloadBuilder.
 *
 * Needs a real Magento install to test (see docs/local-testing.md).
 */
class OrderSnapshotFactory
{
    public function __construct(
        private readonly TotalsResolver $totalsResolver,
        private readonly ItemDataResolver $itemData
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

        $items = [];
        foreach ($order->getItems() as $item) {
            // Child rows (e.g. the simple product behind a configurable) are folded into their parent row.
            if ($item->getParentItemId()) {
                continue;
            }
            $items[] = $this->buildItem($item, $useBase, $itemIdMode, $storeId);
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

    private function buildItem(OrderItemInterface $item, bool $useBase, string $itemIdMode, int $storeId): array
    {
        $qty = max(1.0, (float) $item->getQtyOrdered());
        $discount = (float) ($useBase ? $item->getBaseDiscountAmount() : $item->getDiscountAmount());

        // item_id / item_variant / categories: the same rules as the client-side events (ItemDataResolver).
        return $this->itemData->forOrderItem($item, $storeId, $itemIdMode) + [
            'item_name' => (string) $item->getName(),
            // Without tax, like the order item prices of the client-side purchase event.
            'price' => round((float) ($useBase ? $item->getBasePrice() : $item->getPrice()), 2),
            'quantity' => $qty,
            'discount' => $discount > 0 ? round($discount / $qty, 2) : 0,
        ];
    }
}
