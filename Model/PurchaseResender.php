<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\FollowUpPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ResendPolicy;

/**
 * Admin mass action "Re-send GA4 server-side purchase" (Phase 3).
 */
class PurchaseResender
{
    public function __construct(
        private readonly QueueStorageInterface $storage,
        private readonly ResendPolicy $policy,
        private readonly PurchaseEnqueuer $enqueuer,
        private readonly FollowUpPolicy $followUpPolicy,
        private readonly FollowUpEnqueuer $followUpEnqueuer
    ) {}

    /**
     * @return array{queued: int, followups: int, ignored: array<string, int>} ignored counts keyed by reason,
     *         followups = refund / cancellation events queued again because they had been skipped for the unsent purchase
     */
    public function resend(iterable $orders, int $now): array
    {
        $result = ['queued' => 0, 'followups' => 0, 'ignored' => []];
        foreach ($orders as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            $reason = $this->resendOne($order, $now);
            if ($reason === null) {
                $result['queued']++;
                $result['followups'] += $this->requeueSkippedFollowUps($order, $now);
            } else {
                $result['ignored'][$reason] = ($result['ignored'][$reason] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * @return string|null why the order was not queued, or null when it was
     */
    private function resendOne(OrderInterface $order, int $now): ?string
    {
        $row = $this->storage->findByOrder((int) $order->getEntityId(), QueueStorageInterface::EVENT_PURCHASE);
        [$action, $reason] = $this->policy->decide($row);

        if ($action === ResendPolicy::RESET) {
            $payload = $this->policy->preparePayload((string) $row['payload']);
            if ($payload === null) {
                return 'stored payload unusable';
            }
            $this->storage->resetForResend((int) $row['entity_id'], $payload, $now);

            return null;
        }
        if ($action === ResendPolicy::CREATE) {
            return match ($this->enqueuer->enqueue($order, false, $now)) {
                PurchaseEnqueuer::RESULT_QUEUED => null,
                PurchaseEnqueuer::RESULT_WAITING => 'order status is excluded: waiting, sent once the order reaches another status',
                PurchaseEnqueuer::RESULT_DISABLED => 'server-side tracking disabled for the store',
                PurchaseEnqueuer::RESULT_SKIPPED => 'skipped by the exclusion or consent settings',
                default => 'already queued',
            };
        }

        return $reason;
    }

    /**
     * The purchase is queued again, so refunds / a cancellation that were skipped because it had not been sent are
     * built again from the credit memos and the order state (the skipped rows carry no payload, and their unique key
     * would block a new attempt, so they are deleted first). Returns how many follow-up events were queued.
     */
    public function requeueSkippedFollowUps(OrderInterface $order, int $now): int
    {
        $orderId = (int) $order->getEntityId();
        $rows = $this->followUpPolicy->rowsToRequeue($this->storage->findFollowUpsByOrder($orderId));
        if ($rows === []) {
            return 0;
        }

        $refunds = false;
        $cancel = false;
        foreach ($rows as $row) {
            $this->storage->deleteRow((int) $row['entity_id']);
            $row['event_type'] === QueueStorageInterface::EVENT_REFUND ? $refunds = true : $cancel = true;
        }

        $queued = 0;
        if ($refunds && $order instanceof Order) {
            foreach ($order->getCreditmemosCollection() as $creditmemo) {
                $this->followUpEnqueuer->enqueueRefund($creditmemo, $order, $now);
            }
        }
        if ($cancel && $order->getState() === Order::STATE_CANCELED) {
            $this->followUpEnqueuer->enqueueCancellation($order, $now);
        }
        foreach ($this->storage->findFollowUpsByOrder($orderId) as $row) {
            if ($row['status'] === QueueStorageInterface::STATUS_PENDING) {
                $queued++;
            }
        }

        return $queued;
    }
}
