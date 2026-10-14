<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\EstimateRagMutationBatch;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EstimateCalculationClassificationTest extends TestCase
{
    use RefreshDatabase;

    public static function bulkItemCounts(): array
    {
        return ['small' => [10], 'bulk' => [150]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bulkItemCounts')]
    public function test_bulk_recalculation_without_children_uses_bounded_select_count(int $itemCount): void
    {
        Queue::fake();
        $estimate = $this->createEstimate();
        for ($index = 1; $index <= $itemCount; $index++) {
            $this->createItem($estimate, ['position_number' => (string) $index]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(EstimateCalculationService::class)->recalculateAll($estimate);
            $selects = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^\s*select\b/i', $query['query']) === 1);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $distribution = array_count_values(array_map(static function (array $query): string {
            $template = preg_replace("/'(?:''|[^'])*'/", '?', $query['query']);
            $template = preg_replace('/\b\d+\b/', '#', $template ?? '');

            return substr(preg_replace('/\s+/', ' ', $template ?? '') ?? '', 0, 300);
        }, $selects));
        arsort($distribution);
        $this->assertLessThanOrEqual(12, count($selects), 'SELECT distribution: '.json_encode($distribution));
        $this->assertEquals($itemCount * 127, $result['total_amount']);
        $this->assertEquals($itemCount * 100, $result['total_direct_costs']);
        $this->assertEquals($itemCount, EstimateItem::query()->where('estimate_id', $estimate->id)->count());
    }

    public function test_bulk_recalculation_preserves_signed_children_and_aggregates_loaded_hours(): void
    {
        Queue::fake();
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate, ['unit_price' => 999]);
        $labor = $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'labor',
            'normative_rate_code' => '1-100-01',
            'quantity' => -2,
            'unit_price' => 100,
            'total_amount' => 999,
            'current_total_amount' => -200,
            'is_manual' => true,
            'labor_cost' => -200,
            'labor_hours' => 3,
            'metadata' => ['raw_data' => []],
        ]);
        $machinery = $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'machinery',
            'quantity' => 2,
            'unit_price' => 30,
            'total_amount' => 999,
            'machinery_hours' => 2,
        ]);

        app(EstimateCalculationService::class)->recalculateAll($estimate);
        $work->refresh();
        $this->assertEquals(-200, $labor->fresh()->total_amount);
        $this->assertEquals(60, $machinery->fresh()->total_amount);
        $this->assertEquals(-140, $work->direct_costs);
        $this->assertEquals(-21, $work->overhead_amount);
        $this->assertEquals(-16.8, $work->profit_amount);
        $this->assertEquals(-177.8, $work->total_amount);
        $this->assertEquals(-200, $work->labor_cost);
        $this->assertEquals(3, $work->labor_hours);
        $this->assertEquals(2, $work->machinery_hours);
        $this->assertEquals(-177.8, $estimate->fresh()->total_amount);
    }

    public function test_bulk_recalculation_preserves_indexing_entities_and_tenant_isolation(): void
    {
        Queue::fake();
        $estimate = $this->createEstimate();
        $root = EstimateSection::create(['estimate_id' => $estimate->id, 'section_number' => '1', 'name' => 'Корневой раздел', 'sort_order' => 1]);
        $section = EstimateSection::create(['estimate_id' => $estimate->id, 'parent_section_id' => $root->id, 'section_number' => '1.1', 'name' => 'Вложенный раздел', 'sort_order' => 1]);
        $items = [];
        for ($index = 1; $index <= 3; $index++) {
            $items[] = $this->createItem($estimate, ['estimate_section_id' => $section->id, 'position_number' => (string) $index]);
        }
        $resource = EstimateItemResource::create(['estimate_item_id' => $items[0]->id, 'resource_type' => 'material', 'name' => 'Ресурс', 'total_quantity' => 1, 'unit_price' => 10, 'total_amount' => 10]);
        $foreign = $this->createEstimate();
        $this->createItem($foreign);
        $foreignRuns = RagIndexRun::query()->where('organization_id', $foreign->organization_id)->count();
        RagIndexRun::query()->where('organization_id', $estimate->organization_id)->where('source_type', 'estimate')->delete();

        $calculator = app(EstimateCalculationService::class);
        DB::transaction(function () use ($estimate, $items, $resource, $calculator): void {
            app(EstimateRagMutationBatch::class)->run($estimate, collect($items), static function () use ($estimate, $resource, $calculator): void {
                $resource->update(['unit_price' => 20, 'total_amount' => 20]);
                $calculator->recalculateAll($estimate);
            });
        });
        $calculator->recalculateAll($estimate);
        $runs = RagIndexRun::query()->where('organization_id', $estimate->organization_id)->where('source_type', 'estimate')->get();
        $actual = $runs->map(static fn (RagIndexRun $run): string => $run->entity_type.':'.$run->entity_id)->sort()->values()->all();
        $expected = [
            'estimate:'.$estimate->id,
            'estimate_summary:'.$estimate->id,
            'estimate_section:'.$root->id,
            'estimate_section:'.$section->id,
            'estimate_item_resource:'.$resource->id,
            ...array_map(static fn (EstimateItem $item): string => 'estimate_item:'.$item->id, $items),
        ];
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->assertTrue($runs->every(static fn (RagIndexRun $run): bool => (int) $run->project_id === (int) $estimate->project_id));
        $this->assertEquals($foreignRuns, RagIndexRun::query()->where('organization_id', $foreign->organization_id)->count());
    }

    public function test_failed_bulk_mutation_rolls_back_indexing_and_clears_scoped_batch(): void
    {
        Queue::fake();
        $estimate = $this->createEstimate();
        $item = $this->createItem($estimate);
        $resource = EstimateItemResource::create(['estimate_item_id' => $item->id, 'resource_type' => 'material', 'name' => 'Ресурс', 'total_quantity' => 1, 'unit_price' => 10, 'total_amount' => 10]);
        RagIndexRun::query()->where('organization_id', $estimate->organization_id)->where('source_type', 'estimate')->delete();
        Queue::fake();
        try {
            DB::transaction(function () use ($estimate, $item, $resource): void {
                app(EstimateRagMutationBatch::class)->run($estimate, collect([$item]), static function () use ($item, $resource): void {
                    $item->update(['quantity' => 2]);
                    $resource->update(['unit_price' => 20, 'total_amount' => 20]);
                    throw new \RuntimeException('synthetic_calculation_failure');
                });
            });
            $this->fail('Bulk mutation must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic_calculation_failure', $exception->getMessage());
        }

        $this->assertEquals(1, $item->fresh()->quantity);
        $this->assertEquals(10, $resource->fresh()->total_amount);
        $this->assertSame(0, RagIndexRun::query()->where('organization_id', $estimate->organization_id)->where('source_type', 'estimate')->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
        $item->refresh()->update(['quantity' => 3]);
        $this->assertSame(2, RagIndexRun::query()->where('organization_id', $estimate->organization_id)->where('source_type', 'estimate')->count());
    }

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

    public function test_imported_negative_and_zero_totals_survive_root_and_child_recalculation(): void
    {
        $estimate = $this->createEstimate();
        $parent = $this->createItem($estimate);

        foreach ([null, $parent->id] as $parentId) {
            foreach ([-400, 0] as $sourceTotal) {
                $item = $this->createItem($estimate, [
                    'parent_work_id' => $parentId,
                    'item_type' => 'material',
                    'quantity' => -0.014,
                    'unit_price' => 28557.38,
                    'is_manual' => true,
                    'current_total_amount' => $sourceTotal,
                    'metadata' => ['raw_data' => ['quantity' => -0.014]],
                ]);

                $result = app(EstimateCalculationService::class)->calculateItemTotal($item, $estimate);

                $this->assertEquals($sourceTotal, $result);
                $this->assertEquals($sourceTotal, $item->fresh()->total_amount);
                $this->assertEquals($sourceTotal, $item->fresh()->direct_costs);
            }
        }
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
        $estimate->update(['structure_cache_path' => 'old-snapshot.json']);
        $migration = require database_path('migrations/2026_09_12_180000_restore_imported_work_labor_hours.php');
        $migration->up();
        $migration->up();
        $this->assertEqualsWithDelta(4.235, $works[0]->fresh()->labor_hours, 0.000001);
        $this->assertEquals(7.5, $works[1]->fresh()->labor_hours);
        $this->assertEquals(900, $works[0]->fresh()->total_amount);
        $this->assertSame('draft', $estimate->fresh()->status);
        $this->assertNull($estimate->fresh()->structure_cache_path);
    }

    public function test_backfill_skips_sealed_approved_estimates(): void
    {
        $estimate = $this->createEstimate();
        $work = $this->createItem($estimate, [
            'is_manual' => true,
            'labor_hours' => 0,
            'metadata' => ['raw_data' => []],
        ]);
        $this->createItem($estimate, [
            'parent_work_id' => $work->id,
            'item_type' => 'labor',
            'labor_hours' => 4.235,
            'is_manual' => true,
            'is_not_accounted' => true,
            'metadata' => ['raw_data' => []],
        ]);
        $version = \App\Models\EstimateVersion::query()->create([
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'version_number' => 1,
            'snapshot_type' => 'approval',
            'estimate_status' => 'approved',
            'status' => 'approved',
            'snapshot' => [],
        ]);
        $estimate->update([
            'status' => 'approved',
            'current_version_id' => $version->id,
            'structure_cache_path' => 'sealed-snapshot.json',
        ]);
        $migration = require database_path('migrations/2026_09_12_180000_restore_imported_work_labor_hours.php');
        $migration->up();
        $this->assertEquals(0, $work->fresh()->labor_hours);
        $this->assertSame('sealed-snapshot.json', $estimate->fresh()->structure_cache_path);
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
