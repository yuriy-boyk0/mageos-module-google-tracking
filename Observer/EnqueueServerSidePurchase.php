<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use MageOS\GoogleTagManager\Model\PurchaseEnqueuer;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;

/**
 * Queues the server-side purchase event when an order is placed (checkout_submit_all_after).
 *
 * Only enqueues; sending happens in cron. Everything is wrapped so that tracking can never break checkout.
 */
class EnqueueServerSidePurchase implements ObserverInterface
{
    public function __construct(
        private readonly PurchaseEnqueuer $enqueuer,
        private readonly LogInterface $log
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
                $this->enqueuer->enqueue($order, true, time());
            } catch (\Throwable $e) {
                $this->log->log(sprintf('could not queue order %s: %s', $order->getIncrementId(), $e->getMessage()), false);
            }
        }
    }
}
