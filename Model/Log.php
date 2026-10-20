<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Psr\Log\LoggerInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;

class Log implements LogInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function log(string $message, bool $success): void
    {
        $message = '[MageOS_GoogleTagManager] ' . $message;
        $success ? $this->logger->info($message) : $this->logger->warning($message);
    }
}
