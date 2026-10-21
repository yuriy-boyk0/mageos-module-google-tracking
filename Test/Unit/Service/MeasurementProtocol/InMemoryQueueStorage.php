<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

class InMemoryQueueStorage implements QueueStorageInterface
{
    /** @var array<int, array> */
    public array $rows = [];
    private int $nextId = 1;

    public function enqueue(array $row, int $now): bool
    {
        foreach ($this->rows as $existing) {
            if ($existing['order_id'] === $row['order_id'] && $existing['event_type'] === $row['event_type']
                && $existing['reference_id'] === ($row['reference_id'] ?? '')
            ) {
                return false;
            }
        }
        $id = $this->nextId++;
        $this->rows[$id] = $row + [
            'entity_id' => $id,
            'reference_id' => '',
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'last_error' => null,
            'payload' => null,
            'store_id' => 1,
            'increment_id' => (string) $row['order_id'],
            'created_at' => $row['created_at'] ?? $now,
            'updated_at' => $now,
            'sent_at' => null,
        ];
        $this->rows[$id]['entity_id'] = $id;

        return true;
    }

    public function fetchDue(int $now, int $staleBefore, int $limit): array
    {
        $due = array_filter($this->rows, static fn(array $r) =>
            ($r['status'] === self::STATUS_PENDING && $r['next_attempt_at'] <= $now)
            || ($r['status'] === self::STATUS_PROCESSING && $r['updated_at'] < $staleBefore));

        return array_slice(array_values($due), 0, $limit);
    }

    public function claim(int $entityId, int $now, int $staleBefore): bool
    {
        $row = &$this->rows[$entityId];
        if ($row['status'] === self::STATUS_PENDING
            || ($row['status'] === self::STATUS_PROCESSING && $row['updated_at'] < $staleBefore)
        ) {
            $row['status'] = self::STATUS_PROCESSING;
            $row['updated_at'] = $now;

            return true;
        }

        return false;
    }

    public function markSent(int $entityId, int $attempts, int $now): void
    {
        $this->rows[$entityId] = ['status' => self::STATUS_SENT, 'attempts' => $attempts, 'sent_at' => $now, 'updated_at' => $now, 'last_error' => null]
            + $this->rows[$entityId];
    }

    public function markRetry(int $entityId, int $attempts, int $nextAttemptAt, string $error, int $now): void
    {
        $this->rows[$entityId] = [
            'status' => self::STATUS_PENDING, 'attempts' => $attempts, 'next_attempt_at' => $nextAttemptAt,
            'last_error' => $error, 'updated_at' => $now,
        ] + $this->rows[$entityId];
    }

    public function markFailed(int $entityId, int $attempts, string $error, int $now): void
    {
        $this->rows[$entityId] = ['status' => self::STATUS_FAILED, 'attempts' => $attempts, 'last_error' => $error, 'updated_at' => $now]
            + $this->rows[$entityId];
    }

    public function markExpired(int $entityId, int $attempts, string $error, int $now): void
    {
        $this->rows[$entityId] = ['status' => self::STATUS_EXPIRED, 'attempts' => $attempts, 'last_error' => $error, 'updated_at' => $now]
            + $this->rows[$entityId];
    }

    public function findByOrder(int $orderId, string $eventType, string $referenceId = ''): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['order_id'] === $orderId && $row['event_type'] === $eventType && $row['reference_id'] === $referenceId) {
                return $row;
            }
        }

        return null;
    }

    public function findFollowUpsByOrder(int $orderId): array
    {
        return array_values(array_filter($this->rows, static fn(array $r) =>
            $r['order_id'] === $orderId && in_array($r['event_type'], [self::EVENT_REFUND, self::EVENT_CANCEL], true)));
    }

    public function deleteRow(int $entityId): void
    {
        unset($this->rows[$entityId]);
    }

    public function resetForResend(int $entityId, string $payload, int $now): void
    {
        $this->rows[$entityId] = [
            'status' => self::STATUS_PENDING, 'attempts' => 0, 'next_attempt_at' => $now, 'payload' => $payload,
            'last_error' => 'manual re-send', 'created_at' => $now, 'updated_at' => $now, 'sent_at' => null,
        ] + $this->rows[$entityId];
    }

    public function deleteFinishedBefore(array $statuses, int $before): int
    {
        $deleted = 0;
        foreach ($this->rows as $id => $row) {
            if (in_array($row['status'], $statuses, true) && $row['updated_at'] < $before) {
                unset($this->rows[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }
}
