<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\Client;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HttpResponse;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\LogInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueProcessor;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\RetryPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\SendSettings;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\SendSettingsProviderInterface;
use PHPUnit\Framework\TestCase;

class QueueProcessorTest extends TestCase
{
    private const NOW = 1700000000;
    private const PAYLOAD = '{"client_id":"1.2","events":[{"name":"purchase_server","params":{}}]}';

    private InMemoryQueueStorage $storage;
    /** @var array<int, SendSettings|null> */
    private array $settings;
    /** @var string[] */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->storage = new InMemoryQueueStorage();
        $this->settings = [1 => new SendSettings('G-ABC123XYZ9', 'secret', 3, false)];
        $this->logged = [];
    }

    private function processor(FakeTransport $transport): QueueProcessor
    {
        $provider = new class($this->settings) implements SendSettingsProviderInterface {
            public function __construct(private array $settings) {}
            public function getSettings(int $storeId): ?SendSettings
            {
                return $this->settings[$storeId] ?? null;
            }
        };
        $logger = new class($this->logged) implements LogInterface {
            public function __construct(private array &$logged) {}
            public function log(string $message, bool $success): void
            {
                $this->logged[] = ($success ? 'OK ' : 'ERR ') . $message;
            }
        };

        return new QueueProcessor($this->storage, new Client($transport), $provider, new RetryPolicy(60, 3600), $logger);
    }

    private function queueOrder(int $orderId = 1, array $extra = []): int
    {
        $this->storage->enqueue([
            'order_id' => $orderId, 'event_type' => 'purchase', 'store_id' => 1, 'payload' => self::PAYLOAD,
        ] + $extra, self::NOW);

        return array_key_last($this->storage->rows);
    }

    public function testSuccessfulSendMarksTheRowSent(): void
    {
        $id = $this->queueOrder();
        $transport = new FakeTransport(new HttpResponse(204));

        $stats = $this->processor($transport)->process(self::NOW);

        $this->assertSame(['sent' => 1, 'retried' => 0, 'failed' => 0, 'skipped' => 0], $stats);
        $this->assertSame(QueueStorageInterface::STATUS_SENT, $this->storage->rows[$id]['status']);
        $this->assertSame(1, $this->storage->rows[$id]['attempts']);
        $this->assertSame(self::NOW, $this->storage->rows[$id]['sent_at']);
        $this->assertCount(1, $transport->requests);
        $this->assertSame(json_decode(self::PAYLOAD, true), json_decode($transport->requests[0]['body'], true));
    }

    public function testTransientFailureIsRescheduledWithBackoff(): void
    {
        $id = $this->queueOrder();
        $stats = $this->processor(new FakeTransport(new HttpResponse(503)))->process(self::NOW);

        $this->assertSame(1, $stats['retried']);
        $row = $this->storage->rows[$id];
        $this->assertSame(QueueStorageInterface::STATUS_PENDING, $row['status']);
        $this->assertSame(1, $row['attempts']);
        $this->assertSame(self::NOW + 60, $row['next_attempt_at']);
        $this->assertSame('HTTP 503', $row['last_error']);
    }

    public function testBackoffGrowsAcrossAttemptsAndIsNotRetriedEarly(): void
    {
        $id = $this->queueOrder();
        $processor = $this->processor(new FakeTransport(new HttpResponse(503)));

        $processor->process(self::NOW);
        // Not due yet: nothing happens.
        $this->assertSame(0, array_sum($processor->process(self::NOW + 59)));

        $processor->process(self::NOW + 60);
        $this->assertSame(2, $this->storage->rows[$id]['attempts']);
        $this->assertSame(self::NOW + 60 + 120, $this->storage->rows[$id]['next_attempt_at']);
    }

    public function testRetriesStopAtMaxAttemptsAndTheRowFails(): void
    {
        $id = $this->queueOrder();
        $processor = $this->processor(new FakeTransport(new HttpResponse(500)));

        $processor->process(self::NOW);
        $processor->process(self::NOW + 1000);
        $stats = $processor->process(self::NOW + 2000);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(QueueStorageInterface::STATUS_FAILED, $this->storage->rows[$id]['status']);
        $this->assertSame(3, $this->storage->rows[$id]['attempts']);
        // A failed row is never picked up again.
        $this->assertSame(0, array_sum($processor->process(self::NOW + 999999)));
    }

    public function testPermanentErrorsFailImmediately(): void
    {
        $id = $this->queueOrder();
        $stats = $this->processor(new FakeTransport(new HttpResponse(403)))->process(self::NOW);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(QueueStorageInterface::STATUS_FAILED, $this->storage->rows[$id]['status']);
        $this->assertSame(1, $this->storage->rows[$id]['attempts']);
    }

    public function testNetworkErrorIsRetried(): void
    {
        $id = $this->queueOrder();
        $stats = $this->processor(new FakeTransport(new \RuntimeException('timeout')))->process(self::NOW);

        $this->assertSame(1, $stats['retried']);
        $this->assertStringContainsString('timeout', $this->storage->rows[$id]['last_error']);
    }

    public function testStoreWithoutSettingsFailsTheRow(): void
    {
        $this->settings[1] = null;
        $id = $this->queueOrder();
        $transport = new FakeTransport();

        $stats = $this->processor($transport)->process(self::NOW);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame([], $transport->requests);
        $this->assertStringContainsString('not configured', $this->storage->rows[$id]['last_error']);
    }

    public function testCorruptPayloadFailsWithoutSending(): void
    {
        $this->storage->enqueue(['order_id' => 1, 'event_type' => 'purchase', 'store_id' => 1, 'payload' => '{broken'], self::NOW);
        $transport = new FakeTransport();

        $stats = $this->processor($transport)->process(self::NOW);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame([], $transport->requests);
    }

    public function testSkippedAndSentRowsAreIgnored(): void
    {
        $this->queueOrder(1, ['status' => QueueStorageInterface::STATUS_SKIPPED]);
        $this->queueOrder(2, ['status' => QueueStorageInterface::STATUS_SENT]);
        $transport = new FakeTransport();

        $this->assertSame(0, array_sum($this->processor($transport)->process(self::NOW)));
        $this->assertSame([], $transport->requests);
    }

    public function testAnOrderCannotBeQueuedTwice(): void
    {
        $this->queueOrder(5);
        $this->assertFalse($this->storage->enqueue(['order_id' => 5, 'event_type' => 'purchase'], self::NOW));
        $this->assertCount(1, $this->storage->rows);
    }

    public function testAbandonedProcessingRowIsRecovered(): void
    {
        $id = $this->queueOrder();
        $this->storage->claim($id, self::NOW - 1000, self::NOW - 1600); // worker crashed mid-run
        $transport = new FakeTransport(new HttpResponse(204));

        $stats = $this->processor($transport)->process(self::NOW);

        $this->assertSame(1, $stats['sent']);
    }

    public function testRecentProcessingRowIsLeftToItsWorker(): void
    {
        $id = $this->queueOrder();
        $this->storage->claim($id, self::NOW - 10, self::NOW - 610);
        $transport = new FakeTransport();

        $this->assertSame(0, array_sum($this->processor($transport)->process(self::NOW)));
        $this->assertSame([], $transport->requests);
    }

    public function testLimitCapsTheBatch(): void
    {
        foreach (range(1, 5) as $orderId) {
            $this->queueOrder($orderId);
        }
        $transport = new FakeTransport(new HttpResponse(204));

        $stats = $this->processor($transport)->process(self::NOW, 2);

        $this->assertSame(2, $stats['sent']);
        $this->assertCount(2, $transport->requests);
    }

    public function testFailuresAreLoggedAndRoutineSuccessIsNot(): void
    {
        $this->queueOrder(1, ['increment_id' => '000000001']);
        $this->processor(new FakeTransport(new HttpResponse(204)))->process(self::NOW);
        $this->assertSame([], $this->logged);

        $this->queueOrder(2, ['increment_id' => '000000002']);
        $this->processor(new FakeTransport(new HttpResponse(403)))->process(self::NOW);
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('000000002', $this->logged[0]);
    }
}
