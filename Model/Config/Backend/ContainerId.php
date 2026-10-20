<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConfigIdValidator;

/**
 * Rejects a GTM container ID that has the wrong format (for example a GA4 "G-" ID pasted by mistake).
 */
class ContainerId extends Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '' && !(new ConfigIdValidator())->isValidContainerId($value)) {
            throw new LocalizedException(
                __('"%1" is not a valid Google Tag Manager container ID. It must look like GTM-XXXXXX.', $value)
            );
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
