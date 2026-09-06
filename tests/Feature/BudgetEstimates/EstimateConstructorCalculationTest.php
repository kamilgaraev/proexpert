<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Normative\EnhancedCalculationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateConstructorCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normative_indices_and_coefficients_survive_repeated_constructor_recalculation(): void
    {
        \Illuminate\Support\Facades\Bus::fake([
            \App\BusinessModules\Features\BudgetEstimates\Jobs\CalculateEstimateStatisticsJob::class,
            \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::class,
        ]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CONSTRUCTOR-NORMATIVE',
            'name' => 'Проверка нормативного пересчёта',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-09-06',
            'calculation_method' => 'resource',
            'overhead_rate' => 15,
            'profit_rate' => 12,
            'vat_rate' => 20,
        ]);
        $baseId = \Illuminate\Support\Facades\DB::table('normative_base_types')->insertGetId([
            'code' => 'TEST-CONSTRUCTOR', 'name' => 'Тестовая база',
        ]);
        $collectionId = \Illuminate\Support\Facades\DB::table('normative_collections')->insertGetId([
            'base_type_id' => $baseId, 'code' => 'TEST', 'name' => 'Тестовый сборник',
        ]);
        $rate = \App\Models\NormativeRate::query()->create([
            'collection_id' => $collectionId, 'code' => 'TEST-1', 'name' => 'Нормативная работа',
            'materials_cost' => 60, 'machinery_cost' => 10, 'labor_cost' => 30,
        ]);
        $coefficient = \App\Models\RegionalCoefficient::query()->create([
            'coefficient_type' => 'regional', 'name' => 'Тестовый коэффициент', 'coefficient_value' => 1.5,
        ]);
        app(\App\Repositories\PriceIndexRepository::class)->createOrUpdate([
            'index_type' => 'construction_general', 'year' => 2026, 'month' => 9, 'index_value' => 2,
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Нормативная работа',
            'item_type' => 'work', 'normative_rate_id' => $rate->id, 'quantity' => 2,
            'unit_price' => 100, 'direct_costs' => 200, 'total_amount' => 254,
            'applied_coefficients' => [['id' => $coefficient->id]],
        ]);
        $options = ['apply_indices' => true, 'calculation_date' => \Carbon\Carbon::parse('2026-09-06')];

        foreach ([1, 2] as $iteration) {
            $result = app(EnhancedCalculationService::class)->recalculateEstimate($estimate, $options);
            $item->refresh();
            $this->assertEquals(300, $item->unit_price);
            $this->assertEquals(600, $item->direct_costs);
            $this->assertEquals(762, $item->total_amount);
            $this->assertEquals(762, $result->total_amount);
            $this->assertEquals(914.4, $result->total_amount_with_vat);
        }
    }

    public function test_constructor_totals_count_work_once_with_its_resources(): void
    {
        \Illuminate\Support\Facades\Bus::fake([
            \App\BusinessModules\Features\BudgetEstimates\Jobs\CalculateEstimateStatisticsJob::class,
            \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::class,
        ]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CONSTRUCTOR-CALC',
            'name' => 'Проверка пересчёта конструктора',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-09-06',
            'calculation_method' => 'resource',
            'overhead_rate' => 15,
            'profit_rate' => 12,
            'vat_rate' => 20,
        ]);
        $work = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Работа',
            'item_type' => 'work',
            'quantity' => 1,
            'unit_price' => 100,
            'direct_costs' => 100,
            'overhead_amount' => 15,
            'profit_amount' => 12,
            'total_amount' => 127,
        ]);
        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'parent_work_id' => $work->id,
            'position_number' => '1.1',
            'name' => 'Материал',
            'item_type' => 'material',
            'quantity' => 1,
            'unit_price' => 100,
            'direct_costs' => 100,
            'total_amount' => 100,
        ]);

        $result = app(EnhancedCalculationService::class)->recalculateEstimate($estimate);

        $this->assertEquals(100, $result->total_direct_costs);
        $this->assertEquals(15, $result->total_overhead_costs);
        $this->assertEquals(12, $result->total_estimated_profit);
        $this->assertEquals(127, $result->total_amount);
        $this->assertEquals(152.4, $result->total_amount_with_vat);

        EstimateItem::query()->where('parent_work_id', $work->id)->update(['quantity' => 2]);
        $result = app(EnhancedCalculationService::class)->recalculateEstimate($estimate);

        $this->assertEquals(200, $work->fresh()->direct_costs);
        $this->assertEquals(254, $work->fresh()->total_amount);
        $this->assertEquals(254, $result->total_amount);
        $this->assertEquals(304.8, $result->total_amount_with_vat);
        $this->assertEquals(254, app(EnhancedCalculationService::class)->recalculateEstimate($estimate)->total_amount);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::class, 3);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\BusinessModules\Features\BudgetEstimates\Jobs\CalculateEstimateStatisticsJob::class, 3);
    }
}
