<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    public function testDelaysDoubleThenCap(): void
    {
        $policy = new RetryPolicy(60, 600);
        $this->assertSame(60, $policy->delayAfter(1));
        $this->assertSame(120, $policy->delayAfter(2));
        $this->assertSame(240, $policy->delayAfter(3));
        $this->assertSame(480, $policy->delayAfter(4));
        $this->assertSame(600, $policy->delayAfter(5));
        $this->assertSame(600, $policy->delayAfter(50));
    }

    public function testNonPositiveAttemptsUseTheBaseDelay(): void
    {
        $this->assertSame(60, (new RetryPolicy())->delayAfter(0));
    }
}
