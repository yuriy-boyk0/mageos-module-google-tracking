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
            if ($existing['order_id'] === $row['order_id'] && $existing['event_type'] === $row['event_type']) {
                return false;
            }
        }
        $id = $this->nextId++;
        $this->rows[$id] = $row + [
            'entity_id' => $id,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'last_error' => null,
            'payload' => null,
            'store_id' => 1,
            'increment_id' => (string) $row['order_id'],
            'created_at' => $now,
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
}
