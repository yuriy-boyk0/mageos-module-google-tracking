<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Chooses the GA4 `item_id` for an order item: the parent (configurable product) SKU or the child (variant) SKU.
 *
 * Only configurable products have both. Magento stores the configurable's order row with the *variant's* SKU, so
 * the parent SKU has to come from the catalog product, and the child SKU from the child order row.
 *
 * Bundles always use the bundle's own catalog SKU: with a dynamic SKU Magento composes the row SKU from the selected
 * options ("24-WG080-24-WG081-blue-..."), which would turn one product into many GA4 items. Every other product
 * type (simple, virtual, downloadable, grouped children) uses its own row SKU.
 *
 * Used by the client-side events and the server-side purchase/refund alike, so one product has one item_id.
 */
class ItemIdResolver
{
    public const PARENT = 'parent';
    public const CHILD = 'child';

    private const CONFIGURABLE = 'configurable';
    private const BUNDLE = 'bundle';

    public function resolve(
        string $mode,
        string $productType,
        string $orderRowSku,
        ?string $childRowSku,
        ?string $catalogProductSku
    ): string {
        if ($productType === self::BUNDLE) {
            return $this->firstNonEmpty($catalogProductSku, $orderRowSku);
        }
        if ($productType !== self::CONFIGURABLE) {
            return $orderRowSku;
        }

        if ($mode === self::CHILD) {
            return $this->firstNonEmpty($childRowSku, $orderRowSku);
        }

        return $this->firstNonEmpty($catalogProductSku, $orderRowSku);
    }

    private function firstNonEmpty(?string $preferred, string $fallback): string
    {
        return $preferred !== null && $preferred !== '' ? $preferred : $fallback;
    }
}
