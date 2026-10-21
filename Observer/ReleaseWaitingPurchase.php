<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use MageOS\GoogleTagManager\Model\PurchaseReleaser;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;

/**
 * sales_order_save_after: releases a purchase held for an excluded order status once the status changes.
 * Never throws: an order save must not fail because of tracking.
 */
class ReleaseWaitingPurchase implements ObserverInterface
{
    public function __construct(
        private readonly PurchaseReleaser $releaser,
        private readonly LogInterface $log
    ) {}

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }
        try {
            $this->releaser->release($order, time());
        } catch (\Throwable $e) {
            $this->log->log('could not release waiting purchase of order ' . $order->getIncrementId() . ': ' . $e->getMessage(), false);
        }
    }
}
