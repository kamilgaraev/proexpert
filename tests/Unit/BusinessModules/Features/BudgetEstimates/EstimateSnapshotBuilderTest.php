<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateSnapshotBuilder;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EstimateSnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_contains_stable_section_and_item_keys(): void
    {
        $estimate = $this->createEstimate();
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Section',
            'sort_order' => 1,
            'section_total_amount' => 1500,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Work',
            'item_type' => 'work',
            'quantity' => 2.5,
            'unit_price' => 600,
            'total_amount' => 1500,
        ]);

        $snapshot = app(EstimateSnapshotBuilder::class)->build($estimate);
        $section->refresh();
        $item->refresh();

        $this->assertNotNull($section->stable_key);
        $this->assertNotNull($item->stable_key);
        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertSame($section->stable_key, $snapshot['sections'][0]['stable_key']);
        $this->assertSame($item->stable_key, $snapshot['sections'][0]['items'][0]['stable_key']);
        $this->assertSame(
            'item:root:'.$section->stable_key.':1.1::work:work',
            $snapshot['sections'][0]['items'][0]['structural_key']
        );
        $this->assertSame('2.50000000', $snapshot['sections'][0]['items'][0]['quantity']);
        $this->assertSame('600.00', $snapshot['sections'][0]['items'][0]['unit_price']);
    }

    public function test_snapshot_preserves_item_resources_as_independent_financial_rows(): void
    {
        $estimate = $this->createEstimate();
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Work with resources',
            'item_type' => 'work',
            'quantity' => 2,
            'unit_price' => 750,
            'total_amount' => 1500,
        ]);
        $resource = EstimateItemResource::query()->create([
            'estimate_item_id' => $item->id,
            'resource_type' => 'material',
            'name' => 'Concrete',
            'description' => 'B25',
            'quantity_per_unit' => 1.25,
            'total_quantity' => 2.5,
            'unit_price' => 600,
            'total_amount' => 1500,
        ]);

        $snapshot = app(EstimateSnapshotBuilder::class)->build($estimate);
        $resourcePayload = $snapshot['unsectioned_items'][0]['resources'][0];

        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertSame($resource->id, $resourcePayload['source_id']);
        $this->assertSame('material', $resourcePayload['resource_type']);
        $this->assertSame('Concrete', $resourcePayload['name']);
        $this->assertSame('B25', $resourcePayload['description']);
        $this->assertSame('1.2500', $resourcePayload['quantity_per_unit']);
        $this->assertSame('2.5000', $resourcePayload['total_quantity']);
        $this->assertSame('600.00', $resourcePayload['unit_price']);
        $this->assertSame('1500.00', $resourcePayload['total_amount']);
    }

    public function test_hash_is_deterministic_for_unchanged_estimate(): void
    {
        $estimate = $this->createEstimate();
        $section = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Section',
            'sort_order' => 1,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'estimate_section_id' => $section->id,
            'position_number' => '1.1',
            'name' => 'Work',
            'item_type' => 'work',
            'quantity' => 3,
            'unit_price' => 100,
            'total_amount' => 300,
        ]);

        $builder = app(EstimateSnapshotBuilder::class);

        $firstSnapshot = $builder->build($estimate);
        $secondSnapshot = $builder->build($estimate->fresh());

        $this->assertSame($firstSnapshot, $secondSnapshot);
        $this->assertSame($builder->hash($firstSnapshot), $builder->hash($secondSnapshot));
    }

    public function test_snapshot_preserves_complete_estimate_financial_context_as_decimal_strings(): void
    {
        $estimate = $this->createEstimate();
        $now = now();
        $regionId = (int) DB::table('estimate_regions')->insertGetId([
            'code' => 'SNAPSHOT-REGION',
            'name' => 'Snapshot region',
            'fgiscs_subject_id' => 990016,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $zoneId = (int) DB::table('estimate_price_zones')->insertGetId([
            'estimate_region_id' => $regionId,
            'name' => 'Snapshot zone',
            'fgiscs_price_zone_id' => 990003,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $periodId = (int) DB::table('estimate_price_periods')->insertGetId([
            'fgiscs_period_id' => 990008,
            'name' => 'Snapshot period',
            'year' => 2098,
            'quarter' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $priceVersionId = (int) DB::table('estimate_regional_price_versions')->insertGetId([
            'source' => 'test',
            'region_id' => $regionId,
            'price_zone_id' => $zoneId,
            'period_id' => $periodId,
            'version_key' => 'prices-2026.05',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $estimate->forceFill([
            'estimate_region_id' => $regionId,
            'estimate_price_zone_id' => $zoneId,
            'estimate_price_period_id' => $periodId,
            'estimate_regional_price_version_id' => $priceVersionId,
            'regional_price_snapshot' => ['version_key' => 'prices-2026.05'],
            'metadata' => ['source' => 'approved-import'],
            'import_diagnostics' => ['warnings' => []],
            'statistics' => ['positions' => 1],
            'total_base_direct_costs' => '9999999999999.99',
        ])->save();

        $snapshot = app(EstimateSnapshotBuilder::class)->build($estimate);

        $this->assertSame($regionId, $snapshot['estimate']['estimate_region_id']);
        $this->assertSame($zoneId, $snapshot['estimate']['estimate_price_zone_id']);
        $this->assertSame($periodId, $snapshot['estimate']['estimate_price_period_id']);
        $this->assertSame($priceVersionId, $snapshot['estimate']['estimate_regional_price_version_id']);
        $this->assertSame(['version_key' => 'prices-2026.05'], $snapshot['estimate']['regional_price_snapshot']);
        $this->assertSame(['source' => 'approved-import'], $snapshot['estimate']['metadata']);
        $this->assertSame(['warnings' => []], $snapshot['estimate']['import_diagnostics']);
        $this->assertSame(['positions' => 1], $snapshot['estimate']['statistics']);
        $this->assertSame('9999999999999.99', $snapshot['totals']['total_base_direct_costs']);
    }

    public function test_nested_section_full_number_is_computed_from_parent_path(): void
    {
        $estimate = $this->createEstimate();
        $root = EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'full_section_number' => '1',
            'name' => 'Root section',
            'sort_order' => 1,
        ]);
        EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'parent_section_id' => $root->id,
            'section_number' => '2',
            'full_section_number' => '2',
            'name' => 'Child section',
            'sort_order' => 1,
        ]);

        $snapshot = app(EstimateSnapshotBuilder::class)->build($estimate);
        $root->refresh();

        $childPayload = $snapshot['sections'][0]['children'][0];

        $this->assertSame('2', $childPayload['section_number']);
        $this->assertSame('1.2', $childPayload['full_section_number']);
        $this->assertSame(
            'section:'.$root->stable_key.':1.2:2:child section',
            $childPayload['structural_key']
        );
    }

    private function createEstimate(): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-'.(DB::table('estimates')->count() + 1),
            'name' => 'Test estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-05-05',
            'base_price_date' => '2026-05-01',
            'total_direct_costs' => 1500,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_equipment_costs' => 0,
            'total_amount' => 1500,
            'total_amount_with_vat' => 1800,
            'vat_rate' => 20,
            'overhead_rate' => 0,
            'profit_rate' => 0,
            'calculation_method' => 'resource',
        ]);
    }
}
