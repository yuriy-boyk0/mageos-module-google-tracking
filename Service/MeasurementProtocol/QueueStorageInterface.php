<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Persistence for server-side events. One row per (order, event type); the status doubles as the
 * per-order delivery status shown to admins.
 *
 * Row keys: entity_id, order_id, increment_id, store_id, event_type, reference_id ('' for purchase/cancel, the
 * credit memo id for refunds), payload (JSON string), status,
 * attempts, next_attempt_at (unix), last_error, created_at (unix), updated_at (unix), sent_at (unix|null)
 */
interface QueueStorageInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    /** Too old for Google to accept; kept for admin review, never sent. */
    public const STATUS_EXPIRED = 'expired';

    public const EVENT_PURCHASE = 'purchase';
    public const EVENT_REFUND = 'refund';
    public const EVENT_CANCEL = 'cancel';

    /**
     * Stores a new row. Returns false (and stores nothing) if a row for this order, event type and reference already
     * exists, so the same event can never be queued twice.
     *
     * @param array $row order_id, increment_id, store_id, event_type, reference_id, payload, status, last_error,
     *                   created_at (optional, defaults to $now)
     */
    public function enqueue(array $row, int $now): bool;

    /**
     * Pending rows that are due, plus 'processing' rows abandoned before $staleBefore (crashed worker).
     *
     * @return array[]
     */
    public function fetchDue(int $now, int $staleBefore, int $limit): array;

    /**
     * Atomically takes ownership of a row. Returns false if another worker got it first.
     */
    public function claim(int $entityId, int $now, int $staleBefore): bool;

    public function markSent(int $entityId, int $attempts, int $now): void;

    public function markRetry(int $entityId, int $attempts, int $nextAttemptAt, string $error, int $now): void;

    public function markFailed(int $entityId, int $attempts, string $error, int $now): void;

    /**
     * Closes a row that can no longer be delivered because Google rejects events older than ~72 hours.
     * The row is kept (with its payload) so an admin can review it, but it is never sent again.
     */
    public function markExpired(int $entityId, int $attempts, string $error, int $now): void;

    public function findByOrder(int $orderId, string $eventType, string $referenceId = ''): ?array;

    /**
     * Queues a failed/expired row again with a (refreshed) payload: status pending, attempts 0, age limit restarted.
     */
    public function resetForResend(int $entityId, string $payload, int $now): void;

    /**
     * Deletes rows with one of the statuses whose last update is older than $before. Returns the number deleted.
     *
     * @param string[] $statuses
     */
    public function deleteFinishedBefore(array $statuses, int $before): int;
}
