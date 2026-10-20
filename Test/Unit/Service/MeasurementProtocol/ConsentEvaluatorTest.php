<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentEvaluator;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConsentEvaluatorTest extends TestCase
{
    private ConsentEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConsentEvaluator();
    }

    public function testAdminAreaIsUnknownEvenWithCookies(): void
    {
        $this->assertSame(ConsentState::Unknown, $this->evaluator->evaluate(true, rawurlencode('{"1":1}'), true, 1));
    }

    public function testMagentoCookieAcceptedForCurrentWebsiteIsGranted(): void
    {
        $this->assertSame(ConsentState::Granted, $this->evaluator->evaluate(false, rawurlencode('{"1":1,"2":0}'), true, 1));
    }

    public function testMagentoCookieAcceptedOnlyForAnotherWebsiteIsDeclined(): void
    {
        $this->assertSame(ConsentState::Declined, $this->evaluator->evaluate(false, rawurlencode('{"1":1}'), true, 2));
        $this->assertSame(ConsentState::Declined, $this->evaluator->evaluate(false, rawurlencode('{"1":0}'), true, 1));
    }

    public function testListFormatIndexedByWebsiteId(): void
    {
        $cookie = rawurlencode('[0,1]');
        $this->assertSame(ConsentState::Granted, $this->evaluator->evaluate(false, $cookie, true, 1));
        $this->assertSame(ConsentState::Declined, $this->evaluator->evaluate(false, $cookie, true, 0));
    }

    public function testBrowserVisitWithoutConsentCookieIsDeclined(): void
    {
        $this->assertSame(ConsentState::Declined, $this->evaluator->evaluate(false, null, true, 1));
    }

    public function testNoBrowserContextIsUnknown(): void
    {
        $this->assertSame(ConsentState::Unknown, $this->evaluator->evaluate(false, null, false, 1));
    }

    public function testConsentNotRequiredIsAlwaysGranted(): void
    {
        $this->assertSame(ConsentState::Granted, $this->evaluator->evaluate(false, null, true, 1, false));
        $this->assertSame(ConsentState::Granted, $this->evaluator->evaluate(true, null, false, 1, false));
    }

    public function testEventOnlyBannerWithoutCookieNameIsUnknown(): void
    {
        $this->assertSame(ConsentState::Unknown, $this->evaluator->evaluate(false, null, true, 1, true, ''));
        $this->assertSame(ConsentState::Unknown, $this->evaluator->evaluate(false, 'whatever', true, 1, true, '  '));
    }

    #[DataProvider('plainFlags')]
    public function testPlainFlagCookies(string $value, bool $granted): void
    {
        $this->assertSame($granted, $this->evaluator->isCookieGranted($value, 1));
    }

    public static function plainFlags(): array
    {
        return [
            ['1', true], ['true', true], ['TRUE', true], ['yes', true], ['granted', true], ['accepted', true], ['allow', true],
            ['0', false], ['false', false], ['no', false], ['denied', false], ['garbage', false], ['%%%', false],
        ];
    }

    public function testGrantedValueMatchesInsideTheDecodedCookie(): void
    {
        // Cookiebot-style cookie: {stamp:'...',necessary:true,preferences:false,statistics:true,marketing:false}
        $cookiebot = rawurlencode("{stamp:'abc',necessary:true,preferences:false,statistics:true,marketing:false}");
        $this->assertTrue($this->evaluator->isCookieGranted($cookiebot, 1, 'statistics:true'));
        $this->assertFalse($this->evaluator->isCookieGranted($cookiebot, 1, 'marketing:true'));
        $this->assertSame(
            ConsentState::Granted,
            $this->evaluator->evaluate(false, $cookiebot, true, 1, true, 'CookieConsent', 'statistics:true')
        );
        $this->assertSame(
            ConsentState::Declined,
            $this->evaluator->evaluate(false, $cookiebot, true, 1, true, 'CookieConsent', 'marketing:true')
        );
    }

    public function testGrantedValueOverridesTheAutomaticRules(): void
    {
        $this->assertFalse($this->evaluator->isCookieGranted(rawurlencode('{"1":1}'), 1, 'analytics'));
    }
}
