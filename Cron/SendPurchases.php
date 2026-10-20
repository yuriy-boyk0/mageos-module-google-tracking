<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Cron;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueProcessor;

class SendPurchases
{
    public function __construct(
        private readonly QueueProcessor $processor,
        private readonly LogInterface $log
    ) {}

    public function execute(): void
    {
        try {
            $stats = $this->processor->process(time());
        } catch (\Throwable $e) {
            $this->log->log('queue run crashed: ' . $e->getMessage(), false);
            return;
        }
        if (array_sum($stats) > 0) {
            $this->log->log('queue run: ' . json_encode($stats), true);
        }
    }
}
