<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentModeSignals;

class GrantedSignals implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ConsentModeSignals::GRANT_ALL, 'label' => __('All signals (analytics and ads)')],
            ['value' => ConsentModeSignals::GRANT_ANALYTICS, 'label' => __('Analytics only (analytics_storage)')],
        ];
    }
}
