<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Format checks for the two Google IDs that are easy to swap by mistake.
 */
class ConfigIdValidator
{
    public function isValidContainerId(string $value): bool
    {
        return preg_match('/^GTM-[A-Z0-9]{4,}$/', $value) === 1;
    }

    public function isValidMeasurementId(string $value): bool
    {
        return preg_match('/^G-[A-Z0-9]{4,}$/', $value) === 1;
    }
}
