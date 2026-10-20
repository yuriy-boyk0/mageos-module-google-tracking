<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Model\Config;

class UnknownConsentAction implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::UNKNOWN_CONSENT_SEND, 'label' => __('Send anonymously (no visitor identifiers)')],
            ['value' => Config::UNKNOWN_CONSENT_SKIP, 'label' => __('Do not send')],
        ];
    }
}
