<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use MageOS\GoogleTagManager\Service\DataLayer\GtmScriptUrl as GtmScriptUrlRule;

/**
 * Rejects a Custom GTM Script URL that is not an https origin (a path, plain http, ...) and saves it normalized.
 */
class GtmScriptUrl extends Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '') {
            $origin = (new GtmScriptUrlRule())->normalize($value);
            if ($origin === null) {
                throw new LocalizedException(__(
                    '"%1" is not a valid Custom GTM Script URL. Enter the domain only, with HTTPS and without a path, for example https://gtm.example.com.',
                    $value
                ));
            }
            $value = $origin;
        }
        $this->setValue($value);

        return parent::beforeSave();
    }
}
