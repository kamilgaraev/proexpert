<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class OrdinaryEstimateMoneyComponentsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_expanded_types_and_decimal_resources_round_trip_without_reclassifying_equipment(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $estimate = Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'MONEY-'.Str::random(8), 'name' => 'Проверка денежных компонентов', 'estimate_date' => '2026-10-11']);
        self::assertSame('0.00', $estimate->fresh()->contingency_amount);
        self::assertSame('0.00', $estimate->fresh()->total_other_costs);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1',
            'name' => 'Прочая услуга', 'item_type' => 'other', 'other_cost' => '12.34']);
        self::assertSame(EstimatePositionItemType::OTHER, $item->fresh()->item_type);
        self::assertSame('Прочие затраты', $item->item_type->label());
        $resource = EstimateItemResource::query()->create(['estimate_item_id' => $item->id, 'name' => 'Эксплуатация машины',
            'resource_type' => 'machinery', 'quantity_per_unit' => '0.123456789123456789', 'total_quantity' => '1.123456789123456789',
            'unit_price' => '12.345678912345', 'total_amount' => '13.87']);
        $resource->refresh();
        self::assertSame('0.123456789123456789', $resource->quantity_per_unit);
        self::assertSame('1.123456789123456789', $resource->total_quantity);
        self::assertSame('12.345678912345', $resource->unit_price);
        self::assertTrue($resource->isMachinery());
        $equipment = EstimateItemResource::query()->create(['estimate_item_id' => $item->id, 'name' => 'Оборудование', 'resource_type' => 'equipment']);
        self::assertTrue($equipment->fresh()->isEquipment());
        self::assertFalse($equipment->isMachinery());
    }

    public function test_database_does_not_accept_a_negative_contingency(): void
    {
        $organization = Organization::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('estimates')->insert(['organization_id' => $organization->id, 'number' => 'NEGATIVE-'.Str::random(8),
            'name' => 'Отрицательный резерв', 'estimate_date' => '2026-10-11', 'contingency_amount' => '-0.01']));
    }

    public function test_generated_resources_payroll_other_costs_and_contingency_survive_repeated_standard_recalculation(): void
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $estimate = Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'RECALC-'.Str::random(8), 'name' => 'Машина, машинист и прочие расходы', 'estimate_date' => '2026-10-11',
            'vat_rate' => '0', 'overhead_rate' => '0', 'profit_rate' => '0', 'contingency_amount' => '15.55',
            'metadata' => ['is_ai_generated' => true, 'calculation_version' => 'generated-decimal:v1']]);
        $work = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Монтаж',
            'item_type' => 'work', 'quantity' => '1', 'unit_price' => '826.69', 'total_amount' => '826.69', 'current_total_amount' => '826.69', 'is_manual' => true]);
        foreach (['machinery' => '477.92', 'labor' => '336.43', 'other' => '12.34'] as $type => $amount) {
            EstimateItem::query()->create(['estimate_id' => $estimate->id, 'parent_work_id' => $work->id, 'position_number' => '1.'.$type,
                'name' => $type === 'labor' ? 'Машинист' : 'Ресурс', 'normative_rate_code' => $type === 'labor' ? '4-100-040' : null,
                'item_type' => $type, 'quantity' => '1', 'unit_price' => $amount, 'total_amount' => $amount, 'current_total_amount' => $amount,
                'labor_cost' => $type === 'labor' ? $amount : '0', 'labor_hours' => $type === 'labor' ? '1' : '0',
                'machinery_hours' => $type === 'machinery' ? '1' : '0', 'is_manual' => true]);
        }
        $equipment = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '2', 'name' => 'Покупка оборудования',
            'item_type' => 'equipment', 'quantity' => '1', 'unit_price' => '1000', 'total_amount' => '1000', 'current_total_amount' => '1000', 'is_manual' => true]);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $work->refresh();
            $estimate->refresh();
            $equipment->refresh();
            self::assertSame('826.6900', $work->direct_costs);
            self::assertSame('336.4300', $work->labor_cost);
            self::assertSame('477.9200', $work->machinery_cost);
            self::assertSame('12.34', $work->other_cost);
            self::assertSame('1000.00', $estimate->total_equipment_costs);
            self::assertSame('12.34', $estimate->total_other_costs);
            self::assertSame('1842.24', $estimate->total_amount);
            self::assertSame('1842.24', $estimate->total_amount_with_vat);
            $export = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate);
            self::assertSame('1842.24', (string) $export['total_amount']);
            self::assertSame('12.34', $export['total_other_costs']);
            self::assertSame('15.55', $export['contingency_amount']);
        }
        app(EstimateItemService::class)->updateItem($work->fresh(), ['quantity' => '2']);
        $estimate->refresh();
        $work->refresh();
        self::assertSame('1653.38', $work->total_amount);
        self::assertSame('672.8600', $work->labor_cost);
        self::assertSame('955.8400', $work->machinery_cost);
        self::assertSame('24.68', $work->other_cost);
        self::assertSame('2668.93', $estimate->total_amount);
        $estimate->update(['vat_rate' => '20']);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame('2668.93', $estimate->total_amount);
            self::assertSame('3202.72', $estimate->total_amount_with_vat);
            $export = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate);
            self::assertSame('3202.72', (string) $export['total_amount_with_vat']);
        }
    }
}
