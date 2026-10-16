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

    public function test_fsbc_material_uses_the_shared_base_catalog_source_contract(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['coefficients']['project_material_evidence'][0]['price_source'] = 'fsbc_base';
        $resources = (new NormativePriceEvidenceProjector)->resources($snapshot);
        self::assertSame('fsbc', $resources[1]['source_type']);
        self::assertSame('base_catalog', $resources[1]['coefficients']['price_kind']);
    }

    public function test_historical_v4_preserves_both_sources_and_its_original_aggregate_rounding(): void
    {
        $snapshot = $this->historicalSnapshot();
        $projector = new NormativePriceEvidenceProjector;
        $resources = $projector->resources($snapshot, '1');
        self::assertCount(3, $resources);
        self::assertSame(['0.01', '0.00', '0.01'], array_column($resources, 'final_amount'));
        self::assertSame('estimate_resource_prices:12', $resources[2]['source_reference']);
        self::assertSame('base_catalog', $resources[2]['coefficients']['price_kind']);
        self::assertSame('aggregate_half_up_2', $resources[1]['coefficients']['rounding_policy']);
        self::assertSame('-0.01', $resources[1]['coefficients']['rounding_adjustment']);
        self::assertNull($projector->resources($snapshot));
        $snapshot['coefficients']['project_material_amount'] = '0.02';
        self::assertNull($projector->resources($snapshot, '1'));
        $snapshot = $this->historicalSnapshot();
        $snapshot['final_amount'] = '0.03';
        self::assertNull($projector->resources($snapshot, '1'));
    }

    public function test_historical_v8_keeps_the_same_final_sum_and_explicit_resource_adjustment(): void
    {
        $snapshot = $this->historicalSnapshot();
        $snapshot['coefficients']['pricing_formula_version'] = 'semantic_project_resource:v8';
        $snapshot['coefficients']['project_material_evidence'] = [];
        $entry = $snapshot['coefficients']['resource_evidence'][0];
        $entry['norm_resource_id'] = 23;
        $entry['resource_price_id'] = 14;
        $entry['base_price'] = '1';
        $snapshot['coefficients']['resource_evidence'][] = $entry;
        $snapshot['coefficients']['provenance']['resources'][] = ['norm_resource_id' => 23, 'price_id' => 14, 'regional_price_version_id' => 7];
        $snapshot['final_amount'] = '1.01';
        $resources = (new NormativePriceEvidenceProjector)->resources($snapshot, '1');
        self::assertSame(['0.01', '0.01', '0.99'], array_column($resources, 'final_amount'));
        self::assertSame('-0.01', $resources[2]['coefficients']['rounding_adjustment']);
    }

    private function historicalSnapshot(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['coefficients']['pricing_formula_version'] = 'supplementary_project_material:v4';
        unset($snapshot['coefficients']['rounding_policy']);
        $snapshot['final_amount'] = '0.02';
        $snapshot['coefficients']['project_material_amount'] = '0.01';
        $snapshot['coefficients']['resource_evidence'] = [];
        $snapshot['coefficients']['provenance']['resources'] = [];
        foreach ([21 => 11, 22 => 13] as $resource => $price) {
            $snapshot['coefficients']['resource_evidence'][] = ['norm_resource_id' => $resource, 'resource_price_id' => $price,
                'norm_quantity' => '1', 'work_to_norm_factor' => '1', 'conversion_factor' => '1', 'base_price' => '0.0050'];
            $snapshot['coefficients']['provenance']['resources'][] = ['norm_resource_id' => $resource, 'price_id' => $price, 'regional_price_version_id' => 7];
        }
        $snapshot['coefficients']['project_material_evidence'][0] = ['resource_price_id' => 12, 'price_source' => 'fsnb_base',
            'quantity_per_work_unit' => '1', 'price_factor' => '1', 'base_price' => '0.0050'];

        return $snapshot;
    }

    private function snapshot(): array
    {
        return ['version_id' => 7, 'final_amount' => '0.26', 'coefficients' => [
            'pricing_formula_version' => 'supplementary_project_material:v5', 'rounding_policy' => 'resource_half_up_2', 'work_cost' => '0.00', 'project_material_amount' => '0.13',
            'resource_evidence' => [['norm_resource_id' => 21, 'resource_price_id' => 11, 'final_amount' => '0.13']],
            'project_material_evidence' => [['resource_price_id' => 12, 'price_source' => 'fsnb_base', 'final_amount' => '0.13']],
            'provenance' => ['resources' => [['norm_resource_id' => 21, 'price_id' => 11, 'regional_price_version_id' => 7]]],
        ]];
    }
}
