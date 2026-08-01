<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use PHPUnit\Framework\TestCase;

final class ProcurementAwardPolicyDefinitionTest extends TestCase
{
    public function test_v1_policy_pins_narrow_comparability_and_capacity_contract(): void
    {
        $policy = ProcurementAwardPolicyDefinition::v1();

        self::assertSame('00000000-0000-4000-8000-000000000016', $policy->policyId);
        self::assertSame(1, $policy->version);
        self::assertSame('procurement-award-policy.v1', $policy->schemaVersion);
        self::assertSame('exact_only', $policy->canonicalPayload()['currency_mode']);
        self::assertSame('supplier_request', $policy->canonicalPayload()['competition_mode']);
        self::assertSame(100, $policy->canonicalPayload()['candidate_limit']);
        self::assertSame(250, $policy->canonicalPayload()['capture_slo_milliseconds']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $policy->canonicalHash());
    }
}
