<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\DataLayer;

/**
 * Chooses the category path sent as item_category .. item_category5.
 *
 * Input: the category paths of the product's categories as id lists, root categories already removed
 * (e.g. [[20, 21, 23], [37]]). Excluded categories are taken out of every path (the next level moves up, e.g.
 * a "Sale" or "New" category), then the longest remaining path wins (the most specific category), and it is cut to
 * the configured depth (1 to 5).
 */
class CategoryPathPicker
{
    public const MAX_LEVELS = 5;

    /**
     * @param array<int, int[]> $paths
     * @param int[]             $excludedIds
     * @return int[]
     */
    public function pick(array $paths, array $excludedIds = [], int $depth = self::MAX_LEVELS): array
    {
        $excluded = array_flip(array_map('intval', $excludedIds));
        $best = [];
        foreach ($paths as $path) {
            $kept = array_values(array_filter(
                array_map('intval', $path),
                static fn(int $id) => !isset($excluded[$id])
            ));
            if (count($kept) > count($best)) {
                $best = $kept;
            }
        }
        $depth = max(1, min(self::MAX_LEVELS, $depth));

        return array_slice($best, 0, $depth);
    }

    /**
     * "12, 15,abc" => [12, 15]
     *
     * @return int[]
     */
    public function parseIds(string $config): array
    {
        $ids = [];
        foreach (explode(',', $config) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }
}
