<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use MageOS\GoogleTagManager\Service\ItemDataResolver;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\FollowUpPayloadBuilder;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\FollowUpPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\RefundQuantity;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;

/**
 * Queues the server-side refund (per credit memo) and cancellation events (Phase 3).
 */
class FollowUpEnqueuer
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueStorageInterface $storage,
        private readonly FollowUpPolicy $policy,
        private readonly FollowUpPayloadBuilder $builder,
        private readonly TotalsResolver $totalsResolver,
        private readonly ItemDataResolver $itemData,
        private readonly RefundQuantity $refundQuantity
    ) {}

    public function enqueueRefund(CreditmemoInterface $creditmemo, OrderInterface $order, int $now): void
    {
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isRefundTrackingEnabled($storeId) || !$creditmemo->getEntityId()) {
            return;
        }
        $useBase = $this->config->getCurrencyBasis($storeId) === Config::CURRENCY_BASE;
        $taxBasis = $this->config->getTaxBasis($storeId);

        $totals = $this->totalsResolver->resolveOrder([
            'grand_total' => (float) ($useBase ? $creditmemo->getBaseGrandTotal() : $creditmemo->getGrandTotal()),
            'tax' => (float) ($useBase ? $creditmemo->getBaseTaxAmount() : $creditmemo->getTaxAmount()),
            'shipping' => (float) ($useBase ? $creditmemo->getBaseShippingAmount() : $creditmemo->getShippingAmount()),
        ], $taxBasis);

        $items = $this->refundItems($creditmemo, $useBase, $storeId);

        $orderTotal = (float) ($useBase ? $order->getBaseGrandTotal() : $order->getGrandTotal());
        $memoTotal = (float) ($useBase ? $creditmemo->getBaseGrandTotal() : $creditmemo->getGrandTotal());

        $this->enqueue(
            $order,
            QueueStorageInterface::EVENT_REFUND,
            (string) $creditmemo->getEntityId(),
            $this->config->getRefundEventName($storeId),
            FollowUpPayloadBuilder::DEFAULT_REFUND_EVENT,
            [
                'transaction_id' => (string) $order->getIncrementId(),
                'currency' => $useBase ? (string) $order->getBaseCurrencyCode() : (string) $order->getOrderCurrencyCode(),
                'value' => $totals['value'],
                'items' => $items,
            ],
            ['refund_type' => abs($orderTotal - $memoTotal) < 0.01 ? 'full' : 'partial'],
            $now
        );
    }

    public function enqueueCancellation(OrderInterface $order, int $now): void
    {
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isCancellationTrackingEnabled($storeId)) {
            return;
        }
        $useBase = $this->config->getCurrencyBasis($storeId) === Config::CURRENCY_BASE;
        $totals = $this->totalsResolver->resolveOrder([
            'grand_total' => (float) ($useBase ? $order->getBaseGrandTotal() : $order->getGrandTotal()),
            'tax' => (float) ($useBase ? $order->getBaseTaxAmount() : $order->getTaxAmount()),
            'shipping' => 0.0,
        ], $this->config->getTaxBasis($storeId));

        $this->enqueue(
            $order,
            QueueStorageInterface::EVENT_CANCEL,
            '',
            $this->config->getCancelEventName($storeId),
            FollowUpPayloadBuilder::DEFAULT_CANCEL_EVENT,
            [
                'transaction_id' => (string) $order->getIncrementId(),
                'currency' => $useBase ? (string) $order->getBaseCurrencyCode() : (string) $order->getOrderCurrencyCode(),
                'value' => $totals['value'],
            ],
            [],
            $now
        );
    }

    /**
     * Refunded lines, described like the purchase lines (same item_id rule, children folded into their parent, bundle
     * quantity derived from its children).
     */
    private function refundItems(CreditmemoInterface $creditmemo, bool $useBase, int $storeId): array
    {
        $memoItems = $creditmemo->getItems();
        $childrenByParent = [];
        foreach ($memoItems as $item) {
            $orderItem = $item instanceof CreditmemoItem ? $item->getOrderItem() : null;
            if ($orderItem && $orderItem->getParentItemId()) {
                $childrenByParent[(int) $orderItem->getParentItemId()][] = [
                    'memo_qty' => (float) $item->getQty(),
                    'ordered_qty' => (float) $orderItem->getQtyOrdered(),
                ];
            }
        }

        $items = [];
        foreach ($memoItems as $item) {
            $orderItem = $item instanceof CreditmemoItem ? $item->getOrderItem() : null;
            if ($orderItem && $orderItem->getParentItemId()) {
                continue;
            }
            $qty = (float) $item->getQty();
            if ($orderItem && $orderItem->getProductType() === 'bundle') {
                $qty = $this->refundQuantity->forParent(
                    $qty,
                    (float) $orderItem->getQtyOrdered(),
                    $childrenByParent[(int) $orderItem->getItemId()] ?? []
                );
            }
            if ($qty <= 0) {
                continue;
            }
            $describe = $orderItem
                ? $this->itemData->forOrderItem($orderItem, $storeId, $this->config->getItemIdMode($storeId))
                : ['item_id' => (string) $item->getSku()];
            $items[] = $describe + [
                'item_name' => (string) $item->getName(),
                'quantity' => $qty,
                // Without tax, like the items of the purchase event.
                'price' => round((float) ($useBase ? $item->getBasePrice() : $item->getPrice()), 2),
            ];
        }

        return $items;
    }

    private function enqueue(
        OrderInterface $order,
        string $eventType,
        string $referenceId,
        string $eventName,
        string $defaultName,
        array $amounts,
        array $extraParams,
        int $now
    ): void {
        $orderId = (int) $order->getEntityId();
        $base = [
            'order_id' => $orderId,
            'increment_id' => (string) $order->getIncrementId(),
            'store_id' => (int) $order->getStoreId(),
            'event_type' => $eventType,
            'reference_id' => $referenceId,
        ];

        $purchase = $this->storage->findByOrder($orderId, QueueStorageInterface::EVENT_PURCHASE);
        $reason = $this->policy->skipReason($purchase);
        $purchasePayload = $purchase ? json_decode((string) $purchase['payload'], true) : null;
        if ($reason === null && !is_array($purchasePayload)) {
            $reason = FollowUpPolicy::REASON_NO_PURCHASE;
        }
        if ($reason !== null) {
            $this->storage->enqueue($base + ['status' => QueueStorageInterface::STATUS_SKIPPED, 'last_error' => $reason], $now);
            return;
        }

        $payload = $this->builder->build($purchasePayload, $eventName, $defaultName, $amounts, $extraParams);
        $this->storage->enqueue(
            $base + ['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES), 'status' => QueueStorageInterface::STATUS_PENDING],
            $now
        );
    }
}
