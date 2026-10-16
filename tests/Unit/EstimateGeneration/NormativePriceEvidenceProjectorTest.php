<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NormativePriceEvidenceProjector;
use PHPUnit\Framework\TestCase;

final class NormativePriceEvidenceProjectorTest extends TestCase
{
    public function test_finalized_mixed_price_preserves_both_actual_sources_and_per_resource_amounts(): void
    {
        $resources = (new NormativePriceEvidenceProjector)->resources($this->snapshot());
        self::assertCount(2, $resources);
        self::assertSame(['estimate_resource_prices:11', 'estimate_resource_prices:12'], array_column($resources, 'source_reference'));
        self::assertSame(['regional_catalog', 'fsnb_2022'], array_column($resources, 'source_type'));
        self::assertSame(['0.13', '0.13'], array_column($resources, 'final_amount'));
        self::assertSame('base_catalog', $resources[1]['coefficients']['price_kind']);
    }

    public function test_incomplete_or_inconsistent_finalized_evidence_cannot_become_a_price_basis(): void
    {
        $snapshot = $this->snapshot();
        $invalid = [];
        $change = $snapshot;
        unset($change['coefficients']['project_material_evidence'][0]['final_amount']);
        $invalid[] = $change;
        $change = $snapshot;
        $change['final_amount'] = '0.25';
        $invalid[] = $change;
        $change = $snapshot;
        $change['coefficients']['resource_evidence'][0]['resource_price_id'] = 13;
        $invalid[] = $change;
        $change = $snapshot;
        $change['coefficients']['rounding_policy'] = 'aggregate';
        $invalid[] = $change;
        $change = $snapshot;
        $change['coefficients']['project_material_evidence'][0]['final_amount'] = '-0.13';
        $invalid[] = $change;
        foreach ($invalid as $value) {
            self::assertNull((new NormativePriceEvidenceProjector)->resources($value));
        }
    }

    public function test_base_normative_price_keeps_its_dataset_source_instead_of_regional_label(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['coefficients']['provenance']['resources'][0]['regional_price_version_id'] = null;
        $snapshot['coefficients']['provenance']['resources'][0]['price_dataset'] = ['source_type' => 'fsnb_2022'];
        $resources = (new NormativePriceEvidenceProjector)->resources($snapshot);
        self::assertSame('fsnb_2022', $resources[0]['source_type']);
        self::assertSame('base_catalog', $resources[0]['coefficients']['price_kind']);
    }

    private function snapshot(): array
    {
        return ['version_id' => 7, 'final_amount' => '0.26', 'coefficients' => [
            'pricing_formula_version' => 'supplementary_project_material:v5', 'rounding_policy' => 'resource_half_up_2', 'work_cost' => '0.00',
            'resource_evidence' => [['norm_resource_id' => 21, 'resource_price_id' => 11, 'final_amount' => '0.13']],
            'project_material_evidence' => [['resource_price_id' => 12, 'price_source' => 'fsnb_base', 'final_amount' => '0.13']],
            'provenance' => ['resources' => [['norm_resource_id' => 21, 'price_id' => 11, 'regional_price_version_id' => 7]]],
        ]];
    }
}
