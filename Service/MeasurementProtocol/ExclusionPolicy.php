<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Decides whether an order should be left out of server-side tracking, and why.
 */
class ExclusionPolicy
{
    public const REASON_ZERO_VALUE = 'excluded: zero value order';
    public const REASON_STATUS = 'excluded: order status';
    public const REASON_PAYMENT_METHOD = 'excluded: payment method';
    public const REASON_ADMIN_ORDER = 'excluded: admin-created order';

    /**
     * @param array{grand_total: float, status: string, payment_method: string, is_admin_order: bool} $order
     * @param array{exclude_zero_value: bool, excluded_statuses: string[], excluded_payment_methods: string[], include_admin_orders: bool} $rules
     * @return string|null the reason to skip, or null when the order should be tracked
     */
    public function skipReason(array $order, array $rules): ?string
    {
        if (!empty($order['is_admin_order']) && empty($rules['include_admin_orders'])) {
            return self::REASON_ADMIN_ORDER;
        }
        if (!empty($rules['exclude_zero_value']) && (float) $order['grand_total'] <= 0.0) {
            return self::REASON_ZERO_VALUE;
        }
        if (in_array($order['status'], $rules['excluded_statuses'] ?? [], true)) {
            return self::REASON_STATUS;
        }
        if (in_array($order['payment_method'], $rules['excluded_payment_methods'] ?? [], true)) {
            return self::REASON_PAYMENT_METHOD;
        }

        return null;
    }
}
