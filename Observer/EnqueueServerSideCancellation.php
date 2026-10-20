<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use MageOS\GoogleTagManager\Model\FollowUpEnqueuer;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;

/**
 * order_cancel_after: one cancellation event per order.
 */
class EnqueueServerSideCancellation implements ObserverInterface
{
    public function __construct(
        private readonly FollowUpEnqueuer $enqueuer,
        private readonly LogInterface $log
    ) {}

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }
        try {
            $this->enqueuer->enqueueCancellation($order, time());
        } catch (\Throwable $e) {
            $this->log->log('could not queue cancellation for order ' . $order->getIncrementId() . ': ' . $e->getMessage(), false);
        }
    }
}
