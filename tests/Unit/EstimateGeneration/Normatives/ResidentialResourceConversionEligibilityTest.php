<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialResourceConversionEligibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialResourceConversionEligibilityTest extends TestCase
{
    #[Test]
    public function allows_only_a_signed_residential_scenario_for_its_exact_norm(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $scenario = $catalog->issue('roof.insulation', 'residential');
        self::assertIsArray($scenario);
        $policy = new ResidentialResourceConversionEligibility($catalog);

        self::assertTrue($policy->allows([[
            'object_type' => 'house',
            'specialization_scenario' => $scenario,
        ]], '12-01-013-07'));
        self::assertFalse($policy->allows([[
            'object_type' => 'warehouse',
            'specialization_scenario' => $scenario,
        ]], '12-01-013-07'));
        self::assertFalse($policy->allows([[
            'object_type' => 'house',
            'specialization_scenario' => [...$scenario, 'normative_rate_code' => '12-01-999-99'],
        ]], '12-01-013-07'));
        self::assertFalse($policy->allows([[
            'object_type' => 'house',
            'specialization_scenario' => $scenario,
        ]], '15-01-019-05'));
    }
}
