<?php

declare(strict_types=1);

namespace Tests\Feature\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\MeasurementUnit;
use App\Models\WorkType;
use App\Models\CompletedWork;
use App\Services\Acting\ActingActWizardService;
use App\Services\ActReport\ActReportWorkflowService;
use DomainException;
use Tests\TestCase;

final class WorkVolumeCoverageTest extends TestCase
{
    public function test_module_permission_does_not_open_an_unassigned_project(): void
    {
        $context = \Tests\Support\AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['project_access_mode' => 'assigned_projects']);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $statement = WorkVolumeStatement::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'statement_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'version' => 1, 'name' => 'ВОР', 'status' => 'draft',
        ]);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $this->expectExceptionCode(404);
        app(WorkVolumeCoverageService::class)->allocations($context->user, $statement);
    }

    public function test_statement_quantity_is_distributed_across_contracts_without_overcoverage(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create();
        $actor->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        $actor->forceFill(['current_organization_id' => $organization->id])->save();
        $estimate = Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'WV-COV-1', 'name' => 'Основание', 'type' => 'local', 'status' => 'approved', 'version' => 1, 'estimate_date' => '2026-09-20']);
        $unit = MeasurementUnit::query()->create(['organization_id' => $organization->id, 'name' => 'Квадратный метр', 'short_name' => 'м2', 'type' => 'work']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Работа', 'quantity' => 100, 'measurement_unit_id' => $unit->id]);
        $statement = WorkVolumeStatement::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'statement_key' => '44444444-4444-4444-8444-444444444444', 'version' => 1, 'name' => 'ВОР', 'status' => WorkVolumeStatement::STATUS_DRAFT]);
        $line = $statement->lines()->create(['line_key' => '55555555-5555-4555-8555-555555555555', 'name' => 'Работа', 'unit_code' => 'м2', 'quantity' => '100', 'place' => ['axis' => 'А-1'], 'estimate_item_id' => $item->id]);
        $statements = app(\App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService::class);
        $statement = $statements->approve($actor, $statements->submitForReview($actor, $statement, 0), 1);
        $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Подрядчик']);
        $contracts = collect([1, 2])->map(fn (int $n) => Contract::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'COV-'.$n, 'date' => '2026-09-20', 'total_amount' => 100000]));
        foreach ($contracts as $index => $contract) {
            ContractEstimateItem::query()->create(['contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => $index === 0 ? 60 : 40]);
        }
        $rows = $contracts->values()->map(fn (Contract $contract, int $index) => ['statement_line_id' => $line->id, 'contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => $index === 0 ? '60' : '40', 'unit_code' => 'м2'])->all();
        $service = $this->app->make(WorkVolumeCoverageService::class);
        $first = $service->replaceAllocations($actor, $statement, $rows, 'coverage-1', 0);
        self::assertSame(1, $first['coverage_revision']);
        self::assertCount(2, $first['allocations']);
        self::assertEquals($first, $service->replaceAllocations($actor, $statement, $rows, 'coverage-1', 0));
        try {
            $service->replaceAllocations($actor, $statement, $rows, 'stale-operation', 0);
            self::fail('Устаревшая версия распределения должна быть отклонена');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $empty = $service->replaceAllocations($actor, $statement, [], 'clear-coverage', 1);
        self::assertSame(['coverage_revision' => 2, 'allocations' => []], $empty);
        self::assertSame($empty, $service->allocations($actor, $statement));
        self::assertEquals($first, $service->replaceAllocations($actor, $statement, $rows, 'coverage-1', 0));
        self::assertSame($empty, $service->allocations($actor, $statement));
        $restored = $service->replaceAllocations($actor, $statement, $rows, 'restore-coverage', 2);
        self::assertSame(3, $restored['coverage_revision']);
        foreach (['work_volume_coverage_revisions', 'work_volume_statement_coverages'] as $table) {
            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                \Illuminate\Support\Facades\DB::table($table)->where('statement_id', $statement->id)->delete();
                self::fail('История распределений должна сохраняться');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertSame('55000', $exception->errorInfo[0]);
            } finally {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        }
        $rows[0]['quantity'] = '70';
        try {
            $service->replaceAllocations($actor, $statement, $rows, 'coverage-overflow', 3);
            self::fail('Дополнительные 10 не покрыты утверждённой ВОР');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        foreach (range(3, 20) as $number) {
            $contract = Contract::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'contractor_id' => $contractor->id, 'number' => 'COV-'.$number, 'date' => '2026-09-20', 'total_amount' => 1000]);
            ContractEstimateItem::query()->create(['contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => 5]);
            $contracts->push($contract);
        }
        $manyRows = $contracts->map(fn (Contract $contract): array => ['statement_line_id' => $line->id,
            'contract_id' => $contract->id, 'estimate_id' => $estimate->id, 'estimate_item_id' => $item->id, 'quantity' => '5', 'unit_code' => 'м2'])->all();
        $queryCount = 0;
        $capture = true;
        \Illuminate\Support\Facades\DB::listen(static function () use (&$queryCount, &$capture): void {
            if ($capture) {
                $queryCount++;
            }
        });
        $many = $service->replaceAllocations($actor, $statement, $manyRows, 'twenty-contracts', 3);
        $capture = false;
        self::assertCount(20, $many['allocations']);
        self::assertLessThanOrEqual(35, $queryCount, 'Проверки распределения не должны выполнять запросы на каждый договор');
    }
}
