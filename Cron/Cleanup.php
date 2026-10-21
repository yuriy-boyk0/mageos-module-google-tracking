<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Cron;

use MageOS\GoogleTagManager\Model\Config;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HoldPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

/**
 * Daily: deletes sent and skipped rows older than the retention period. Failed and expired rows are kept for
 * the admin to review or re-send. Purchases held (`waiting`) longer than *Maximum Hold* are closed as `expired`.
 */
class Cleanup
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueStorageInterface $storage,
        private readonly LogInterface $log,
        private readonly HoldPolicy $holdPolicy
    ) {}

    public function execute(): void
    {
        $this->expireHeldPurchases();
        $days = $this->config->getRetentionDays();
        if ($days === 0) {
            return;
        }
        try {
            $deleted = $this->storage->deleteFinishedBefore(
                [QueueStorageInterface::STATUS_SENT, QueueStorageInterface::STATUS_SKIPPED],
                time() - $days * 86400
            );
            if ($deleted > 0) {
                $this->log->log(sprintf('cleanup: deleted %d sent/skipped rows older than %d days', $deleted, $days), true);
            }
        } catch (\Throwable $e) {
            $this->log->log('cleanup failed: ' . $e->getMessage(), false);
        }
    }

    private function expireHeldPurchases(): void
    {
        $days = $this->config->getMaxHoldDays();
        $cutoff = $this->holdPolicy->expiryCutoff(time(), $days);
        if ($cutoff === null) {
            return;
        }
        try {
            $closed = $this->storage->expireWaitingBefore(
                $cutoff,
                sprintf('held more than %d days: the order never left an excluded status', $days),
                time()
            );
            if ($closed > 0) {
                $this->log->log(sprintf('cleanup: closed %d held purchases older than %d days as expired', $closed, $days), true);
            }
        } catch (\Throwable $e) {
            $this->log->log('expiring held purchases failed: ' . $e->getMessage(), false);
        }
    }
}
