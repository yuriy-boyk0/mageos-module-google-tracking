<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Applies the "Tax in Totals" setting to the order value.
 *
 * Item prices and shipping are always the amounts WITHOUT tax and the `tax` parameter is always the order tax
 * amount: exactly what the client-side purchase event sends (order item prices excluding tax, shipping excluding
 * tax), so client and server events describe the order with the same lines.
 *
 *  including: value = order grand total (tax and shipping included, same as the client-side purchase event).
 *  excluding: value = grand total minus tax (shipping stays in).
 */
class TotalsResolver
{
    public const INCLUDING_TAX = 'including';
    public const EXCLUDING_TAX = 'excluding';

    /**
     * @param array{grand_total: float, tax: float, shipping: float} $amounts shipping without tax
     * @return array{value: float, tax: float, shipping: float}
     */
    public function resolveOrder(array $amounts, string $basis): array
    {
        $grandTotal = (float) $amounts['grand_total'];
        $tax = (float) $amounts['tax'];
        $shipping = (float) $amounts['shipping'];

        $value = $basis === self::EXCLUDING_TAX ? max(0.0, $grandTotal - $tax) : $grandTotal;

        return ['value' => $value, 'tax' => $tax, 'shipping' => $shipping];
    }
}
