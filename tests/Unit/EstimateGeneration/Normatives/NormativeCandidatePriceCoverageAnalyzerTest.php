<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidatePriceCoverageAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeCandidatePriceCoverageAnalyzerTest extends TestCase
{
    #[Test]
    public function it_separates_missing_codes_absent_prices_and_incompatible_units(): void
    {
        $result = (new NormativeCandidatePriceCoverageAnalyzer)->analyze(
            [
                ['estimate_norm_id' => 10, 'resource_code' => null, 'unit' => 'kg', 'resource_type' => 'material'],
                ['estimate_norm_id' => 10, 'resource_code' => 'ABSENT', 'unit' => 'kg', 'resource_type' => 'material'],
                ['estimate_norm_id' => 10, 'resource_code' => 'MISMATCH', 'unit' => 'kg', 'resource_type' => 'machinery'],
                ['estimate_norm_id' => 10, 'resource_code' => 'NORMALIZED', 'unit' => 'маш.-ч', 'resource_type' => 'machinery'],
                ['estimate_norm_id' => 10, 'resource_code' => 'CONVERTED', 'unit' => '100 m2', 'resource_type' => 'labor'],
            ],
            [
                ['resource_code' => 'MISMATCH', 'unit' => 'm3'],
                ['resource_code' => 'NORMALIZED', 'unit' => 'маш ч'],
                ['resource_code' => 'CONVERTED', 'unit' => 'm2'],
            ],
            [
                ['from_unit' => '100 m2', 'to_unit' => 'm2'],
            ],
        );

        self::assertSame([
            'positive_resources' => 5,
            'priced_resources' => 2,
            'unpriced_resources' => 3,
            'reasons' => [
                'missing_resource_code' => 1,
                'absent_from_selected_sources' => 1,
                'unit_mismatch' => 1,
            ],
            'missing_resources' => [
                ['resource_code' => null, 'resource_type' => 'material', 'unit' => 'kg', 'reason' => 'missing_resource_code'],
                ['resource_code' => 'ABSENT', 'resource_type' => 'material', 'unit' => 'kg', 'reason' => 'absent_from_selected_sources'],
                ['resource_code' => 'MISMATCH', 'resource_type' => 'machinery', 'unit' => 'kg', 'reason' => 'unit_mismatch'],
            ],
        ], $result[10]);
    }
}
