<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\ResourceModel\ServerEvent\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Admin grid "GA4 Server-side Events": one row per queued event, with the order date from sales_order.
 *
 * Both tables have entity_id, increment_id, store_id, status and created_at, so every filterable/sortable field
 * is mapped to a qualified column to keep the grid's WHERE/ORDER BY unambiguous.
 */
class Collection extends SearchResult
{
    protected function _initSelect(): void
    {
        parent::_initSelect();
        $this->getSelect()->joinLeft(
            ['sales_order' => $this->getTable('sales_order')],
            'sales_order.entity_id = main_table.order_id',
            ['order_date' => 'sales_order.created_at']
        );

        foreach (['entity_id', 'order_id', 'increment_id', 'store_id', 'event_type', 'status', 'last_error', 'created_at', 'updated_at', 'sent_at'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
        $this->addFilterToMap('order_date', 'sales_order.created_at');
    }
}
