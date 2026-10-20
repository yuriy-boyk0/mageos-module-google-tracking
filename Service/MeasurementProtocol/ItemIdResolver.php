<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Chooses the GA4 `item_id` for an order item: the parent (configurable product) SKU or the child (variant) SKU.
 *
 * Only configurable products have both. Magento stores the configurable's order row with the *variant's* SKU, so
 * the parent SKU has to come from the catalog product, and the child SKU from the child order row. Every other
 * product type (simple, bundle, ...) always uses its own order-row SKU.
 */
class ItemIdResolver
{
    public const PARENT = 'parent';
    public const CHILD = 'child';

    private const CONFIGURABLE = 'configurable';

    public function resolve(
        string $mode,
        string $productType,
        string $orderRowSku,
        ?string $childRowSku,
        ?string $catalogProductSku
    ): string {
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
