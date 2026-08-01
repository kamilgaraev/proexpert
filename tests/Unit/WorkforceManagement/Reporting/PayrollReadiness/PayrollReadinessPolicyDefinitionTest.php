<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums\PayrollReadinessReason;
use PHPUnit\Framework\TestCase;

final class PayrollReadinessPolicyDefinitionTest extends TestCase
{
    public function test_v1_policy_has_stable_closed_contract_and_hash(): void
    {
        $policy = PayrollReadinessPolicyDefinition::v1();

        self::assertSame('payroll-readiness-policy.v1', $policy->version);
        self::assertSame('UTC', $policy->timezone);
        self::assertSame('none', $policy->calendarMode);
        self::assertSame([
            'period_validated',
            'source_present',
            'source_actual',
            'validation_clear',
            'accounting_clear',
        ], $policy->checkOrder);
        self::assertSame([
            'employee_id',
            'employee_name',
            'hours',
            'amount',
            'message',
            'personnel_number',
            'salary_amount',
        ], $policy->redactedFields);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $policy->hash());
        self::assertSame($policy->hash(), PayrollReadinessPolicyDefinition::v1()->hash());
    }

    public function test_v1_policy_accepts_only_declared_owner_outcomes(): void
    {
        $policy = PayrollReadinessPolicyDefinition::v1();

        foreach (PayrollReadinessReason::cases() as $reason) {
            self::assertTrue($policy->allows($reason), $reason->value);
        }

        self::assertFalse($policy->allowsCode('unknown_runtime_reason'));
    }
}
