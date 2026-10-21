<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentModeSignals;

class ConsentMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ConsentModeSignals::OFF, 'label' => __('Off')],
            ['value' => ConsentModeSignals::BASIC, 'label' => __('Basic')],
            ['value' => ConsentModeSignals::ADVANCED, 'label' => __('Advanced')],
        ];
    }
}
