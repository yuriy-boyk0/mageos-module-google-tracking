<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Model\Config;

class CurrencyBasis implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::CURRENCY_DISPLAY, 'label' => __('Order currency (what the customer paid)')],
            ['value' => Config::CURRENCY_BASE, 'label' => __('Base currency of the store')],
        ];
    }
}
