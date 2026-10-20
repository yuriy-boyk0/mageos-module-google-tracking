<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentEvaluator;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentState;
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
        $cookie = rawurlencode('{"1":1}');
        $this->assertSame(ConsentState::Unknown, $this->evaluator->evaluate(true, $cookie, true, 1));
    }

    public function testAcceptedForCurrentWebsiteIsGranted(): void
    {
        $cookie = rawurlencode('{"1":1,"2":0}');
        $this->assertSame(ConsentState::Granted, $this->evaluator->evaluate(false, $cookie, true, 1));
    }

    public function testAcceptedOnlyForAnotherWebsiteIsDeclined(): void
    {
        $cookie = rawurlencode('{"1":1}');
        $this->assertSame(ConsentState::Declined, $this->evaluator->evaluate(false, $cookie, true, 2));
    }

    public function testListFormatIndexedByWebsiteId(): void
    {
        // head.phtml reads the cookie as an array indexed by website id
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

    public function testUnparseableCookieIsTreatedAsMissing(): void
    {
        $this->assertSame(ConsentState::Declined, $this->evaluator->evaluate(false, '%%%garbage', true, 1));
        $this->assertSame(ConsentState::Unknown, $this->evaluator->evaluate(false, '%%%garbage', false, 1));
    }
}
