<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CategoryDepth implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([1, 2, 3, 4, 5] as $depth) {
            $options[] = ['value' => (string) $depth, 'label' => __('%1 level(s)', $depth)];
        }

        return $options;
    }
}
