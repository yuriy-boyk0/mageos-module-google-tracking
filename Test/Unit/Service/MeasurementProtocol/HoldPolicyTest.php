<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\HoldPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HoldPolicyTest extends TestCase
{
    #[DataProvider('cases')]
    public function testShouldRelease(?string $rowStatus, string $state, string $status, bool $release): void
    {
        $row = $rowStatus === null ? null : ['status' => $rowStatus];
        $this->assertSame($release, (new HoldPolicy())->shouldRelease($row, $state, $status, ['pending_payment', 'fraud']));
    }

    public static function cases(): array
    {
        return [
            'paid: waiting row released' => ['waiting', 'processing', 'processing', true],
            'completed directly' => ['waiting', 'complete', 'complete', true],
            'still pending payment' => ['waiting', 'pending_payment', 'pending_payment', false],
            'payment review' => ['waiting', 'payment_review', 'payment_review', false],
            'canceled (payment failed)' => ['waiting', 'canceled', 'canceled', false],
            'closed' => ['waiting', 'closed', 'closed', false],
            'other excluded status' => ['waiting', 'processing', 'fraud', false],
            'no row' => [null, 'processing', 'processing', false],
            'already pending in the queue' => ['pending', 'processing', 'processing', false],
            'skipped for consent stays skipped' => ['skipped', 'processing', 'processing', false],
            'already sent' => ['sent', 'processing', 'processing', false],
        ];
    }

    #[DataProvider('closeCases')]
    public function testCloseReason(?string $rowStatus, string $state, ?string $reason): void
    {
        $row = $rowStatus === null ? null : ['status' => $rowStatus];
        $this->assertSame($reason, (new HoldPolicy())->closeReason($row, $state));
    }

    public static function closeCases(): array
    {
        return [
            'canceled while held' => ['waiting', 'canceled', 'order canceled while held'],
            'closed while held' => ['waiting', 'closed', 'order closed while held'],
            'still unpaid: keep waiting' => ['waiting', 'pending_payment', null],
            'payment review: keep waiting' => ['waiting', 'payment_review', null],
            'paid: released, not closed' => ['waiting', 'processing', null],
            'held for another status (holded)' => ['waiting', 'holded', null],
            'sent purchase of a canceled order is untouched' => ['sent', 'canceled', null],
            'pending purchase of a canceled order is untouched' => ['pending', 'canceled', null],
            'no row' => [null, 'canceled', null],
        ];
    }

    public function testExpiryCutoff(): void
    {
        $policy = new HoldPolicy();
        $this->assertSame(1_000_000 - 30 * 86400, $policy->expiryCutoff(1_000_000, 30));
        $this->assertSame(1_000_000 - 86400, $policy->expiryCutoff(1_000_000, 1));
        $this->assertNull($policy->expiryCutoff(1_000_000, 0), '0 days = no limit');
        $this->assertNull($policy->expiryCutoff(1_000_000, -5));
    }

    #[DataProvider('clientCases')]
    public function testSkipsClientPurchase(bool $setting, bool $serverSide, string $status, bool $skip): void
    {
        $this->assertSame($skip, (new HoldPolicy())->skipsClientPurchase($setting, $serverSide, $status, ['pending_payment', 'fraud']));
    }

    public static function clientCases(): array
    {
        return [
            'setting on, unpaid order' => [true, true, 'pending_payment', true],
            'setting on, other excluded status' => [true, true, 'fraud', true],
            'setting on, paid order' => [true, true, 'processing', false],
            'setting off (default)' => [false, true, 'pending_payment', false],
            'server side disabled' => [true, false, 'pending_payment', false],
            'no status' => [true, true, '', false],
        ];
    }
}
