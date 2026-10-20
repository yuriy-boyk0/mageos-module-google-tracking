<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\FollowUpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FollowUpPolicyTest extends TestCase
{
    #[DataProvider('cases')]
    public function testSkipReason(?string $purchaseStatus, bool $send): void
    {
        $row = $purchaseStatus === null ? null : ['status' => $purchaseStatus];
        $reason = (new FollowUpPolicy())->skipReason($row);

        $send ? $this->assertNull($reason) : $this->assertNotNull($reason);
    }

    public static function cases(): array
    {
        return [
            'no purchase row' => [null, false],
            'sent' => ['sent', true],
            'pending' => ['pending', true],
            'processing' => ['processing', true],
            'failed' => ['failed', false],
            'expired' => ['expired', false],
            'skipped (no consent)' => ['skipped', false],
        ];
    }

    public function testReasonNamesThePurchaseStatus(): void
    {
        $this->assertStringContainsString('expired', (new FollowUpPolicy())->skipReason(['status' => 'expired']));
    }
}
