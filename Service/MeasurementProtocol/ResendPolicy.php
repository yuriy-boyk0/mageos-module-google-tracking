<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * What the admin "re-send server-side purchase" action does for one order.
 */
class ResendPolicy
{
    public const CREATE = 'create';   // no row yet (placed before the feature was enabled): build one
    public const RESET = 'reset';     // failed or expired: queue the stored payload again
    public const IGNORE = 'ignore';

    /**
     * @return array{0: string, 1: string} [action, reason shown to the admin when ignored]
     */
    public function decide(?array $purchaseRow): array
    {
        if ($purchaseRow === null) {
            return [self::CREATE, ''];
        }

        return match ((string) $purchaseRow['status']) {
            QueueStorageInterface::STATUS_FAILED, QueueStorageInterface::STATUS_EXPIRED => [self::RESET, ''],
            QueueStorageInterface::STATUS_SENT => [self::IGNORE, 'already sent'],
            QueueStorageInterface::STATUS_PENDING, QueueStorageInterface::STATUS_PROCESSING => [self::IGNORE, 'already queued'],
            QueueStorageInterface::STATUS_WAITING => [self::IGNORE, 'waiting for an order status that is not excluded (sent automatically then)'],
            // Skipped for consent or an exclusion rule: re-sending would override that decision.
            QueueStorageInterface::STATUS_SKIPPED => [self::IGNORE, 'skipped on purpose: ' . (string) ($purchaseRow['last_error'] ?? '')],
            default => [self::IGNORE, 'unknown status'],
        };
    }

    /**
     * A re-sent event is recorded by GA4 at the time it is received: the original timestamp is dropped because
     * Google rejects events older than 72 hours, and the queue's age limit restarts from the re-send.
     */
    public function preparePayload(string $storedPayload): ?string
    {
        $payload = json_decode($storedPayload, true);
        if (!is_array($payload) || empty($payload['events'])) {
            return null;
        }
        unset($payload['timestamp_micros']);

        return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: null;
    }
}
