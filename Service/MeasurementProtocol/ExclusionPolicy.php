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
    public const REASON_CUSTOMER_GROUP = 'excluded: customer group';
    public const REASON_MAX_VALUE = 'excluded: above the maximum transaction value';

    /**
     * @param array{grand_total: float, status: string, payment_method: string, is_admin_order: bool, customer_group_id?: int} $order
     *        grand_total in the base currency
     * @param array{exclude_zero_value: bool, excluded_statuses: string[], excluded_payment_methods: string[], include_admin_orders: bool,
     *              excluded_customer_groups?: int[], max_transaction_value?: float} $rules max 0 = no maximum
     * @return string|null the reason to skip, or null when the order should be tracked
     */
    public function skipReason(array $order, array $rules): ?string
    {
        if (!empty($order['is_admin_order']) && empty($rules['include_admin_orders'])) {
            return self::REASON_ADMIN_ORDER;
        }
        if (isset($order['customer_group_id'])
            && in_array((int) $order['customer_group_id'], array_map('intval', $rules['excluded_customer_groups'] ?? []), true)
        ) {
            return self::REASON_CUSTOMER_GROUP;
        }
        if (!empty($rules['exclude_zero_value']) && (float) $order['grand_total'] <= 0.0) {
            return self::REASON_ZERO_VALUE;
        }
        $max = (float) ($rules['max_transaction_value'] ?? 0);
        if ($max > 0 && (float) $order['grand_total'] > $max) {
            return self::REASON_MAX_VALUE;
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
