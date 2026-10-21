<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\RefundQuantity;
use PHPUnit\Framework\TestCase;

class RefundQuantityTest extends TestCase
{
    private RefundQuantity $quantity;

    protected function setUp(): void
    {
        $this->quantity = new RefundQuantity();
    }

    public function testOneBundleWithFourOptionsIsOneUnit(): void
    {
        // run 13: the memo row of the bundle said 4, the purchase had 1 bundle with 4 single options
        $children = array_fill(0, 4, ['memo_qty' => 1.0, 'ordered_qty' => 1.0]);
        $this->assertSame(1.0, $this->quantity->forParent(4.0, 1.0, $children));
    }

    public function testChildQuantitiesPerBundleAreRespected(): void
    {
        // 3 bundles ordered, each with 2 of option A and 1 of option B; 2 bundles refunded
        $children = [['memo_qty' => 4.0, 'ordered_qty' => 6.0], ['memo_qty' => 2.0, 'ordered_qty' => 3.0]];
        $this->assertSame(2.0, $this->quantity->forParent(6.0, 3.0, $children));
    }

    public function testNeverMoreThanOrdered(): void
    {
        $this->assertSame(1.0, $this->quantity->forParent(9.0, 1.0, [['memo_qty' => 5.0, 'ordered_qty' => 1.0]]));
    }

    public function testRowsWithoutChildrenKeepTheirOwnQuantity(): void
    {
        $this->assertSame(2.0, $this->quantity->forParent(2.0, 3.0, []));
        $this->assertSame(2.0, $this->quantity->forParent(2.0, 0.0, [['memo_qty' => 1.0, 'ordered_qty' => 1.0]]));
        $this->assertSame(2.0, $this->quantity->forParent(2.0, 1.0, [['memo_qty' => 0.0, 'ordered_qty' => 1.0]]));
    }
}
