<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ItemIdResolver;

class ItemIdMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ItemIdResolver::PARENT, 'label' => __('Parent SKU (the configurable product)')],
            ['value' => ItemIdResolver::CHILD, 'label' => __('Child SKU (the selected variant)')],
        ];
    }
}
