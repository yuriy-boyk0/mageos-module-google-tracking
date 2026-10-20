<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Sales\Api\Data\OrderInterface;
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
        private readonly PurchaseEnqueuer $enqueuer
    ) {}

    /**
     * @return array{queued: int, ignored: array<string, int>} ignored counts keyed by reason
     */
    public function resend(iterable $orders, int $now): array
    {
        $result = ['queued' => 0, 'ignored' => []];
        foreach ($orders as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            $reason = $this->resendOne($order, $now);
            if ($reason === null) {
                $result['queued']++;
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
                PurchaseEnqueuer::RESULT_DISABLED => 'server-side tracking disabled for the store',
                PurchaseEnqueuer::RESULT_SKIPPED => 'skipped by the exclusion or consent settings',
                default => 'already queued',
            };
        }

        return $reason;
    }
}
