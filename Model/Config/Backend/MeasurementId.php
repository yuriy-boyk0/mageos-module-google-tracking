<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConfigIdValidator;

/**
 * Rejects a GA4 Measurement ID that has the wrong format (for example a GTM "GTM-" ID pasted by mistake).
 */
class MeasurementId extends Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '' && !(new ConfigIdValidator())->isValidMeasurementId($value)) {
            throw new LocalizedException(
                __('"%1" is not a valid GA4 Measurement ID. It must look like G-XXXXXXXXXX.', $value)
            );
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
