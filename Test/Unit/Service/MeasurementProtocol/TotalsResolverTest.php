<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;
use PHPUnit\Framework\TestCase;

class TotalsResolverTest extends TestCase
{
    private TotalsResolver $resolver;
    /** shipping is the amount WITHOUT tax, like the client-side purchase event */
    private array $amounts = ['grand_total' => 123.0, 'tax' => 23.0, 'shipping' => 5.0];

    protected function setUp(): void
    {
        $this->resolver = new TotalsResolver();
    }

    public function testIncludingTaxKeepsTheGrandTotalAndSendsShippingWithoutTax(): void
    {
        $this->assertSame(
            ['value' => 123.0, 'tax' => 23.0, 'shipping' => 5.0],
            $this->resolver->resolveOrder($this->amounts, TotalsResolver::INCLUDING_TAX)
        );
    }

    public function testExcludingTaxOnlySubtractsTaxFromTheValue(): void
    {
        $this->assertSame(
            ['value' => 100.0, 'tax' => 23.0, 'shipping' => 5.0],
            $this->resolver->resolveOrder($this->amounts, TotalsResolver::EXCLUDING_TAX)
        );
    }

    public function testShippingAndTaxAreTheSameWhateverTheBasis(): void
    {
        $including = $this->resolver->resolveOrder($this->amounts, TotalsResolver::INCLUDING_TAX);
        $excluding = $this->resolver->resolveOrder($this->amounts, TotalsResolver::EXCLUDING_TAX);

        $this->assertSame($including['shipping'], $excluding['shipping']);
        $this->assertSame($including['tax'], $excluding['tax']);
    }

    public function testExcludingTaxNeverGoesNegative(): void
    {
        $result = $this->resolver->resolveOrder(
            ['grand_total' => 0.0, 'tax' => 4.0, 'shipping' => 0.0],
            TotalsResolver::EXCLUDING_TAX
        );
        $this->assertSame(0.0, $result['value']);
    }

    public function testUnknownBasisBehavesLikeIncludingTax(): void
    {
        $this->assertSame(123.0, $this->resolver->resolveOrder($this->amounts, 'something')['value']);
    }

    public function testNumericStringsFromTheDatabaseAreAccepted(): void
    {
        // Orders and credit memos loaded from the DB return decimals as strings ("123.0000").
        $result = $this->resolver->resolveOrder(
            ['grand_total' => '123.0000', 'tax' => '23.0000', 'shipping' => '5.0000'],
            TotalsResolver::INCLUDING_TAX
        );
        $this->assertSame(['value' => 123.0, 'tax' => 23.0, 'shipping' => 5.0], $result);
    }

    public function testLinesPlusTaxEqualTheValueWhenIncludingTax(): void
    {
        // Italian example: item 24.13 + shipping 3.77 + tax 6.14 = 34.04, as in the client-side purchase event.
        $result = $this->resolver->resolveOrder(
            ['grand_total' => 34.04, 'tax' => 6.14, 'shipping' => 3.77],
            TotalsResolver::INCLUDING_TAX
        );
        $this->assertEqualsWithDelta(34.04, 24.13 + $result['shipping'] + $result['tax'], 0.001);
        $this->assertSame(34.04, $result['value']);
    }
}
