<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\Client;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private const PAYLOAD = ['client_id' => '1.2', 'events' => [['name' => 'purchase_server', 'params' => []]]];

    public function testSuccessfulSendPostsJsonToTheCollectEndpoint(): void
    {
        $transport = new FakeTransport(new HttpResponse(204));
        $result = (new Client($transport))->send('G-ABC123XYZ9', 's3cr3t&x', self::PAYLOAD);

        $this->assertTrue($result->success);
        $this->assertFalse($result->retryable);
        $this->assertSame(204, $result->statusCode);

        $request = $transport->requests[0];
        $this->assertSame(
            'https://www.google-analytics.com/mp/collect?measurement_id=G-ABC123XYZ9&api_secret=s3cr3t%26x',
            $request['url']
        );
        $this->assertSame(self::PAYLOAD, json_decode($request['body'], true));
        $this->assertGreaterThan(0, $request['timeout']);
    }

    public function testDebugModeUsesTheValidationEndpoint(): void
    {
        $transport = new FakeTransport(new HttpResponse(200, '{"validationMessages":[]}'));
        (new Client($transport))->send('G-ABC123XYZ9', 'secret', self::PAYLOAD, true);

        $this->assertStringStartsWith('https://www.google-analytics.com/debug/mp/collect?', $transport->requests[0]['url']);
    }

    public function testDebugModeReportsValidationMessages(): void
    {
        $body = json_encode(['validationMessages' => [
            ['fieldPath' => 'events[0].name', 'description' => 'Event name is invalid', 'validationCode' => 'NAME_INVALID'],
        ]]);
        $result = (new Client(new FakeTransport(new HttpResponse(200, $body))))
            ->send('G-ABC123XYZ9', 'secret', self::PAYLOAD, true);

        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
        $this->assertSame(['events[0].name: Event name is invalid'], $result->validationMessages);
    }

    public function testDebugModeWithoutMessagesIsValid(): void
    {
        $result = (new Client(new FakeTransport(new HttpResponse(200, '{"validationMessages":[]}'))))
            ->send('G-ABC123XYZ9', 'secret', self::PAYLOAD, true);

        $this->assertTrue($result->success);
    }

    #[DataProvider('failureStatuses')]
    public function testHttpFailures(int $status, bool $retryable): void
    {
        $result = (new Client(new FakeTransport(new HttpResponse($status))))->send('G-ABC123XYZ9', 'secret', self::PAYLOAD);

        $this->assertFalse($result->success);
        $this->assertSame($retryable, $result->retryable);
        $this->assertSame($status, $result->statusCode);
    }

    public static function failureStatuses(): array
    {
        return [
            'bad request' => [400, false],
            'forbidden (bad secret)' => [403, false],
            'not found' => [404, false],
            'request timeout' => [408, true],
            'too many requests' => [429, true],
            'server error' => [500, true],
            'bad gateway' => [502, true],
            'unavailable' => [503, true],
        ];
    }

    public function testNetworkErrorsAreRetryableAndNeverThrow(): void
    {
        $result = (new Client(new FakeTransport(new \RuntimeException('Connection timed out'))))
            ->send('G-ABC123XYZ9', 'secret', self::PAYLOAD);

        $this->assertFalse($result->success);
        $this->assertTrue($result->retryable);
        $this->assertNull($result->statusCode);
        $this->assertStringContainsString('Connection timed out', $result->message);
    }

    public function testUnencodablePayloadIsNotRetried(): void
    {
        $transport = new FakeTransport();
        $result = (new Client($transport))->send('G-ABC123XYZ9', 'secret', ['bad' => NAN]);

        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
        $this->assertSame([], $transport->requests);
    }
}
