<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Raw numbers for the admin report. Both queries group by the order's creation day (UTC), so a row shows what
 * happened to the orders placed that day, including refunds/cancellations that came later.
 */
class ReportDataProvider
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    /**
     * @return array<string, int> 'Y-m-d' => orders placed
     */
    public function ordersByDay(string $sinceDate): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('sales_order'), ['day' => 'DATE(created_at)', 'total' => 'COUNT(*)'])
            ->where('created_at >= ?', $sinceDate)
            ->group('day');

        return array_map('intval', $connection->fetchPairs($select));
    }

    /**
     * @return array<int, array{day: string, event_type: string, status: string, total: int}>
     */
    public function eventsByOrderDay(string $sinceDate): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName(DbQueueStorage::TABLE)], ['event_type', 'status', 'total' => 'COUNT(*)'])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = e.order_id', ['day' => 'DATE(o.created_at)'])
            ->where('o.created_at >= ?', $sinceDate)
            ->group(['day', 'e.event_type', 'e.status']);

        return $connection->fetchAll($select);
    }
}
