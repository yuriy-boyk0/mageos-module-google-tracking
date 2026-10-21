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

    public function testRowsToRequeueTakesOnlyFollowUpsSkippedForTheUnsentPurchase(): void
    {
        $rows = [
            ['entity_id' => 1, 'status' => 'skipped', 'last_error' => 'skipped: server-side purchase was not sent (failed)'],
            ['entity_id' => 2, 'status' => 'skipped', 'last_error' => 'skipped: server-side purchase was not sent (expired)'],
            ['entity_id' => 3, 'status' => 'skipped', 'last_error' => 'skipped: no server-side purchase recorded for this order'],
            ['entity_id' => 4, 'status' => 'skipped', 'last_error' => 'skipped: server-side purchase was not sent (skipped)'],
            ['entity_id' => 5, 'status' => 'sent', 'last_error' => null],
            ['entity_id' => 6, 'status' => 'pending', 'last_error' => 'skipped: server-side purchase was not sent (failed)'],
            ['entity_id' => 7, 'status' => 'skipped', 'last_error' => 'tracking consent not given'],
            ['entity_id' => 8, 'status' => 'skipped', 'last_error' => null],
        ];

        $ids = array_column((new FollowUpPolicy())->rowsToRequeue($rows), 'entity_id');

        // 4 is included on purpose: whatever the purchase status was when the follow-up was skipped, the policy only
        // looks at the reason text; the resender runs it right after the purchase row was reset to pending.
        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function testRowsToRequeueOfNothingIsEmpty(): void
    {
        $this->assertSame([], (new FollowUpPolicy())->rowsToRequeue([]));
    }
}
