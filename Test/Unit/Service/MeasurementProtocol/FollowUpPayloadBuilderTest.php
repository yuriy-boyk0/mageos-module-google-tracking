<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\FollowUpPayloadBuilder;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\PayloadBuilder;
use PHPUnit\Framework\TestCase;

class FollowUpPayloadBuilderTest extends TestCase
{
    private FollowUpPayloadBuilder $builder;
    private array $purchase = [
        'client_id' => '111.222',
        'user_id' => '17',
        'timestamp_micros' => 1700000000000000,
        'events' => [['name' => 'purchase_server', 'params' => ['session_id' => '999']]],
    ];

    protected function setUp(): void
    {
        $this->builder = new FollowUpPayloadBuilder(new PayloadBuilder());
    }

    public function testRefundReusesTheVisitorOfThePurchase(): void
    {
        $payload = $this->builder->build($this->purchase, 'refund_server', FollowUpPayloadBuilder::DEFAULT_REFUND_EVENT, [
            'transaction_id' => '000000042',
            'currency' => 'eur',
            'value' => 19.999,
            'items' => [['item_id' => 'MH01', 'price' => 10, 'quantity' => 2]],
        ], ['refund_type' => 'partial']);

        $this->assertSame('111.222', $payload['client_id']);
        $this->assertSame('17', $payload['user_id']);
        $this->assertArrayNotHasKey('timestamp_micros', $payload, 'a refund happens now, not at purchase time');

        $event = $payload['events'][0];
        $this->assertSame('refund_server', $event['name']);
        $this->assertSame('000000042', $event['params']['transaction_id']);
        $this->assertSame('EUR', $event['params']['currency']);
        $this->assertSame(20.0, $event['params']['value']);
        $this->assertSame('partial', $event['params']['refund_type']);
        $this->assertSame([['price' => 10.0, 'quantity' => 2, 'item_id' => 'MH01']], $event['params']['items']);
        $this->assertArrayNotHasKey('session_id', $event['params']);
    }

    public function testNoItemsKeyWhenThereAreNone(): void
    {
        $payload = $this->builder->build($this->purchase, 'cancel_server', FollowUpPayloadBuilder::DEFAULT_CANCEL_EVENT, [
            'transaction_id' => '1', 'currency' => 'USD', 'value' => 5,
        ]);
        $this->assertArrayNotHasKey('items', $payload['events'][0]['params']);
    }

    public function testAnonymousPurchaseStaysAnonymous(): void
    {
        $payload = $this->builder->build(['client_id' => '5.6', 'events' => []], 'refund_server', FollowUpPayloadBuilder::DEFAULT_REFUND_EVENT, [
            'transaction_id' => '1', 'currency' => 'USD', 'value' => 5,
        ]);
        $this->assertArrayNotHasKey('user_id', $payload);
        $this->assertSame('5.6', $payload['client_id']);
    }

    public function testInvalidConfiguredNameFallsBackToTheFollowUpDefault(): void
    {
        $amounts = ['transaction_id' => '1', 'currency' => 'USD', 'value' => 5];
        $this->assertSame('cancel_server', $this->builder->build($this->purchase, 'bad name', 'cancel_server', $amounts)['events'][0]['name']);
        $this->assertSame('refund_server', $this->builder->build($this->purchase, '', 'refund_server', $amounts)['events'][0]['name']);
        $this->assertSame('my_refund', $this->builder->build($this->purchase, 'my_refund', 'refund_server', $amounts)['events'][0]['name']);
    }
}
