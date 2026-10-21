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
}
