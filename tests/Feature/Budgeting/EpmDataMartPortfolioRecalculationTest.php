<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Jobs\RecalculateEpmDataMartSnapshotJob;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationCoordinator;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class EpmDataMartPortfolioRecalculationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_portfolio_selection_is_rejected_without_any_runs_or_jobs(string $scope, string $case): void
    {
        $context = $this->context();
        $budget = $this->budget($context->organization);
        $input = $this->input($budget, $scope);
        if ($case === 'organization') {
            $foreign = $this->budget(Organization::factory()->create());
            $input['budget_version_uuid'] = $foreign['version']->uuid;
        } elseif ($case === 'period') {
            $input['period_start'] = '2026-11-01';
            $input['period_end'] = '2026-11-30';
        } else {
            $other = $this->budget($context->organization);
            $input['scenario_uuid'] = $other['scenario']->uuid;
        }

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/budgeting/epm-data-mart/recalculate', $input)
            ->assertStatus(422);

        self::assertSame(0, EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)->count());
        Queue::assertNotPushed(RecalculateEpmDataMartSnapshotJob::class);
    }

    public static function invalidSelections(): array
    {
        $cases = [];
        foreach (['project_portfolio_dashboard', 'all', ''] as $scope) {
            foreach (['organization', 'period', 'scenario'] as $case) {
                $cases[$scope.' '.$case] = [$scope, $case];
            }
        }

        return $cases;
    }

    public function test_valid_non_default_selection_queues_all_scopes_and_reuses_active_runs(): void
    {
        $context = $this->context();
        $budget = $this->budget($context->organization);
        $input = $this->input($budget, 'all');
        $first = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/budgeting/epm-data-mart/recalculate', $input)
            ->assertStatus(202)
            ->assertJsonPath('data.accepted_count', count(EpmDataMartScope::SUPPORTED_REPORT_SCOPES));
        $second = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/budgeting/epm-data-mart/recalculate', $input)
            ->assertStatus(202);

        self::assertSame($first->json('data.runs'), $second->json('data.runs'));
        self::assertSame(count(EpmDataMartScope::SUPPORTED_REPORT_SCOPES), EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)->count());
        Queue::assertPushed(RecalculateEpmDataMartSnapshotJob::class, count(EpmDataMartScope::SUPPORTED_REPORT_SCOPES));
        $run = EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)
            ->where('report_scope', EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD)->firstOrFail();
        self::assertSame($budget['version']->uuid, $run->filters['filters']['budget_version_uuid']);
        self::assertSame($budget['scenario']->uuid, $run->filters['filters']['scenario_uuid']);
    }

    public function test_direct_coordinator_rejects_incomplete_portfolio_selection_before_creating_a_run(): void
    {
        $context = $this->context();
        $budget = $this->budget($context->organization);
        $input = $this->input($budget, EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD);
        unset($input['scenario_uuid']);
        $scope = EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD, ['organization_id' => $context->organization->id, ...$input]);

        try {
            app(EpmDataMartRecalculationCoordinator::class)->createOrReuseQueuedRun($scope, $context->user->id);
            self::fail('Incomplete portfolio selection must not create a run.');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('budgeting.project_portfolio_dashboard.errors.budget_selection_incomplete'), $exception->getMessage());
        }
        self::assertSame(0, EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)->count());
        Queue::assertNotPushed(RecalculateEpmDataMartSnapshotJob::class);
    }

    public function test_batch_persistence_failure_rolls_back_all_runs_and_dispatches_nothing(): void
    {
        $context = $this->context();
        $valid = EpmDataMartScope::fromInput(EpmDataMartScope::CFO_COMMAND_CENTER, [
            'organization_id' => $context->organization->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ]);
        $invalid = EpmDataMartScope::fromInput(EpmDataMartScope::CFO_COMMAND_CENTER, [
            'organization_id' => PHP_INT_MAX,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ]);

        try {
            app(EpmDataMartRecalculationCoordinator::class)->queueMany([$valid, $invalid], $context->user->id);
            self::fail('An invalid batch must not be accepted.');
        } catch (ModelNotFoundException|QueryException) {
            self::assertSame(0, EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)->count());
            Queue::assertNotPushed(RecalculateEpmDataMartSnapshotJob::class);
        }
    }

    public function test_jobs_are_dispatched_only_after_the_outer_transaction_commits(): void
    {
        $context = $this->context();
        $scope = EpmDataMartScope::fromInput(EpmDataMartScope::CFO_COMMAND_CENTER, [
            'organization_id' => $context->organization->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ]);
        $initialTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $run = app(EpmDataMartRecalculationCoordinator::class)->queue($scope, $context->user->id);
            Queue::assertNotPushed(RecalculateEpmDataMartSnapshotJob::class);
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > $initialTransactionLevel) {
                DB::rollBack();
            }
            throw $exception;
        }

        Queue::assertPushed(RecalculateEpmDataMartSnapshotJob::class, fn ($job): bool => $job->runId === $run->id);
    }

    public function test_dispatch_failure_does_not_strand_the_batch_and_retry_recovers_only_the_failed_scope(): void
    {
        $context = $this->context();
        $scopes = array_map(fn (string $reportScope): EpmDataMartScope => EpmDataMartScope::fromInput($reportScope, [
            'organization_id' => $context->organization->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ]), [EpmDataMartScope::CFO_COMMAND_CENTER, EpmDataMartScope::CASH_GAP]);
        $coordinator = app(EpmDataMartRecalculationCoordinator::class);
        $originalDispatcher = app(Dispatcher::class);
        $attempts = [];
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($originalDispatcher, &$attempts) {
            if (!$job instanceof RecalculateEpmDataMartSnapshotJob) {
                return $originalDispatcher->dispatch($job);
            }
            $attempts[] = $job->runId;
            if (count($attempts) === 1) {
                throw new \RuntimeException('Queue transport unavailable');
            }

            return null;
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            try {
                $coordinator->queueMany($scopes, $context->user->id);
                self::fail('A transport failure must be reported.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Queue transport unavailable', $exception->getMessage());
            }
            $runs = EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)->orderBy('id')->get();
            self::assertCount(2, $attempts);
            self::assertSame('failed', $runs[0]->status);
            self::assertNull($runs[0]->active_lock);
            self::assertSame('queued', $runs[1]->status);
            $retried = $coordinator->queueMany($scopes, $context->user->id);
            self::assertNotSame($runs[0]->id, $retried[0]->id);
            self::assertSame($runs[1]->id, $retried[1]->id);
            self::assertSame([$runs[0]->id, $runs[1]->id, $retried[0]->id], $attempts);
        } finally {
            $this->app->instance(Dispatcher::class, $originalDispatcher);
        }
    }

    public function test_dispatch_failure_does_not_replace_a_run_already_started_by_a_worker(): void
    {
        $context = $this->context();
        $scope = EpmDataMartScope::fromInput(EpmDataMartScope::CFO_COMMAND_CENTER, [
            'organization_id' => $context->organization->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ]);
        $coordinator = app(EpmDataMartRecalculationCoordinator::class);
        $originalDispatcher = app(Dispatcher::class);
        $attempts = [];
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($originalDispatcher, $coordinator, &$attempts) {
            if (!$job instanceof RecalculateEpmDataMartSnapshotJob) {
                return $originalDispatcher->dispatch($job);
            }
            $attempts[] = $job->runId;
            $coordinator->markRunning($job->runId);
            throw new \RuntimeException('Queue acknowledgement unavailable');
        });
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            try {
                $coordinator->queue($scope, $context->user->id);
                self::fail('An acknowledgement failure must be reported.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Queue acknowledgement unavailable', $exception->getMessage());
            }
            $run = EpmDataMartRecalculationRun::query()->where('organization_id', $context->organization->id)->firstOrFail();
            self::assertSame('running', $run->status);
            self::assertSame($run->id, $coordinator->queue($scope, $context->user->id)->id);
            self::assertSame([$run->id], $attempts);
        } finally {
            $this->app->instance(Dispatcher::class, $originalDispatcher);
        }
    }

    private function context(): AdminApiTestContext
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $module = Module::query()->firstOrCreate(['slug' => 'budgeting'], [
            'name' => 'Budgeting', 'version' => '1.0.0', 'type' => 'feature',
            'billing_model' => 'free', 'category' => 'finance',
            'permissions' => ['budgeting.cfo.manage'], 'is_active' => true,
            'is_system_module' => true,
        ]);
        $module->forceFill(['is_system_module' => true])->save();
        OrganizationModuleActivation::query()->create([
            'organization_id' => $context->organization->id, 'module_id' => $module->id,
            'status' => 'active', 'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($context->organization->id, 'budgeting');

        return $context;
    }

    private function budget(Organization $organization): array
    {
        $period = BudgetPeriod::query()->create([
            'organization_id' => $organization->id, 'code' => 'QUEUE-'.uniqid(),
            'name' => 'October', 'period_type' => 'month',
            'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open',
        ]);
        $scenario = BudgetScenario::query()->create([
            'organization_id' => $organization->id, 'code' => 'QUEUE-'.uniqid(),
            'name' => 'Explicit non-default', 'scenario_type' => 'base',
            'is_default' => false, 'is_active' => true,
        ]);
        $version = BudgetVersion::query()->create([
            'organization_id' => $organization->id, 'budget_period_id' => $period->id,
            'scenario_id' => $scenario->id, 'budget_kind' => 'bdds', 'version_number' => 1,
            'name' => 'Explicit budget', 'status' => 'active', 'approved_at' => now(), 'activated_at' => now(),
        ]);

        return ['version' => $version, 'scenario' => $scenario];
    }

    private function input(array $budget, string $scope): array
    {
        return [
            'report_scope' => $scope, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'budget_version_uuid' => $budget['version']->uuid, 'scenario_uuid' => $budget['scenario']->uuid,
        ];
    }
}
