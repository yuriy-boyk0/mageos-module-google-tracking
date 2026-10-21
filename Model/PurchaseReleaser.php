<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Sales\Api\Data\OrderInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HoldPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ResendPolicy;

/**
 * Releases a purchase that was held (`waiting`) because the order status was excluded at placement, once the order
 * reaches a status that is not excluded (typically: redirect payment confirmed, pending_payment -> processing).
 *
 * The payload was built at placement with the visitor's consent and GA ids; its original timestamp is dropped, so
 * GA4 dates the event when it is released (the order may have waited longer than Google's 72 h window).
 */
class PurchaseReleaser
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueStorageInterface $storage,
        private readonly HoldPolicy $holdPolicy,
        private readonly ResendPolicy $resendPolicy,
        private readonly PurchaseResender $resender
    ) {}

    /**
     * @return bool true when the purchase was released into the queue
     */
    public function release(OrderInterface $order, int $now): bool
    {
        $storeId = (int) $order->getStoreId();
        if (!$order->getEntityId() || !$this->config->isEnabled($storeId)) {
            return false;
        }
        $row = $this->storage->findByOrder((int) $order->getEntityId(), QueueStorageInterface::EVENT_PURCHASE);
        $excluded = $this->config->getExclusionRules($storeId)['excluded_statuses'];
        if (!$this->holdPolicy->shouldRelease($row, (string) $order->getState(), (string) $order->getStatus(), $excluded)) {
            return false;
        }
        $payload = $this->resendPolicy->preparePayload((string) $row['payload']);
        if ($payload === null) {
            return false;
        }
        $this->storage->resetForResend(
            (int) $row['entity_id'],
            $payload,
            $now,
            sprintf('released: order status changed to "%s"', (string) $order->getStatus())
        );
        // A refund or cancellation recorded while the purchase was waiting was skipped; queue it again now.
        $this->resender->requeueSkippedFollowUps($order, $now);

        return true;
    }
}
