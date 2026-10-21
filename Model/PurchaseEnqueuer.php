<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentState;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ExclusionPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\PayloadBuilder;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueProcessor;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

/**
 * Builds and queues the server-side purchase row for an order. Used at order placement (with the visitor's
 * request) and by the admin re-send action for orders that have no row yet (no request: consent unknown).
 */
class PurchaseEnqueuer
{
    public const SKIP_NO_CONSENT = 'skipped: tracking consent not given';
    public const SKIP_UNKNOWN_CONSENT = 'skipped: consent unknown (configured to skip)';

    public const WAITING_REASON = 'waiting: order status "%s" is excluded, sent once the order reaches another status';

    public const RESULT_QUEUED = 'queued';
    public const RESULT_WAITING = 'waiting';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_EXISTS = 'exists';
    public const RESULT_DISABLED = 'disabled';

    public function __construct(
        private readonly Config $config,
        private readonly RequestContextProvider $requestContext,
        private readonly OrderSnapshotFactory $snapshotFactory,
        private readonly ExclusionPolicy $exclusionPolicy,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly QueueStorageInterface $storage,
        private readonly StoreManagerInterface $storeManager
    ) {}

    /**
     * @param bool $fromVisitorRequest true at order placement; false for a backfill from the admin (no cookies)
     */
    public function enqueue(OrderInterface $order, bool $fromVisitorRequest, int $now): string
    {
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isEnabled($storeId)) {
            return self::RESULT_DISABLED;
        }

        $base = [
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => (string) $order->getIncrementId(),
            'store_id' => $storeId,
            'event_type' => QueueStorageInterface::EVENT_PURCHASE,
            'reference_id' => '',
        ];

        $isAdmin = $fromVisitorRequest && $this->requestContext->isAdminArea();
        $facts = $this->snapshotFactory->describeForPolicy($order, $isAdmin);
        $rules = $this->config->getExclusionRules($storeId);
        // An excluded *status* is not final (e.g. pending_payment until a redirect payment is confirmed): the purchase
        // is built now, with this request's consent and GA ids, and waits for the status to change (HoldPolicy).
        $holdForStatus = in_array($facts['status'], $rules['excluded_statuses'], true);
        $rules['excluded_statuses'] = [];
        $reason = $this->exclusionPolicy->skipReason($facts, $rules);
        if ($reason !== null) {
            return $this->skip($base, $reason, $now);
        }

        $consent = $fromVisitorRequest
            ? $this->requestContext->getConsentState((int) $this->storeManager->getStore($storeId)->getWebsiteId(), $storeId)
            : ConsentState::Unknown;
        if ($consent === ConsentState::Declined) {
            return $this->skip($base, self::SKIP_NO_CONSENT, $now);
        }
        if ($consent === ConsentState::Unknown
            && $this->config->getUnknownConsentAction($storeId) === Config::UNKNOWN_CONSENT_SKIP
        ) {
            return $this->skip($base, self::SKIP_UNKNOWN_CONSENT, $now);
        }

        $granted = $consent === ConsentState::Granted;
        $measurementId = $this->config->getMeasurementId($storeId);
        // Attribution cookies are only used with consent; otherwise the event is anonymous and unlinked.
        $clientId = ($granted ? $this->requestContext->getClientId() : null) ?? $this->generateClientId();
        $sessionId = $granted ? $this->requestContext->getSessionId($measurementId) : null;

        $snapshot = $this->snapshotFactory->create(
            $order,
            $this->config->getCurrencyBasis($storeId),
            $this->config->getTaxBasis($storeId),
            $this->config->getItemIdMode($storeId)
        );

        // Backfill of an older order: keep its real date if Google still accepts it, otherwise let GA4 date it now.
        $createdAt = $now;
        if (!$fromVisitorRequest && !empty($snapshot['created_at_micros'])) {
            $orderTime = intdiv((int) $snapshot['created_at_micros'], 1000000);
            if ($now - $orderTime < QueueProcessor::MAX_EVENT_AGE_SECONDS - 3600) {
                $createdAt = $orderTime;
            } else {
                $snapshot['created_at_micros'] = null;
            }
        }

        $payload = $this->payloadBuilder->build(
            $snapshot,
            $this->config->getEventName($storeId),
            $clientId,
            $sessionId,
            $granted
        );

        $queued = $this->storage->enqueue(
            $base + [
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'status' => $holdForStatus ? QueueStorageInterface::STATUS_WAITING : QueueStorageInterface::STATUS_PENDING,
                'last_error' => $holdForStatus ? sprintf(self::WAITING_REASON, $facts['status']) : null,
                'created_at' => $createdAt,
            ],
            $now
        );
        if (!$queued) {
            return self::RESULT_EXISTS;
        }

        return $holdForStatus ? self::RESULT_WAITING : self::RESULT_QUEUED;
    }

    private function skip(array $base, string $reason, int $now): string
    {
        $stored = $this->storage->enqueue(
            $base + ['status' => QueueStorageInterface::STATUS_SKIPPED, 'last_error' => $reason],
            $now
        );

        return $stored ? self::RESULT_SKIPPED : self::RESULT_EXISTS;
    }

    /**
     * Same shape as a GA client id (two numbers), but random and not tied to any visitor.
     */
    private function generateClientId(): string
    {
        return random_int(1000000000, 2147483647) . '.' . time();
    }
}
