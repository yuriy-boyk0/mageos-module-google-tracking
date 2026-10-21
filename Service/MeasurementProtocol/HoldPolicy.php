<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Purchases of orders whose status is excluded at placement (typically `pending_payment` of redirect payment
 * methods) are not dropped: they wait (status `waiting`, payload already built with the visitor's consent and ids)
 * and are released into the queue as soon as the order reaches a status that is not excluded, e.g. `processing`
 * once the payment is confirmed.
 */
class HoldPolicy
{
    /** Order states in which a waiting purchase must never be released (not paid, or undone). */
    private const NEVER_RELEASE_STATES = ['pending_payment', 'payment_review', 'canceled', 'closed'];

    /**
     * @param string[] $excludedStatuses the configured *Exclude Order Statuses*
     */
    public function shouldRelease(?array $purchaseRow, string $orderState, string $orderStatus, array $excludedStatuses): bool
    {
        if ($purchaseRow === null || (string) $purchaseRow['status'] !== QueueStorageInterface::STATUS_WAITING) {
            return false;
        }
        if (in_array($orderState, self::NEVER_RELEASE_STATES, true)) {
            return false;
        }

        return !in_array($orderStatus, $excludedStatuses, true);
    }
}
