<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Cron;

use MageOS\GoogleTagManager\Model\Config;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

/**
 * Daily: deletes sent and skipped rows older than the retention period. Failed and expired rows are kept for
 * the admin to review or re-send.
 */
class Cleanup
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueStorageInterface $storage,
        private readonly LogInterface $log
    ) {}

    public function execute(): void
    {
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
}
