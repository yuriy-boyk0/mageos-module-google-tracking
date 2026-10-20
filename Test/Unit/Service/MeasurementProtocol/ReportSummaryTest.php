<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ReportSummary;
use PHPUnit\Framework\TestCase;

class ReportSummaryTest extends TestCase
{
    public function testCombinesOrdersAndDeliveryPerDay(): void
    {
        $days = (new ReportSummary())->build(
            ['2026-10-19' => 10, '2026-10-20' => 4],
            [
                ['day' => '2026-10-20', 'event_type' => 'purchase', 'status' => 'sent', 'total' => 3],
                ['day' => '2026-10-20', 'event_type' => 'purchase', 'status' => 'skipped', 'total' => 1],
                ['day' => '2026-10-19', 'event_type' => 'purchase', 'status' => 'sent', 'total' => 6],
                ['day' => '2026-10-19', 'event_type' => 'purchase', 'status' => 'failed', 'total' => 1],
                ['day' => '2026-10-19', 'event_type' => 'refund', 'status' => 'sent', 'total' => 2],
                ['day' => '2026-10-19', 'event_type' => 'refund', 'status' => 'failed', 'total' => 1],
                ['day' => '2026-10-19', 'event_type' => 'cancel', 'status' => 'sent', 'total' => 1],
            ]
        );

        $this->assertSame(['2026-10-20', '2026-10-19'], array_keys($days), 'newest day first');

        $this->assertSame(3, $days['2026-10-20']['purchase']['sent']);
        $this->assertSame(1, $days['2026-10-20']['purchase']['skipped']);
        $this->assertSame(0, $days['2026-10-20']['untracked']);
        $this->assertSame(75.0, $days['2026-10-20']['sent_rate']);

        $this->assertSame(3, $days['2026-10-19']['untracked'], '10 orders, 7 purchase rows');
        $this->assertSame(60.0, $days['2026-10-19']['sent_rate']);
        $this->assertSame(2, $days['2026-10-19']['refund_sent'], 'only sent follow-ups are counted');
        $this->assertSame(1, $days['2026-10-19']['cancel_sent']);
    }

    public function testDayWithEventsButNoOrders(): void
    {
        $days = (new ReportSummary())->build([], [
            ['day' => '2026-10-18', 'event_type' => 'refund', 'status' => 'sent', 'total' => 1],
        ]);
        $this->assertSame(0, $days['2026-10-18']['orders']);
        $this->assertNull($days['2026-10-18']['sent_rate']);
        $this->assertSame(1, $days['2026-10-18']['refund_sent']);
    }
}
