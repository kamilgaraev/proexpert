<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Jobs\RecalculateEpmDataMartSnapshotJob;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use DomainException;
use Throwable;

use function trans_message;

final class EpmDataMartRecalculationCoordinator
{
    public function __construct(
        private readonly EpmDataMartPayloadProjector $projector,
        private readonly PlanFactReportService $planFactReportService,
    ) {
    }

    public function queue(EpmDataMartScope $scope, ?int $requestedBy = null, bool $dispatch = true): EpmDataMartRecalculationRun
    {
        return $this->queueMany([$scope], $requestedBy, $dispatch)[0];
    }

    public function queueMany(array $scopes, ?int $requestedBy = null, bool $dispatch = true): array
    {
        foreach ($scopes as $scope) {
            $this->validateScope($scope);
        }

        return DB::transaction(function () use ($scopes, $requestedBy, $dispatch): array {
            $runs = [];
            $dispatchableRuns = [];
            foreach ($scopes as $scope) {
                [$run, $created] = $this->createOrReuseQueuedRun($scope, $requestedBy);
                $runs[] = $run;

                if ($created && $dispatch) {
                    $dispatchableRuns[] = $run;
                }
            }

            if ($dispatchableRuns !== []) {
                DB::afterCommit(function () use ($dispatchableRuns): void {
                    $this->dispatchRuns($dispatchableRuns);
                });
            }

            return $runs;
        });
    }

    private function dispatchRuns(array $runs): void
    {
        $firstFailure = null;
        foreach ($runs as $run) {
            try {
                RecalculateEpmDataMartSnapshotJob::dispatch((int) $run->id);
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
                try {
                    $this->markDispatchFailed((int) $run->id, $exception);
                } catch (Throwable $statusException) {
                    Log::error('budgeting.epm_data_mart.dispatch_status_update_failed', [
                        'run_id' => (int) $run->id,
                        'exception_class' => $statusException::class,
                    ]);
                }
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    private function markDispatchFailed(int $runId, Throwable $exception): void
    {
        DB::transaction(function () use ($runId, $exception): void {
            $run = EpmDataMartRecalculationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if ($run instanceof EpmDataMartRecalculationRun && $run->status === EpmDataMartStatus::QUEUED) {
                $this->markFailed($run, $exception);
            }
        });
    }

    public function createOrReuseQueuedRun(EpmDataMartScope $scope, ?int $requestedBy = null): array
    {
        $this->validateScope($scope);

        return DB::transaction(function () use ($scope, $requestedBy): array {
            $active = EpmDataMartRecalculationRun::query()
                ->forScope($scope->organizationId, $scope->reportScope, $scope->scopeHash())
                ->whereIn('status', [EpmDataMartStatus::QUEUED, EpmDataMartStatus::RUNNING])
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($active instanceof EpmDataMartRecalculationRun) {
                return [$active, false];
            }

            $run = EpmDataMartRecalculationRun::query()->create([
                'organization_id' => $scope->organizationId,
                'report_scope' => $scope->reportScope,
                'scope_hash' => $scope->scopeHash(),
                'active_lock' => $scope->scopeHash(),
                'status' => EpmDataMartStatus::QUEUED,
                'formula_version' => EpmDataMartPayloadProjector::FORMULA_VERSION,
                'filters' => $scope->toArray(),
                'requested_by' => $requestedBy,
                'queued_at' => now(),
            ]);

            return [$run, true];
        });
    }

    private function validateScope(EpmDataMartScope $scope): void
    {
        if ($scope->reportScope !== EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD) {
            return;
        }

        $input = $scope->reportInput();
        $versionUuid = $input['budget_version_uuid'] ?? null;
        $scenarioUuid = $input['scenario_uuid'] ?? null;
        if ($versionUuid === null && $scenarioUuid === null) {
            return;
        }

        if (!is_string($versionUuid) || !Str::isUuid($versionUuid)
            || !is_string($scenarioUuid) || !Str::isUuid($scenarioUuid)) {
            throw new DomainException(trans_message('budgeting.project_portfolio_dashboard.errors.budget_selection_incomplete'));
        }

        if ($scope->periodStart === null || $scope->periodEnd === null) {
            throw new DomainException(trans_message('budgeting.project_portfolio_dashboard.errors.period_invalid'));
        }

        $this->planFactReportService->validateBudgetSelection($input);
    }

    public function markRunning(int $runId): ?EpmDataMartRecalculationRun
    {
        return DB::transaction(function () use ($runId): ?EpmDataMartRecalculationRun {
            $run = EpmDataMartRecalculationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();

            if (!$run instanceof EpmDataMartRecalculationRun) {
                return null;
            }

            if (EpmDataMartStatus::isTerminal((string) $run->status)) {
                return null;
            }

            $run->forceFill([
                'status' => EpmDataMartStatus::RUNNING,
                'started_at' => now(),
                'attempts_count' => (int) $run->attempts_count + 1,
            ])->save();

            return $run->refresh();
        });
    }

    public function markSucceeded(EpmDataMartRecalculationRun $run, EpmDataMartSnapshot $snapshot, string $status, string $sourceHash, array $sourceRefs, string $generatedAt): EpmDataMartRecalculationRun
    {
        $startedAt = $run->started_at;
        $durationMs = $startedAt !== null ? max(0, (int) $startedAt->diffInMilliseconds(now())) : null;

        $run->forceFill([
            'status' => $status,
            'active_lock' => null,
            'source_hash' => $sourceHash,
            'snapshot_id' => $snapshot->id,
            'source_refs' => $sourceRefs,
            'error_summary' => null,
            'finished_at' => now(),
            'generated_at' => $generatedAt,
            'duration_ms' => $durationMs,
        ])->save();

        return $run->refresh();
    }

    public function markFailedById(int $runId, Throwable $throwable): void
    {
        $run = EpmDataMartRecalculationRun::query()->find($runId);

        if (!$run instanceof EpmDataMartRecalculationRun || EpmDataMartStatus::isTerminal((string) $run->status)) {
            return;
        }

        $this->markFailed($run, $throwable);
    }

    public function markFailed(EpmDataMartRecalculationRun $run, Throwable $throwable): void
    {
        $run->forceFill([
            'status' => EpmDataMartStatus::FAILED,
            'active_lock' => null,
            'error_summary' => $this->projector->errorSummary($throwable),
            'finished_at' => now(),
        ])->save();

        Log::warning('budgeting.epm_data_mart.recalculation_failed', [
            'run_id' => $run->id,
            'organization_id' => $run->organization_id,
            'report_scope' => $run->report_scope,
            'exception_class' => $throwable::class,
        ]);
    }
}
