<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Refunds and cancellations are only sent for orders whose server-side purchase was (or is about to be) sent.
 * A purchase that was skipped (no consent, excluded) must not get a follow-up either, and one that failed or
 * expired would reverse revenue GA4 never received.
 */
class FollowUpPolicy
{
    public const REASON_NO_PURCHASE = 'skipped: no server-side purchase recorded for this order';
    public const REASON_PURCHASE_NOT_SENT = 'skipped: server-side purchase was not sent (%s)';

    /**
     * @param array|null $purchaseRow the order's purchase row, or null
     * @return string|null reason to skip, or null to send
     */
    public function skipReason(?array $purchaseRow): ?string
    {
        if ($purchaseRow === null) {
            return self::REASON_NO_PURCHASE;
        }
        $status = (string) $purchaseRow['status'];
        $ok = [QueueStorageInterface::STATUS_PENDING, QueueStorageInterface::STATUS_PROCESSING, QueueStorageInterface::STATUS_SENT];

        return in_array($status, $ok, true) ? null : sprintf(self::REASON_PURCHASE_NOT_SENT, $status);
    }
}
