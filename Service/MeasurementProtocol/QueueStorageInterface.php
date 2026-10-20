<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Persistence for server-side events. One row per (order, event type); the status doubles as the
 * per-order delivery status shown to admins.
 *
 * Row keys: entity_id, order_id, increment_id, store_id, event_type, payload (JSON string), status,
 * attempts, next_attempt_at (unix), last_error, created_at (unix), updated_at (unix), sent_at (unix|null)
 */
interface QueueStorageInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const EVENT_PURCHASE = 'purchase';

    /**
     * Stores a new row. Returns false (and stores nothing) if a row for this order and event type already exists,
     * so an order can never be queued twice.
     *
     * @param array $row order_id, increment_id, store_id, event_type, payload, status, last_error
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
}
