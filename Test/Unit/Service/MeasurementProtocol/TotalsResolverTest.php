<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;
use PHPUnit\Framework\TestCase;

class TotalsResolverTest extends TestCase
{
    private TotalsResolver $resolver;
    private array $amounts = ['grand_total' => 123.0, 'tax' => 23.0, 'shipping' => 5.0, 'shipping_incl_tax' => 6.15];

    protected function setUp(): void
    {
        $this->resolver = new TotalsResolver();
    }

    public function testIncludingTaxKeepsTheGrandTotalAndShippingWithTax(): void
    {
        $this->assertSame(
            ['value' => 123.0, 'tax' => 23.0, 'shipping' => 6.15],
            $this->resolver->resolveOrder($this->amounts, TotalsResolver::INCLUDING_TAX)
        );
    }

    public function testExcludingTaxSubtractsTaxAndUsesShippingWithoutTax(): void
    {
        $this->assertSame(
            ['value' => 100.0, 'tax' => 23.0, 'shipping' => 5.0],
            $this->resolver->resolveOrder($this->amounts, TotalsResolver::EXCLUDING_TAX)
        );
    }

    public function testExcludingTaxNeverGoesNegative(): void
    {
        $result = $this->resolver->resolveOrder(
            ['grand_total' => 0.0, 'tax' => 4.0, 'shipping' => 0.0, 'shipping_incl_tax' => 0.0],
            TotalsResolver::EXCLUDING_TAX
        );
        $this->assertSame(0.0, $result['value']);
    }

    public function testMissingShippingInclTaxFallsBackToShipping(): void
    {
        $amounts = ['grand_total' => 50.0, 'tax' => 0.0, 'shipping' => 5.0, 'shipping_incl_tax' => null];
        $this->assertSame(5.0, $this->resolver->resolveOrder($amounts, TotalsResolver::INCLUDING_TAX)['shipping']);
    }

    public function testUnknownBasisBehavesLikeIncludingTax(): void
    {
        $this->assertSame(123.0, $this->resolver->resolveOrder($this->amounts, 'something')['value']);
    }

    public function testItemPrice(): void
    {
        $this->assertSame(12.3, $this->resolver->resolveItemPrice(10.0, 12.3, TotalsResolver::INCLUDING_TAX));
        $this->assertSame(10.0, $this->resolver->resolveItemPrice(10.0, 12.3, TotalsResolver::EXCLUDING_TAX));
    }

    public function testItemPriceFallsBackWhenTaxInclusivePriceIsMissing(): void
    {
        $this->assertSame(10.0, $this->resolver->resolveItemPrice(10.0, null, TotalsResolver::INCLUDING_TAX));
        $this->assertSame(10.0, $this->resolver->resolveItemPrice(10.0, 0.0, TotalsResolver::INCLUDING_TAX));
    }
}
