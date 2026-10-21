<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\ExclusionPolicy;
use PHPUnit\Framework\TestCase;

class ExclusionPolicyTest extends TestCase
{
    private ExclusionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ExclusionPolicy();
    }

    private function order(array $overrides = []): array
    {
        return $overrides + ['grand_total' => 50.0, 'status' => 'pending', 'payment_method' => 'checkmo', 'is_admin_order' => false];
    }

    private function rules(array $overrides = []): array
    {
        return $overrides + [
            'exclude_zero_value' => true,
            'excluded_statuses' => [],
            'excluded_payment_methods' => [],
            'include_admin_orders' => false,
        ];
    }

    public function testNormalOrderIsTracked(): void
    {
        $this->assertNull($this->policy->skipReason($this->order(), $this->rules()));
    }

    public function testZeroValueOrderIsSkippedWhenConfigured(): void
    {
        $this->assertSame(
            ExclusionPolicy::REASON_ZERO_VALUE,
            $this->policy->skipReason($this->order(['grand_total' => 0.0]), $this->rules())
        );
        $this->assertNull(
            $this->policy->skipReason($this->order(['grand_total' => 0.0]), $this->rules(['exclude_zero_value' => false]))
        );
    }

    public function testExcludedStatus(): void
    {
        $rules = $this->rules(['excluded_statuses' => ['fraud', 'holded']]);
        $this->assertSame(ExclusionPolicy::REASON_STATUS, $this->policy->skipReason($this->order(['status' => 'fraud']), $rules));
        $this->assertNull($this->policy->skipReason($this->order(['status' => 'pending']), $rules));
    }

    public function testExcludedPaymentMethod(): void
    {
        $rules = $this->rules(['excluded_payment_methods' => ['banktransfer']]);
        $this->assertSame(
            ExclusionPolicy::REASON_PAYMENT_METHOD,
            $this->policy->skipReason($this->order(['payment_method' => 'banktransfer']), $rules)
        );
    }

    public function testAdminOrdersAreSkippedUnlessIncluded(): void
    {
        $order = $this->order(['is_admin_order' => true]);
        $this->assertSame(ExclusionPolicy::REASON_ADMIN_ORDER, $this->policy->skipReason($order, $this->rules()));
        $this->assertNull($this->policy->skipReason($order, $this->rules(['include_admin_orders' => true])));
    }

    public function testStatusMatchingIsExactNotPartial(): void
    {
        $rules = $this->rules(['excluded_statuses' => ['pending_payment']]);
        $this->assertNull($this->policy->skipReason($this->order(['status' => 'pending']), $rules));
    }

    public function testExcludedCustomerGroup(): void
    {
        $rules = $this->rules(['excluded_customer_groups' => ['2', 4]]);
        $this->assertSame(ExclusionPolicy::REASON_CUSTOMER_GROUP, $this->policy->skipReason($this->order(['customer_group_id' => 2]), $rules));
        $this->assertSame(ExclusionPolicy::REASON_CUSTOMER_GROUP, $this->policy->skipReason($this->order(['customer_group_id' => 4]), $rules));
        $this->assertNull($this->policy->skipReason($this->order(['customer_group_id' => 1]), $rules));
        $this->assertNull($this->policy->skipReason($this->order(['customer_group_id' => 0]), $this->rules()), 'no rule');
    }

    public function testMaximumTransactionValue(): void
    {
        $rules = $this->rules(['max_transaction_value' => 1000.0]);
        $this->assertNull($this->policy->skipReason($this->order(['grand_total' => 1000.0]), $rules), 'equal is allowed');
        $this->assertSame(ExclusionPolicy::REASON_MAX_VALUE, $this->policy->skipReason($this->order(['grand_total' => 1000.01]), $rules));
        $this->assertNull($this->policy->skipReason($this->order(['grand_total' => 99999.0]), $this->rules(['max_transaction_value' => 0.0])), '0 = off');
    }
}
