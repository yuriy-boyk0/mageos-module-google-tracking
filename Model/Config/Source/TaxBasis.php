<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;

class TaxBasis implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => TotalsResolver::INCLUDING_TAX, 'label' => __('Including tax (order grand total, prices and shipping with tax)')],
            ['value' => TotalsResolver::EXCLUDING_TAX, 'label' => __('Excluding tax (grand total minus tax, prices and shipping without tax)')],
        ];
    }
}
