<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Minimal logging port so the core stays free of Magento/PSR dependencies.
 */
interface LogInterface
{
    public function log(string $message, bool $success): void;
}
