<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\GoogleTagManager\Model\Config;
use MageOS\GoogleTagManager\Model\OrderSnapshotFactory;
use MageOS\GoogleTagManager\Model\RequestContextProvider;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentState;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ExclusionPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\PayloadBuilder;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

/**
 * Queues the server-side purchase event when an order is placed.
 *
 * Only enqueues; sending happens in cron. Everything is wrapped so that tracking can never break checkout.
 */
class EnqueueServerSidePurchase implements ObserverInterface
{
    private const SKIP_NO_CONSENT = 'skipped: tracking consent not given';
    private const SKIP_UNKNOWN_CONSENT = 'skipped: consent unknown (configured to skip)';

    public function __construct(
        private readonly Config $config,
        private readonly RequestContextProvider $requestContext,
        private readonly OrderSnapshotFactory $snapshotFactory,
        private readonly ExclusionPolicy $exclusionPolicy,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly QueueStorageInterface $storage,
        private readonly LogInterface $log,
        private readonly StoreManagerInterface $storeManager
    ) {}

    public function execute(Observer $observer): void
    {
        // Regular checkout dispatches 'order'; multishipping dispatches 'orders'.
        $event = $observer->getEvent();
        $orders = $event->getData('orders') ?: [$event->getData('order')];

        foreach ($orders as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            try {
                $this->enqueueOrder($order);
            } catch (\Throwable $e) {
                $this->log->log(sprintf('could not queue order %s: %s', $order->getIncrementId(), $e->getMessage()), false);
            }
        }
    }

    private function enqueueOrder(OrderInterface $order): void
    {
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $now = time();
        $base = [
            'order_id' => (int) $order->getEntityId(),
            'increment_id' => (string) $order->getIncrementId(),
            'store_id' => $storeId,
            'event_type' => QueueStorageInterface::EVENT_PURCHASE,
        ];

        $isAdmin = $this->requestContext->isAdminArea();
        $reason = $this->exclusionPolicy->skipReason(
            $this->snapshotFactory->describeForPolicy($order, $isAdmin),
            $this->config->getExclusionRules($storeId)
        );
        if ($reason !== null) {
            $this->skip($base, $reason, $now);
            return;
        }

        $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        $consent = $this->requestContext->getConsentState($websiteId);
        if ($consent === ConsentState::Declined) {
            $this->skip($base, self::SKIP_NO_CONSENT, $now);
            return;
        }
        if ($consent === ConsentState::Unknown
            && $this->config->getUnknownConsentAction($storeId) === Config::UNKNOWN_CONSENT_SKIP
        ) {
            $this->skip($base, self::SKIP_UNKNOWN_CONSENT, $now);
            return;
        }

        $granted = $consent === ConsentState::Granted;
        $measurementId = $this->config->getMeasurementId($storeId);
        // Attribution cookies are only used with consent; otherwise the event is anonymous and unlinked.
        $clientId = ($granted ? $this->requestContext->getClientId() : null) ?? $this->generateClientId();
        $sessionId = $granted ? $this->requestContext->getSessionId($measurementId) : null;

        $payload = $this->payloadBuilder->build(
            $this->snapshotFactory->create(
                $order,
                $this->config->getCurrencyBasis($storeId),
                $this->config->getTaxBasis($storeId),
                $this->config->getItemIdMode($storeId)
            ),
            $this->config->getEventName($storeId),
            $clientId,
            $sessionId,
            $granted
        );

        $this->storage->enqueue(
            $base + [
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'status' => QueueStorageInterface::STATUS_PENDING,
            ],
            $now
        );
    }

    private function skip(array $base, string $reason, int $now): void
    {
        $this->storage->enqueue(
            $base + ['status' => QueueStorageInterface::STATUS_SKIPPED, 'last_error' => $reason],
            $now
        );
    }

    /**
     * Same shape as a GA client id (two numbers), but random and not tied to any visitor.
     */
    private function generateClientId(): string
    {
        return random_int(1000000000, 2147483647) . '.' . time();
    }
}
