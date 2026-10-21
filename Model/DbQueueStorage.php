<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

class DbQueueStorage implements QueueStorageInterface
{
    public const TABLE = 'mageos_gtm_server_event';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function enqueue(array $row, int $now): bool
    {
        $status = $row['status'] ?? self::STATUS_PENDING;
        try {
            $this->connection()->insert($this->table(), [
                'order_id' => (int) $row['order_id'],
                'increment_id' => (string) ($row['increment_id'] ?? ''),
                'store_id' => (int) ($row['store_id'] ?? 0),
                'event_type' => (string) $row['event_type'],
                'reference_id' => (string) ($row['reference_id'] ?? ''),
                'payload' => $row['payload'] ?? null,
                'status' => $status,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'last_error' => isset($row['last_error']) ? mb_substr((string) $row['last_error'], 0, 255) : null,
                'created_at' => (int) ($row['created_at'] ?? $now),
                'updated_at' => $now,
            ]);
        } catch (DuplicateException $e) {
            // Unique key (order_id, event_type, reference_id): this event was already queued.
            return false;
        }

        return true;
    }

    public function fetchDue(int $now, int $staleBefore, int $limit): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table())
            ->where(
                '(status = ' . $connection->quote(self::STATUS_PENDING) . ' AND next_attempt_at <= ' . (int) $now . ')'
                . ' OR (status = ' . $connection->quote(self::STATUS_PROCESSING) . ' AND updated_at < ' . (int) $staleBefore . ')'
            )
            ->order('next_attempt_at ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    public function claim(int $entityId, int $now, int $staleBefore): bool
    {
        $connection = $this->connection();
        $affected = $connection->update(
            $this->table(),
            ['status' => self::STATUS_PROCESSING, 'updated_at' => $now],
            [
                'entity_id = ?' => $entityId,
                '(status = ' . $connection->quote(self::STATUS_PENDING)
                    . ' OR (status = ' . $connection->quote(self::STATUS_PROCESSING) . ' AND updated_at < ' . (int) $staleBefore . '))',
            ]
        );

        return $affected > 0;
    }

    public function markSent(int $entityId, int $attempts, int $now): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_SENT,
                'attempts' => $attempts,
                'last_error' => null,
                'sent_at' => $now,
                'updated_at' => $now,
            ],
            ['entity_id = ?' => $entityId]
        );
    }

    public function markRetry(int $entityId, int $attempts, int $nextAttemptAt, string $error, int $now): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_PENDING,
                'attempts' => $attempts,
                'next_attempt_at' => $nextAttemptAt,
                'last_error' => mb_substr($error, 0, 255),
                'updated_at' => $now,
            ],
            ['entity_id = ?' => $entityId]
        );
    }

    public function markFailed(int $entityId, int $attempts, string $error, int $now): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_FAILED,
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 255),
                'updated_at' => $now,
            ],
            ['entity_id = ?' => $entityId]
        );
    }

    public function markExpired(int $entityId, int $attempts, string $error, int $now): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_EXPIRED,
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 255),
                'updated_at' => $now,
            ],
            ['entity_id = ?' => $entityId]
        );
    }

    public function findByOrder(int $orderId, string $eventType, string $referenceId = ''): ?array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table())
                ->where('order_id = ?', $orderId)
                ->where('event_type = ?', $eventType)
                ->where('reference_id = ?', $referenceId)
        );

        return $row ?: null;
    }

    public function findFollowUpsByOrder(int $orderId): array
    {
        $connection = $this->connection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->table())
                ->where('order_id = ?', $orderId)
                ->where('event_type IN (?)', [self::EVENT_REFUND, self::EVENT_CANCEL])
        );
    }

    public function deleteRow(int $entityId): void
    {
        $this->connection()->delete($this->table(), ['entity_id = ?' => $entityId]);
    }

    public function resetForResend(int $entityId, string $payload, int $now): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status' => self::STATUS_PENDING,
                'payload' => $payload,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'last_error' => 'manual re-send',
                'created_at' => $now,
                'updated_at' => $now,
                'sent_at' => null,
            ],
            ['entity_id = ?' => $entityId]
        );
    }

    public function deleteFinishedBefore(array $statuses, int $before): int
    {
        if ($statuses === []) {
            return 0;
        }

        return $this->connection()->delete(
            $this->table(),
            ['status IN (?)' => $statuses, 'updated_at < ?' => $before]
        );
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
