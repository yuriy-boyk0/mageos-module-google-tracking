<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Creditmemo;
use MageOS\GoogleTagManager\Model\FollowUpEnqueuer;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;

/**
 * sales_order_creditmemo_save_after: one refund event per credit memo (re-saves are ignored by the unique key).
 */
class EnqueueServerSideRefund implements ObserverInterface
{
    public function __construct(
        private readonly FollowUpEnqueuer $enqueuer,
        private readonly LogInterface $log
    ) {}

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if (!$creditmemo instanceof Creditmemo) {
            return;
        }
        try {
            $this->enqueuer->enqueueRefund($creditmemo, $creditmemo->getOrder(), time());
        } catch (\Throwable $e) {
            $this->log->log('could not queue refund for credit memo ' . $creditmemo->getIncrementId() . ': ' . $e->getMessage(), false);
        }
    }
}
