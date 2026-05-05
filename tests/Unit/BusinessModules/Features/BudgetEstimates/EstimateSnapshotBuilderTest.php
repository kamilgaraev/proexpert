<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateSnapshotBuilder;
use App\Models\Estimate;
use App\Models\EstimateItem;
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
        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame($section->stable_key, $snapshot['sections'][0]['stable_key']);
        $this->assertSame($item->stable_key, $snapshot['sections'][0]['items'][0]['stable_key']);
        $this->assertSame(
            'item:root:' . $section->stable_key . ':1.1::work:work',
            $snapshot['sections'][0]['items'][0]['structural_key']
        );
        $this->assertSame('2.50000000', $snapshot['sections'][0]['items'][0]['quantity']);
        $this->assertSame('600.00', $snapshot['sections'][0]['items'][0]['unit_price']);
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
            'section:' . $root->stable_key . ':1.2:2:child section',
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
            'number' => 'EST-' . (DB::table('estimates')->count() + 1),
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
