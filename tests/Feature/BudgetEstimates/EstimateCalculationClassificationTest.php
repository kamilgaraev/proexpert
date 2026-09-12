<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateCalculationClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_does_not_change_work_or_material_into_equipment(): void
    {
        $estimate = $this->createEstimate();
        $calculator = app(EstimateCalculationService::class);

        foreach (['work', 'material'] as $type) {
            foreach ([100, 600000] as $price) {
                $item = $this->createItem($estimate, ['item_type' => $type, 'unit_price' => $price]);
                $result = $calculator->calculateItemTotal($item, $estimate);
                $item->refresh();

                $this->assertEqualsWithDelta($price * 1.27, $result, 0.001);
                $this->assertEquals($price, $item->direct_costs);
                $this->assertEquals(0, $item->equipment_cost);
                $this->assertEquals($price * 0.15, $item->overhead_amount);
                $this->assertEquals($price * 0.12, $item->profit_amount);
            }
        }
    }

    public function test_expensive_work_does_not_add_its_price_again_to_resource_total(): void
    {
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate, ['unit_price' => 1412802.18]);
        $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'material',
            'unit_price' => 1412802.18,
            'total_amount' => 1412802.18,
        ]);

        $result = app(EstimateCalculationService::class)->calculateItemTotal($work, $estimate);

        $this->assertEqualsWithDelta(1794258.77, $result, 0.001);
        $this->assertEquals(0, $work->fresh()->equipment_cost);
    }

    public function test_expensive_resource_remains_material_and_is_counted_once(): void
    {
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate);
        $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'material',
            'unit_price' => 6000000,
            'total_amount' => 6000000,
        ]);

        $result = app(EstimateCalculationService::class)->calculateItemTotal($work, $estimate);

        $this->assertEquals(7620000, $result);
        $this->assertEquals(6000000, $work->fresh()->direct_costs);
        $this->assertEquals(0, $work->fresh()->equipment_cost);
    }

    public function test_explicit_equipment_keeps_separate_cost_without_overhead(): void
    {
        $estimate = $this->createEstimate();
        $item = $this->createItem($estimate, ['item_type' => 'equipment', 'unit_price' => 600000]);

        $result = app(EstimateCalculationService::class)->calculateItemTotal($item, $estimate);

        $this->assertEquals(600000, $result);
        $this->assertEquals(600000, $item->fresh()->equipment_cost);
        $this->assertEquals(0, $item->fresh()->direct_costs);
        $this->assertEquals(0, $item->fresh()->overhead_amount);
        $this->assertEquals(0, $item->fresh()->profit_amount);
    }

    public function test_imported_expensive_work_preserves_total_and_existing_overhead(): void
    {
        $estimate = $this->createEstimate();
        $item = $this->createItem($estimate, [
            'unit_price' => 600000,
            'is_manual' => true,
            'current_total_amount' => 762000,
            'direct_costs' => 600000,
            'overhead_amount' => 90000,
            'profit_amount' => 72000,
        ]);

        $result = app(EstimateCalculationService::class)->calculateItemTotal($item, $estimate);

        $this->assertEquals(762000, $result);
        $this->assertEquals(600000, $item->fresh()->direct_costs);
        $this->assertEquals(90000, $item->fresh()->overhead_amount);
        $this->assertEquals(72000, $item->fresh()->profit_amount);
        $this->assertEquals(0, $item->fresh()->equipment_cost);
    }

    public function test_child_equipment_is_added_once_outside_overhead_base(): void
    {
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate, ['unit_price' => 5000]);
        $this->createItem($estimate, ['parent_work_id' => $work->id, 'item_type' => 'equipment', 'total_amount' => 1000]);
        $calculator = app(EstimateCalculationService::class);

        $this->assertEquals(1000, $calculator->calculateItemTotal($work, $estimate));
        $this->assertEquals(0, $work->fresh()->direct_costs);
        $this->createItem($estimate, ['parent_work_id' => $work->id, 'item_type' => 'material', 'total_amount' => 100]);
        $this->assertEquals(1127, $calculator->calculateItemTotal($work, $estimate));
        $this->assertEquals(100, $work->fresh()->direct_costs);
        $this->assertEquals(1000, $work->fresh()->equipment_cost);
    }

    public function test_excluded_resources_do_not_return_through_zero_sum_branch(): void
    {
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate, ['unit_price' => 5000]);
        foreach (['material', 'equipment'] as $type) {
            $this->createItem($estimate, [
                'parent_work_id' => $work->id,
                'item_type' => $type,
                'total_amount' => 1000,
                'is_not_accounted' => true,
            ]);
        }

        $this->assertEquals(0, app(EstimateCalculationService::class)->calculateItemTotal($work, $estimate));
        $this->assertEquals(0, $work->fresh()->direct_costs);
        $this->assertEquals(0, $work->fresh()->equipment_cost);
    }

    public function test_labor_and_machinery_hours_are_aggregated_by_enum_type(): void
    {
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate);
        $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'labor',
            'normative_rate_code' => '1-100-01',
            'labor_cost' => 200,
            'labor_hours' => 4,
        ]);
        $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'machinery',
            'machinery_hours' => 3,
        ]);
        $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'labor',
            'labor_cost' => 900,
            'labor_hours' => 90,
            'is_not_accounted' => true,
        ]);

        app(EstimateCalculationService::class)->calculateItemTotal($work, $estimate);

        $this->assertEquals(200, $work->fresh()->labor_cost);
        $this->assertEquals(4, $work->fresh()->labor_hours);
        $this->assertEquals(3, $work->fresh()->machinery_hours);

        EstimateItem::query()->where('parent_work_id', $work->id)->update(['is_not_accounted' => true]);
        app(EstimateCalculationService::class)->calculateItemTotal($work, $estimate);

        $this->assertEquals(0, $work->fresh()->labor_cost);
        $this->assertEquals(0, $work->fresh()->labor_hours);
        $this->assertEquals(0, $work->fresh()->machinery_hours);
    }

    public function test_imported_resource_hours_are_deduplicated_without_changing_money(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate, [
            'is_manual' => true,
            'current_total_amount' => 4134.53,
            'direct_costs' => 4134.53,
            'metadata' => ['raw_data' => []],
        ]);
        foreach ([
            ['ОТ(ЗТ)', 'ОТ', 4.235],
            ['Средний разряд рабочих', '1-100-01', 4.235],
            ['ОТм(ЗТм)', 'ОТм', 0.144],
            ['ОТм(ЗТм) Средний разряд', '4-100-01', 0.144],
        ] as [$name, $code, $hours]) {
            $this->createItem($estimate, [
                'parent_work_id' => $work->id,
                'name' => $name,
                'normative_rate_code' => $code,
                'item_type' => 'labor',
                'quantity' => $hours,
                'labor_hours' => $hours,
                'labor_cost' => 1354.65,
                'is_manual' => true,
                'is_not_accounted' => true,
                'metadata' => ['raw_data' => []],
            ]);
        }

        $calculator = app(EstimateCalculationService::class);
        $calculator->recalculateAll($estimate);
        $work->refresh();
        $this->assertEqualsWithDelta(4.379, $work->labor_hours, 0.000001);
        $this->assertEquals(4134.53, $work->total_amount);
        $this->assertEquals(4134.53, $work->direct_costs);
        $this->assertEquals(0, $work->labor_cost);
        $calculator->recalculateAll($estimate);
        $this->assertEqualsWithDelta(4.379, $work->fresh()->labor_hours, 0.000001);
    }

    public function test_backfill_restores_only_missing_imported_work_hours(): void
    {
        $estimate = $this->createEstimate();
        $works = [];
        foreach ([0, 7.5] as $hours) {
            $work = $this->createItem($estimate, [
                'is_manual' => true,
                'labor_hours' => $hours,
                'total_amount' => 900,
                'metadata' => ['raw_data' => []],
            ]);
            $this->createItem($estimate, [
                'parent_work_id' => $work->id,
                'item_type' => 'labor',
                'quantity' => 4.235,
                'labor_hours' => 0,
                'is_manual' => true,
                'is_not_accounted' => true,
                'metadata' => ['raw_data' => []],
            ]);
            $works[] = $work;
        }
        $estimate->update(['status' => 'approved', 'structure_cache_path' => 'old-snapshot.json']);
        $migration = require database_path('migrations/2026_09_12_180000_restore_imported_work_labor_hours.php');
        $migration->up();
        $migration->up();
        $this->assertEqualsWithDelta(4.235, $works[0]->fresh()->labor_hours, 0.000001);
        $this->assertEquals(7.5, $works[1]->fresh()->labor_hours);
        $this->assertEquals(900, $works[0]->fresh()->total_amount);
        $this->assertSame('approved', $estimate->fresh()->status);
        $this->assertNull($estimate->fresh()->structure_cache_path);
    }

    private function createEstimate(): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CALC-TYPES',
            'name' => 'Проверка расчёта',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-09-06',
            'calculation_method' => 'resource',
            'overhead_rate' => 15,
            'profit_rate' => 12,
        ]);
    }

    private function createItem(Estimate $estimate, array $attributes = []): EstimateItem
    {
        return EstimateItem::query()->create(array_merge([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Позиция',
            'item_type' => 'work',
            'quantity' => 1,
            'unit_price' => 100,
            'is_manual' => false,
        ], $attributes));
    }
}
