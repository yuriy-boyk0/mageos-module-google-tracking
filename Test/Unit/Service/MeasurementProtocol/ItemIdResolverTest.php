<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ItemIdResolver;
use PHPUnit\Framework\TestCase;

class ItemIdResolverTest extends TestCase
{
    private ItemIdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ItemIdResolver();
    }

    public function testConfigurableInParentModeUsesTheCatalogProductSku(): void
    {
        $this->assertSame(
            'MH01',
            $this->resolver->resolve(ItemIdResolver::PARENT, 'configurable', 'MH01-XS-Black', 'MH01-XS-Black', 'MH01')
        );
    }

    public function testConfigurableInChildModeUsesTheChildRowSku(): void
    {
        // The parent order row carries the variant SKU plus any custom-option suffix; the child row is the clean variant SKU.
        $this->assertSame(
            'MH01-XS-Black',
            $this->resolver->resolve(ItemIdResolver::CHILD, 'configurable', 'MH01-XS-Black-gift', 'MH01-XS-Black', 'MH01')
        );
    }

    public function testFallsBackToTheOrderRowSkuWhenTheOtherSourceIsMissing(): void
    {
        $this->assertSame('ROW', $this->resolver->resolve(ItemIdResolver::PARENT, 'configurable', 'ROW', 'CHILD', null));
        $this->assertSame('ROW', $this->resolver->resolve(ItemIdResolver::PARENT, 'configurable', 'ROW', 'CHILD', ''));
        $this->assertSame('ROW', $this->resolver->resolve(ItemIdResolver::CHILD, 'configurable', 'ROW', null, 'PARENT'));
    }

    public function testOtherProductTypesAlwaysUseTheirOwnSku(): void
    {
        foreach (['simple', 'bundle', 'virtual', 'downloadable', 'grouped'] as $type) {
            foreach ([ItemIdResolver::PARENT, ItemIdResolver::CHILD] as $mode) {
                $this->assertSame('OWN', $this->resolver->resolve($mode, $type, 'OWN', 'CHILD', 'PARENT'), "$type/$mode");
            }
        }
    }
}
