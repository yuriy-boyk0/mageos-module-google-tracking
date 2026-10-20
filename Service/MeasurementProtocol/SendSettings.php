<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

class SendSettings
{
    public function __construct(
        public readonly string $measurementId,
        public readonly string $apiSecret,
        public readonly int $maxAttempts,
        public readonly bool $debug,
        /** Adds debug_mode=1 so events show in GA4 DebugView (sent to the real endpoint, so they are recorded). */
        public readonly bool $debugView = false
    ) {}
}
