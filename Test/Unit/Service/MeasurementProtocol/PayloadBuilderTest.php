<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\PayloadBuilder;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
    private PayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PayloadBuilder();
    }

    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'transaction_id' => '000000042',
            'currency' => 'eur',
            'value' => 123.456,
            'tax' => 21.0,
            'shipping' => 5.0,
            'coupon' => 'SUMMER10',
            'created_at_micros' => 1700000000000000,
            'user_id' => '17',
            'items' => [
                [
                    'item_id' => 'MH01-XS-Black',
                    'item_name' => 'Chaz Kangeroo Hoodie',
                    'price' => 52.0,
                    'quantity' => 2.0,
                    'discount' => 5.2,
                    'item_variant' => 'Black, XS',
                    'item_category' => 'Men',
                    'item_category2' => 'Tops',
                ],
            ],
        ];
    }

    public function testBuildsTheMeasurementProtocolStructure(): void
    {
        $payload = $this->builder->build($this->snapshot(), 'purchase_server', '111.222', '1700000123', true);

        $this->assertSame('111.222', $payload['client_id']);
        $this->assertSame('17', $payload['user_id']);
        $this->assertSame(1700000000000000, $payload['timestamp_micros']);
        $this->assertCount(1, $payload['events']);

        $event = $payload['events'][0];
        $this->assertSame('purchase_server', $event['name']);
        $this->assertSame('000000042', $event['params']['transaction_id']);
        $this->assertSame('EUR', $event['params']['currency']);
        $this->assertSame(123.46, $event['params']['value']);
        $this->assertSame(21.0, $event['params']['tax']);
        $this->assertSame(5.0, $event['params']['shipping']);
        $this->assertSame('SUMMER10', $event['params']['coupon']);
        $this->assertSame('1700000123', $event['params']['session_id']);
        $this->assertSame(1, $event['params']['engagement_time_msec']);
    }

    public function testItemsAreMappedAndEmptyFieldsDropped(): void
    {
        $payload = $this->builder->build($this->snapshot(), 'purchase_server', '1.2');
        $item = $payload['events'][0]['params']['items'][0];

        $this->assertSame([
            'price' => 52.0,
            'quantity' => 2,
            'discount' => 5.2,
            'item_id' => 'MH01-XS-Black',
            'item_name' => 'Chaz Kangeroo Hoodie',
            'item_variant' => 'Black, XS',
            'item_category' => 'Men',
            'item_category2' => 'Tops',
        ], $item);
    }

    public function testUserIdOnlyWhenAllowed(): void
    {
        $this->assertArrayNotHasKey('user_id', $this->builder->build($this->snapshot(), 'purchase_server', '1.2', null, false));
        $this->assertArrayNotHasKey(
            'user_id',
            $this->builder->build($this->snapshot(['user_id' => null]), 'purchase_server', '1.2', null, true)
        );
    }

    public function testSessionIdAndCouponAreOptional(): void
    {
        $payload = $this->builder->build($this->snapshot(['coupon' => '']), 'purchase_server', '1.2', null);
        $params = $payload['events'][0]['params'];

        $this->assertArrayNotHasKey('session_id', $params);
        $this->assertArrayNotHasKey('coupon', $params);
    }

    public function testTimestampOmittedWhenUnknown(): void
    {
        $payload = $this->builder->build($this->snapshot(['created_at_micros' => null]), 'purchase_server', '1.2');
        $this->assertArrayNotHasKey('timestamp_micros', $payload);
    }

    public function testQuantityIsAtLeastOne(): void
    {
        $snapshot = $this->snapshot(['items' => [['item_id' => 'A', 'price' => 1, 'quantity' => 0]]]);
        $payload = $this->builder->build($snapshot, 'purchase_server', '1.2');
        $this->assertSame(1, $payload['events'][0]['params']['items'][0]['quantity']);
    }

    public function testItemsAreCappedAtTwoHundred(): void
    {
        $items = array_map(static fn(int $i) => ['item_id' => 'SKU' . $i, 'price' => 1, 'quantity' => 1], range(1, 250));
        $payload = $this->builder->build($this->snapshot(['items' => $items]), 'purchase_server', '1.2');
        $this->assertCount(200, $payload['events'][0]['params']['items']);
    }

    /**
     * @dataProvider eventNames
     */
    public function testEventNameIsSanitized(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->builder->sanitizeEventName($input));
    }

    public static function eventNames(): array
    {
        return [
            'valid' => ['purchase_server', 'purchase_server'],
            'trimmed' => ['  my_purchase  ', 'my_purchase'],
            'starts with digit' => ['1purchase', 'purchase_server'],
            'has space' => ['purchase server', 'purchase_server'],
            'has dash' => ['purchase-server', 'purchase_server'],
            'empty' => ['', 'purchase_server'],
            'too long' => [str_repeat('a', 41), 'purchase_server'],
            'max length' => [str_repeat('a', 40), str_repeat('a', 40)],
        ];
    }

    public function testInvalidConfiguredNameFallsBackInThePayload(): void
    {
        $payload = $this->builder->build($this->snapshot(), 'bad name!', '1.2');
        $this->assertSame('purchase_server', $payload['events'][0]['name']);
    }

    public function testConsentModeSignalsAreAddedOnlyWhenGiven(): void
    {
        $this->assertArrayNotHasKey('consent', $this->builder->build($this->snapshot(), 'purchase_server', '1.2'));
        $consent = ['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED'];
        $this->assertSame($consent, $this->builder->build($this->snapshot(), 'purchase_server', '1.2', null, false, $consent)['consent']);
    }

    public function testBrandAffiliationAndListFieldsArePassedThrough(): void
    {
        $snapshot = $this->snapshot(['items' => [[
            'item_id' => 'A', 'price' => 1, 'quantity' => 1,
            'item_brand' => 'Luma', 'affiliation' => 'Luma Store', 'item_list_id' => 'category_12', 'item_list_name' => 'Tops',
        ]]]);
        $item = $this->builder->build($snapshot, 'purchase_server', '1.2')['events'][0]['params']['items'][0];
        $this->assertSame('Luma', $item['item_brand']);
        $this->assertSame('Luma Store', $item['affiliation']);
        $this->assertSame('category_12', $item['item_list_id']);
    }

    public function testCustomItemParametersArePassedThroughAsText(): void
    {
        $snapshot = $this->snapshot(['items' => [[
            'item_id' => 'A', 'price' => 1, 'quantity' => 1, 'item_color' => 'Black', 'item_size' => 42,
            'Bad-Key' => 'x', 'empty_one' => '', 'nested' => ['x'],
        ]]]);
        $item = $this->builder->build($snapshot, 'purchase_server', '1.2')['events'][0]['params']['items'][0];
        $this->assertSame('Black', $item['item_color']);
        $this->assertSame('42', $item['item_size']);
        $this->assertArrayNotHasKey('Bad-Key', $item);
        $this->assertArrayNotHasKey('empty_one', $item);
        $this->assertArrayNotHasKey('nested', $item);
        $this->assertSame(1.0, $item['price']);
    }
}
