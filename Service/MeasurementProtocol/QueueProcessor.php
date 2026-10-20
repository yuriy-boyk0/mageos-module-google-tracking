<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Sends due queue rows to Google. Called by cron, never from the checkout request.
 */
class QueueProcessor
{
    public const PROCESSING_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly QueueStorageInterface $storage,
        private readonly Client $client,
        private readonly SendSettingsProviderInterface $settingsProvider,
        private readonly RetryPolicy $retryPolicy,
        private readonly LogInterface $logger
    ) {}

    /**
     * @return array{sent: int, retried: int, failed: int, skipped: int}
     */
    public function process(int $now, int $limit = 50): array
    {
        $stats = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        $staleBefore = $now - self::PROCESSING_TIMEOUT_SECONDS;

        foreach ($this->storage->fetchDue($now, $staleBefore, $limit) as $row) {
            $id = (int) $row['entity_id'];
            if (!$this->storage->claim($id, $now, $staleBefore)) {
                $stats['skipped']++;
                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            $settings = $this->settingsProvider->getSettings((int) $row['store_id']);
            if ($settings === null) {
                $this->storage->markFailed($id, $attempts, 'Server-side tracking is disabled or not configured for this store', $now);
                $stats['failed']++;
                continue;
            }

            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                $this->storage->markFailed($id, $attempts, 'Stored payload is not valid JSON', $now);
                $stats['failed']++;
                continue;
            }

            $result = $this->client->send($settings->measurementId, $settings->apiSecret, $payload, $settings->debug);
            $this->logResult($row, $result, $settings->debug);

            if ($result->success) {
                // In debug mode nothing is recorded by Google, so the row is not a real delivery.
                // It is still closed, otherwise it would be re-sent forever.
                $this->storage->markSent($id, $attempts, $now);
                $stats['sent']++;
            } elseif ($result->retryable && $attempts < $settings->maxAttempts) {
                $this->storage->markRetry($id, $attempts, $now + $this->retryPolicy->delayAfter($attempts), $this->describe($result), $now);
                $stats['retried']++;
            } else {
                $this->storage->markFailed($id, $attempts, $this->describe($result), $now);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function describe(SendResult $result): string
    {
        $text = $result->message;
        if ($result->validationMessages !== []) {
            $text .= ': ' . implode('; ', $result->validationMessages);
        }

        return mb_substr($text, 0, 255);
    }

    private function logResult(array $row, SendResult $result, bool $debug): void
    {
        if (!$debug && $result->success) {
            return;
        }
        $this->logger->log(sprintf(
            'order %s (%s): %s%s',
            $row['increment_id'] ?? $row['order_id'],
            $row['event_type'] ?? '',
            $result->success ? 'ok' : 'failed',
            ' - ' . $this->describe($result)
        ), $result->success);
    }
}
