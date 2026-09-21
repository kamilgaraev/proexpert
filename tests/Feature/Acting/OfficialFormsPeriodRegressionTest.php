<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Acting\ActingActWizardService;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

final class OfficialFormsPeriodRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ks3_uses_reporting_period_for_late_acts_and_year_boundary(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Подрядчик', 'contractor_type' => 'manual']);
        $contract = Contract::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'T07', 'date' => '2025-01-01', 'status' => 'active', 'total_amount' => 1000, 'is_fixed_amount' => true, 'currency' => 'RUB']);
        $unit = MeasurementUnit::query()->where('organization_id', $organization->id)->firstOrFail();
        $type = WorkType::create(['organization_id' => $organization->id, 'name' => 'Работа', 'measurement_unit_id' => $unit->id]);
        $work = CompletedWork::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'work_type_id' => $type->id, 'user_id' => $actor->id, 'quantity' => 100, 'completed_quantity' => 100, 'price' => 1, 'total_amount' => 100, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED, 'completion_date' => '2025-12-01', 'status' => CompletedWork::STATUS_CONFIRMED]);
        $acts = [];
        $worksByMonth = ['2025-12' => $work];
        foreach ([['2025-12-31', 10], ['2026-01-31', 20], ['2026-01-31', 5], ['2026-02-28', 30]] as $index => [$period, $quantity]) {
            $month = substr($period, 0, 7);
            if (! isset($worksByMonth[$month])) {
                $monthlyWork = $work->replicate();
                $monthlyWork->completion_date = $month.'-01';
                $monthlyWork->save();
                $worksByMonth[$month] = $monthlyWork;
            }
            $act = app(ActingActWizardService::class)->createFromWizard($organization->id, [
                'contract_id' => $contract->id, 'act_document_number' => 'T07-'.$index,
                'act_date' => '2026-03-10', 'period_start' => substr($period, 0, 7).'-01', 'period_end' => $period,
                'selected_works' => [['completed_work_id' => $worksByMonth[$month]->id, 'quantity' => $quantity]],
            ], $actor->id, false);
            $workflow = app(ActReportWorkflowService::class);
            $workflow->submit($act, $actor->id);
            $acts[] = $workflow->approve($act->fresh(), $actor->id);
        }

        $service = app(OfficialFormsExportService::class);
        $method = (new ReflectionClass($service))->getMethod('ks3LineAggregates');
        $december = $method->invoke($service, $acts[0]);
        $january = $method->invoke($service, $acts[2]);

        self::assertSame(10.0, $december['total_from_start']);
        self::assertSame(10.0, $december['year_total']);
        self::assertSame(35.0, $january['total_from_start']);
        self::assertSame(25.0, $january['year_total']);
        self::assertSame(25.0, $january['lines']['work:'.$worksByMonth['2026-01']->id]['from_start']);
    }

    public function test_ks6a_fills_thirteen_months_keeps_unacted_fact_and_same_title_keys(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $contractor = Contractor::create(['organization_id' => $organization->id, 'name' => 'Подрядчик', 'contractor_type' => 'manual']);
        $contract = Contract::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'T21-KS6A', 'date' => '2025-12-01', 'status' => 'active', 'total_amount' => 1000, 'is_fixed_amount' => true, 'currency' => 'RUB']);
        $unit = MeasurementUnit::query()->where('organization_id', $organization->id)->firstOrFail();
        $typeA = WorkType::create(['organization_id' => $organization->id, 'name' => 'Одинаковое имя', 'measurement_unit_id' => $unit->id]);
        $typeB = WorkType::create(['organization_id' => $organization->id, 'name' => 'Одинаковое имя', 'measurement_unit_id' => $unit->id]);
        $actedWork = CompletedWork::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'work_type_id' => $typeA->id, 'user_id' => $actor->id, 'quantity' => 40, 'completed_quantity' => 40, 'price' => 1, 'total_amount' => 40, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED, 'completion_date' => '2025-12-15', 'status' => CompletedWork::STATUS_CONFIRMED]);
        $sameNameWork = CompletedWork::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'work_type_id' => $typeB->id, 'user_id' => $actor->id, 'quantity' => 15, 'completed_quantity' => 15, 'price' => 1, 'total_amount' => 15, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED, 'completion_date' => '2026-01-10', 'status' => CompletedWork::STATUS_CONFIRMED]);
        $unactedWork = CompletedWork::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'work_type_id' => $typeA->id, 'user_id' => $actor->id, 'quantity' => 7, 'completed_quantity' => 7, 'price' => 1, 'total_amount' => 7, 'work_origin_type' => CompletedWork::ORIGIN_MANUAL, 'planning_status' => CompletedWork::PLANNING_PLANNED, 'completion_date' => '2026-06-01', 'status' => CompletedWork::STATUS_CONFIRMED]);

        foreach ([[$actedWork, '2025-12-31', 40, 'T21-6A-1'], [$sameNameWork, '2026-01-31', 15, 'T21-6A-2']] as [$work, $period, $quantity, $number]) {
            $act = app(ActingActWizardService::class)->createFromWizard($organization->id, [
                'contract_id' => $contract->id, 'act_document_number' => $number,
                'act_date' => '2026-03-10', 'period_start' => substr($period, 0, 7).'-01', 'period_end' => $period,
                'selected_works' => [['completed_work_id' => $work->id, 'quantity' => $quantity]],
            ], $actor->id, false);
            $workflow = app(ActReportWorkflowService::class);
            $workflow->submit($act, $actor->id);
            $workflow->approve($act->fresh(), $actor->id);
        }

        $service = app(OfficialFormsExportService::class);
        $data = (new ReflectionClass($service))->getMethod('prepareKS6aData')->invoke($service, $contract, '2025-12-01', '2026-12-31');
        $monthKeys = collect($data['month_groups'])->pluck('key')->filter()->values();

        self::assertCount(13, $monthKeys);
        self::assertSame('2025-12', $monthKeys->first());
        self::assertSame('2026-12', $monthKeys->last());
        self::assertCount(3, $data['rows']);
        self::assertSame(['Одинаковое имя', 'Одинаковое имя', 'Одинаковое имя'], $data['rows']->pluck('title')->all());
        self::assertCount(3, $data['rows']->pluck('number')->unique()->all());

        $juneFact = $data['rows']->sum(fn (array $row): float => (float) ($row['months']['2026-06']['fact_amount'] ?? 0));
        $juneActed = $data['rows']->sum(fn (array $row): float => (float) ($row['months']['2026-06']['acted_amount'] ?? $row['months']['2026-06']['amount'] ?? 0));
        self::assertSame(7.0, $juneFact);
        self::assertSame(0.0, $juneActed);
        self::assertGreaterThan($data['rows']->sum('acted_amount'), $data['rows']->sum('fact_amount'));
    }
}
