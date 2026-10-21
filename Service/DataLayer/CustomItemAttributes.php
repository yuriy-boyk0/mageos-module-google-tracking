<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\DataLayer;

/**
 * Parses the "Custom Item Parameters" setting: up to five `parameter_name:attribute_code` pairs, one per line or
 * comma-separated, e.g. "item_color:color, item_material:material". Each becomes a custom item parameter on every
 * item (register it in GA4 as an item-scoped custom dimension to see it in reports).
 */
class CustomItemAttributes
{
    public const MAX = 5;

    /** Parameter names that GA4 or this module already use for items. */
    private const RESERVED = [
        'item_id', 'item_name', 'affiliation', 'coupon', 'discount', 'index', 'item_brand', 'item_category',
        'item_category2', 'item_category3', 'item_category4', 'item_category5', 'item_list_id', 'item_list_name',
        'item_variant', 'location_id', 'price', 'quantity', 'promotion_id', 'promotion_name', 'creative_name',
        'creative_slot',
    ];

    /**
     * @return array<string, string> parameter name => attribute code (invalid or reserved entries are skipped)
     */
    public function parse(string $config): array
    {
        $result = [];
        foreach (preg_split('/[\r\n,]+/', $config) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !str_contains($entry, ':')) {
                continue;
            }
            [$param, $code] = array_map('trim', explode(':', $entry, 2));
            $param = strtolower($param);
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $param) !== 1
                || preg_match('/^[a-z][a-z0-9_]{0,59}$/', $code) !== 1
                || in_array($param, self::RESERVED, true)
                || str_starts_with($param, 'google_') || str_starts_with($param, 'ga_') || str_starts_with($param, 'firebase_')
                || isset($result[$param])
            ) {
                continue;
            }
            $result[$param] = $code;
            if (count($result) === self::MAX) {
                break;
            }
        }

        return $result;
    }
}
