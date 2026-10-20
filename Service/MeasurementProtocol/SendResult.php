<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

class SendResult
{
    /**
     * @param string[] $validationMessages Only filled by the debug endpoint
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $retryable,
        public readonly ?int $statusCode = null,
        public readonly string $message = '',
        public readonly array $validationMessages = []
    ) {}
}
