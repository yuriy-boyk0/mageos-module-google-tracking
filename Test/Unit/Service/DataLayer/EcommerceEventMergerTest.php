<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\DataLayer;

use MageOS\GoogleTagManager\Service\DataLayer\EcommerceEventMerger;
use PHPUnit\Framework\TestCase;

class EcommerceEventMergerTest extends TestCase
{
    private EcommerceEventMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new EcommerceEventMerger();
    }

    private function addToCart(array $items, string $currency = 'USD'): array
    {
        $value = array_sum(array_map(static fn($i) => $i['price'] * $i['quantity'], $items));

        return ['event' => 'add_to_cart', 'ecommerce' => ['currency' => $currency, 'value' => $value, 'items' => $items]];
    }

    public function testNothingPendingKeepsTheNewEvent(): void
    {
        $new = $this->addToCart([['item_id' => 'A', 'price' => 10.0, 'quantity' => 1.0]]);
        $this->assertSame($new, $this->merger->merge(null, $new));
    }

    public function testItemsAreAppendedAndValueRecomputed(): void
    {
        $pending = $this->addToCart([['item_id' => '24-WG085', 'price' => 14.0, 'quantity' => 1.0]]);
        $new = $this->addToCart([['item_id' => '24-WG086', 'price' => 17.0, 'quantity' => 2.0]]);

        $merged = $this->merger->merge($pending, $new);

        $this->assertSame(['24-WG085', '24-WG086'], array_column($merged['ecommerce']['items'], 'item_id'));
        $this->assertSame(48.0, $merged['ecommerce']['value'], '14 x 1 + 17 x 2, not the value of the first event');
        $this->assertSame('add_to_cart', $merged['event']);
    }

    public function testThreeAddsInARow(): void
    {
        $merged = null;
        foreach ([['A', 1.5], ['B', 2.25], ['C', 3.0]] as [$id, $price]) {
            $merged = $this->merger->merge($merged, $this->addToCart([['item_id' => $id, 'price' => $price, 'quantity' => 2.0]]));
        }
        $this->assertCount(3, $merged['ecommerce']['items']);
        $this->assertSame(13.5, $merged['ecommerce']['value']);
    }

    public function testDifferentCurrencyOrEventKeepsTheNewest(): void
    {
        $pending = $this->addToCart([['item_id' => 'A', 'price' => 10.0, 'quantity' => 1.0]], 'USD');
        $eur = $this->addToCart([['item_id' => 'B', 'price' => 5.0, 'quantity' => 1.0]], 'EUR');
        $this->assertSame($eur, $this->merger->merge($pending, $eur));

        $remove = ['event' => 'remove_from_cart'] + $this->addToCart([['item_id' => 'B', 'price' => 5.0, 'quantity' => 1.0]]);
        $remove['event'] = 'remove_from_cart';
        $this->assertSame($remove, $this->merger->merge($pending, $remove));
    }

    public function testMalformedPendingDataIsReplaced(): void
    {
        $new = $this->addToCart([['item_id' => 'A', 'price' => 10.0, 'quantity' => 1.0]]);
        $this->assertSame($new, $this->merger->merge(['event' => 'add_to_cart', 'ecommerce' => null], $new));
    }
}
