<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_preserves_composition_with_new_ids_and_without_source_structure_cache(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $source = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'COPY-SOURCE',
            'name' => 'Исходная смета',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-09-06',
            'calculation_method' => 'resource',
            'structure_cache_path' => 'org-'.$organization->id.'/estimates/source.json',
            'total_amount' => 1200,
        ]);
        $parent = EstimateSection::query()->create([
            'estimate_id' => $source->id,
            'section_number' => '1',
            'name' => 'Родительский раздел',
            'sort_order' => 10,
            'section_total_amount' => 1200,
        ]);
        $child = EstimateSection::query()->create([
            'estimate_id' => $source->id,
            'parent_section_id' => $parent->id,
            'section_number' => '1',
            'name' => 'Вложенный раздел',
            'sort_order' => 0,
            'section_total_amount' => 1200,
        ]);
        $work = EstimateItem::query()->create([
            'estimate_id' => $source->id,
            'estimate_section_id' => $child->id,
            'position_number' => '2',
            'name' => 'Работа',
            'item_type' => 'work',
            'normative_rate_code' => 'TEST-01',
            'quantity' => 2,
            'unit_price' => 600,
            'total_amount' => 1200,
            'labor_hours' => 12,
            'resource_calculation' => ['labor' => 12],
        ]);
        $material = EstimateItem::query()->create([
            'estimate_id' => $source->id,
            'estimate_section_id' => $child->id,
            'parent_work_id' => $work->id,
            'position_number' => '1',
            'name' => 'Материал',
            'item_type' => 'material',
            'quantity' => 4,
            'unit_price' => 100,
            'total_amount' => 400,
            'is_not_accounted' => true,
        ]);
        $work->resources()->create([
            'resource_type' => 'labor',
            'name' => 'Затраты труда',
            'quantity_per_unit' => 6,
            'total_quantity' => 12,
            'unit_price' => 50,
            'total_amount' => 600,
        ]);
        $work->works()->create(['caption' => 'Состав работы', 'sort_order' => 3]);
        $work->totals()->create(['data_type' => 'labor', 'caption' => 'Оплата труда', 'total_curr' => 600]);

        $copy = app(EstimateService::class)->duplicate($source, 'COPY-RESULT');
        $copyParent = EstimateSection::query()->where('estimate_id', $copy->id)->where('name', $parent->name)->firstOrFail();
        $copyChild = EstimateSection::query()->where('estimate_id', $copy->id)->where('name', $child->name)->firstOrFail();
        $copyWork = EstimateItem::query()->where('estimate_id', $copy->id)->where('name', $work->name)->firstOrFail();
        $copyMaterial = EstimateItem::query()->where('estimate_id', $copy->id)->where('name', $material->name)->firstOrFail();

        $this->assertNull($copy->structure_cache_path);
        $this->assertNull($copy->current_version_id);
        $this->assertSame($source->structure_cache_path, $source->fresh()->structure_cache_path);
        $this->assertSame($source->total_amount, $copy->total_amount);
        $this->assertNotSame($parent->id, $copyParent->id);
        $this->assertSame($copyParent->id, $copyChild->parent_section_id);
        $this->assertSame($child->section_total_amount, $copyChild->section_total_amount);
        $this->assertSame($copyChild->id, $copyWork->estimate_section_id);
        $this->assertSame($copyChild->id, $copyMaterial->estimate_section_id);
        $this->assertSame($copyWork->id, $copyMaterial->parent_work_id);
        $this->assertSame($material->item_type, $copyMaterial->item_type);
        $this->assertTrue($copyMaterial->is_not_accounted);
        $this->assertSame($work->normative_rate_code, $copyWork->normative_rate_code);
        $this->assertSame($work->labor_hours, $copyWork->labor_hours);
        $this->assertSame($work->resource_calculation, $copyWork->resource_calculation);
        $this->assertSame('600.00', $copyWork->resources()->sole()->total_amount);
        $this->assertSame('Состав работы', $copyWork->works()->sole()->caption);
        $this->assertSame('600.00', $copyWork->totals()->sole()->total_curr);
        $this->assertSame($work->id, $material->fresh()->parent_work_id);
        $this->assertSame($parent->id, $child->fresh()->parent_section_id);
        $this->assertSame(2, EstimateItem::query()->where('estimate_id', $copy->id)->count());
        $this->assertSame(2, EstimateSection::query()->where('estimate_id', $copy->id)->count());
    }
}
