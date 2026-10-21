<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentModeSignals;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentState;
use PHPUnit\Framework\TestCase;

class ConsentModeSignalsTest extends TestCase
{
    private ConsentModeSignals $signals;

    protected function setUp(): void
    {
        $this->signals = new ConsentModeSignals();
    }

    public function testModeNormalization(): void
    {
        $this->assertSame('basic', $this->signals->normalizeMode('basic'));
        $this->assertSame('advanced', $this->signals->normalizeMode('advanced'));
        $this->assertSame('off', $this->signals->normalizeMode(''));
        $this->assertSame('off', $this->signals->normalizeMode('ADVANCED'));
    }

    public function testGrantedSignals(): void
    {
        $this->assertSame(
            ['ad_storage' => 'granted', 'analytics_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'granted'],
            $this->signals->grantedSignals('all')
        );
        $this->assertSame(
            ['ad_storage' => 'denied', 'analytics_storage' => 'granted', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied'],
            $this->signals->grantedSignals('analytics')
        );
    }

    public function testMeasurementProtocolConsent(): void
    {
        $this->assertNull($this->signals->measurementProtocolConsent('off', 'all', ConsentState::Granted));
        $this->assertSame(
            ['ad_user_data' => 'GRANTED', 'ad_personalization' => 'GRANTED'],
            $this->signals->measurementProtocolConsent('basic', 'all', ConsentState::Granted)
        );
        $this->assertSame(
            ['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED'],
            $this->signals->measurementProtocolConsent('advanced', 'analytics', ConsentState::Granted)
        );
        $this->assertSame(
            ['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED'],
            $this->signals->measurementProtocolConsent('advanced', 'all', ConsentState::Unknown),
            'anonymous sends never grant ad use'
        );
    }
}
