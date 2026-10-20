<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

class RetryPolicy
{
    public function __construct(
        private readonly int $baseDelaySeconds = 60,
        private readonly int $maxDelaySeconds = 21600
    ) {}

    /**
     * Seconds to wait before the next attempt, after $attempts failed attempts (exponential backoff, capped).
     */
    public function delayAfter(int $attempts): int
    {
        $attempts = max(1, $attempts);
        $delay = $this->baseDelaySeconds * (2 ** min($attempts - 1, 20));

        return min($delay, $this->maxDelaySeconds);
    }
}
