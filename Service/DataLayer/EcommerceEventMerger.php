<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\DataLayer;

/**
 * Combines ecommerce events of the same type that happen before the browser picks them up (one customer-data
 * reload can follow several adds to cart, e.g. AJAX adds from a listing, or a grouped product adding several
 * children). Instead of the last one overwriting the others, the items are appended to the pending event and
 * `value` is recomputed from all items.
 */
class EcommerceEventMerger
{
    public function merge(?array $pending, array $new): array
    {
        $pendingItems = $pending['ecommerce']['items'] ?? null;
        $newItems = $new['ecommerce']['items'] ?? null;
        if (!is_array($pendingItems) || !is_array($newItems)
            || ($pending['event'] ?? null) !== ($new['event'] ?? null)
            // Different currencies cannot share one value: keep the newest event.
            || ($pending['ecommerce']['currency'] ?? null) !== ($new['ecommerce']['currency'] ?? null)
        ) {
            return $new;
        }

        $merged = $new;
        $merged['ecommerce']['items'] = array_values(array_merge($pendingItems, $newItems));
        $merged['ecommerce']['value'] = $this->value($merged['ecommerce']['items']);

        return $merged;
    }

    private function value(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) ($item['price'] ?? 0) * (float) ($item['quantity'] ?? 1);
        }

        return round($total, 2);
    }
}
