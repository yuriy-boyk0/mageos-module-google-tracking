<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use MageOS\GoogleTagManager\Model\DbQueueStorage;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

/**
 * Adds the server-side purchase delivery status (column `mageos_gtm_status`) to the admin order grid.
 *
 * Joined as a derived table that only exposes order id + status under unique aliases, so none of the grid's
 * unqualified filters/sorts (status, created_at, store_id, ...) become ambiguous.
 */
class OrderGridDeliveryStatus
{
    private const GRID = 'sales_order_grid_data_source';
    public const COLUMN = 'mageos_gtm_status';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function afterGetReport(CollectionFactory $subject, $collection, $requestName)
    {
        if ($requestName !== self::GRID || !$collection instanceof AbstractDb || $collection->getFlag(self::COLUMN)) {
            return $collection;
        }
        $connection = $this->resource->getConnection();
        $statuses = $connection->select()
            ->from(
                $this->resource->getTableName(DbQueueStorage::TABLE),
                ['mageos_gtm_order_id' => 'order_id', self::COLUMN => 'status']
            )
            ->where('event_type = ?', QueueStorageInterface::EVENT_PURCHASE)
            ->where('reference_id = ?', '');

        $collection->getSelect()->joinLeft(
            ['mageos_gtm' => $statuses],
            'mageos_gtm.mageos_gtm_order_id = main_table.entity_id',
            [self::COLUMN => 'mageos_gtm.' . self::COLUMN]
        );
        $collection->addFilterToMap(self::COLUMN, 'mageos_gtm.' . self::COLUMN);
        $collection->setFlag(self::COLUMN, true);

        return $collection;
    }
}
