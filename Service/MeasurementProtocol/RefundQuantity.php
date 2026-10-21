<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Number of units of a parent line (bundle) that a credit memo refunds.
 *
 * Magento's credit memo row of a bundle does not hold the number of bundles refunded (it showed 4 for one bundle
 * with four options). The children are reliable: each child's refunded quantity divided by how many of that child
 * one bundle contains gives the number of bundles. A simple or configurable row uses its own quantity.
 */
class RefundQuantity
{
    /**
     * @param float $parentMemoQty    quantity on the credit memo row of the parent
     * @param float $parentOrderedQty quantity ordered of the parent order item
     * @param array<int, array{memo_qty: float, ordered_qty: float}> $children refunded/ordered quantity per child row
     */
    public function forParent(float $parentMemoQty, float $parentOrderedQty, array $children): float
    {
        if ($children === [] || $parentOrderedQty <= 0) {
            return $parentMemoQty;
        }
        $bundles = 0.0;
        foreach ($children as $child) {
            $perBundle = (float) $child['ordered_qty'] / $parentOrderedQty;
            if ($perBundle > 0) {
                $bundles = max($bundles, (float) $child['memo_qty'] / $perBundle);
            }
        }

        return $bundles > 0 ? round(min($bundles, $parentOrderedQty), 4) : $parentMemoQty;
    }
}
