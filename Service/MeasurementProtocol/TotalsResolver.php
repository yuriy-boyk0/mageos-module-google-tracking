<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Applies the "send tax inclusive totals" setting to order and item amounts.
 *
 *  including: value = order grand total (tax and shipping included, same as the client-side purchase event),
 *             shipping and item prices with tax.
 *  excluding: value = grand total minus tax (shipping stays in), shipping and item prices without tax.
 *
 * The `tax` parameter is always the order tax amount, whatever the setting.
 */
class TotalsResolver
{
    public const INCLUDING_TAX = 'including';
    public const EXCLUDING_TAX = 'excluding';

    /**
     * @param array{grand_total: float, tax: float, shipping: float, shipping_incl_tax: float|null} $amounts
     * @return array{value: float, tax: float, shipping: float}
     */
    public function resolveOrder(array $amounts, string $basis): array
    {
        $grandTotal = (float) $amounts['grand_total'];
        $tax = (float) $amounts['tax'];
        $shippingExcl = (float) $amounts['shipping'];
        $shippingIncl = isset($amounts['shipping_incl_tax']) ? (float) $amounts['shipping_incl_tax'] : $shippingExcl;

        if ($basis === self::EXCLUDING_TAX) {
            return ['value' => max(0.0, $grandTotal - $tax), 'tax' => $tax, 'shipping' => $shippingExcl];
        }

        return ['value' => $grandTotal, 'tax' => $tax, 'shipping' => $shippingIncl];
    }

    /**
     * Unit price of an item. Falls back to the price without tax when the tax-inclusive price is not stored
     * (tax calculation disabled).
     *
     * The tax-inclusive price may arrive as a numeric string: Magento models loaded from the database
     * (credit memo items, orders re-sent from the admin) return decimals as strings, while freshly placed orders hold floats.
     */
    public function resolveItemPrice(float $priceExclTax, float|string|null $priceInclTax, string $basis): float
    {
        $inclTax = ($priceInclTax === null || $priceInclTax === '') ? null : (float) $priceInclTax;
        if ($basis === self::EXCLUDING_TAX || $inclTax === null || $inclTax <= 0.0) {
            return $priceExclTax;
        }

        return $inclTax;
    }
}
