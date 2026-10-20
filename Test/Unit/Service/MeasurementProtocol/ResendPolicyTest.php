<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ResendPolicy;
use PHPUnit\Framework\TestCase;

class ResendPolicyTest extends TestCase
{
    private ResendPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ResendPolicy();
    }

    public function testDecisions(): void
    {
        $this->assertSame(ResendPolicy::CREATE, $this->policy->decide(null)[0]);
        $this->assertSame(ResendPolicy::RESET, $this->policy->decide(['status' => 'failed'])[0]);
        $this->assertSame(ResendPolicy::RESET, $this->policy->decide(['status' => 'expired'])[0]);
        $this->assertSame([ResendPolicy::IGNORE, 'already sent'], $this->policy->decide(['status' => 'sent']));
        $this->assertSame([ResendPolicy::IGNORE, 'already queued'], $this->policy->decide(['status' => 'pending']));
        $this->assertSame([ResendPolicy::IGNORE, 'already queued'], $this->policy->decide(['status' => 'processing']));
    }

    public function testSkippedRowsAreNotOverriddenAndTheReasonIsShown(): void
    {
        [$action, $reason] = $this->policy->decide(['status' => 'skipped', 'last_error' => 'skipped: tracking consent not given']);
        $this->assertSame(ResendPolicy::IGNORE, $action);
        $this->assertStringContainsString('consent', $reason);
    }

    public function testPreparePayloadDropsTheOriginalTimestamp(): void
    {
        $stored = '{"client_id":"1.2","timestamp_micros":1700000000000000,"events":[{"name":"purchase_server","params":{"value":5}}]}';
        $prepared = json_decode((string) $this->policy->preparePayload($stored), true);

        $this->assertArrayNotHasKey('timestamp_micros', $prepared);
        $this->assertSame('1.2', $prepared['client_id']);
        $this->assertSame(5, $prepared['events'][0]['params']['value']);
    }

    public function testPreparePayloadRejectsUnusablePayloads(): void
    {
        $this->assertNull($this->policy->preparePayload(''));
        $this->assertNull($this->policy->preparePayload('{broken'));
        $this->assertNull($this->policy->preparePayload('{"client_id":"1.2","events":[]}'));
    }
}
