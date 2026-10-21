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
            ['value' => TotalsResolver::INCLUDING_TAX, 'label' => __('Including tax (value = order grand total, like the client-side purchase event)')],
            ['value' => TotalsResolver::EXCLUDING_TAX, 'label' => __('Excluding tax (value = order grand total minus tax)')],
        ];
    }
}
