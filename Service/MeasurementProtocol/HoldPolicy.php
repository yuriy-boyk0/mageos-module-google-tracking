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
    /** Order states that end the hold for good: the purchase is closed as skipped, never sent automatically. */
    private const CLOSE_STATES = ['canceled', 'closed'];
    public const DEFAULT_MAX_HOLD_DAYS = 30;

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

    /**
     * A waiting purchase of an order that was canceled (payment failed or abandoned at the gateway) or closed is
     * closed as `skipped` with this reason; null when the row is not waiting or the order may still be paid.
     */
    public function closeReason(?array $purchaseRow, string $orderState): ?string
    {
        if ($purchaseRow === null || (string) $purchaseRow['status'] !== QueueStorageInterface::STATUS_WAITING) {
            return null;
        }

        return in_array($orderState, self::CLOSE_STATES, true) ? sprintf('order %s while held', $orderState) : null;
    }

    /**
     * *Skip Client-side purchase for Excluded Statuses*: true when the success page must not push `purchase`
     * because the order still has an excluded status (it is held server-side and never sent from the browser later).
     *
     * @param string[] $excludedStatuses
     */
    public function skipsClientPurchase(bool $setting, bool $serverSideEnabled, string $orderStatus, array $excludedStatuses): bool
    {
        return $setting && $serverSideEnabled && $orderStatus !== '' && in_array($orderStatus, $excludedStatuses, true);
    }

    /**
     * Waiting purchases created before this time are closed as `expired` by the daily cleanup (orders that never
     * left the excluded status). null = no limit (0 days).
     */
    public function expiryCutoff(int $now, int $maxHoldDays): ?int
    {
        return $maxHoldDays > 0 ? $now - $maxHoldDays * 86400 : null;
    }
}
