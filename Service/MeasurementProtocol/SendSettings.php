<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

class SendSettings
{
    public function __construct(
        public readonly string $measurementId,
        public readonly string $apiSecret,
        public readonly int $maxAttempts,
        public readonly bool $debug
    ) {}
}
