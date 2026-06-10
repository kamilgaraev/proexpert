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
use Throwable;

final class EpmDataMartRecalculationCoordinator
{
    public function __construct(
        private readonly EpmDataMartPayloadProjector $projector,
    ) {
    }

    public function queue(EpmDataMartScope $scope, ?int $requestedBy = null, bool $dispatch = true): EpmDataMartRecalculationRun
    {
        [$run, $created] = $this->createOrReuseQueuedRun($scope, $requestedBy);

        if ($created && $dispatch) {
            RecalculateEpmDataMartSnapshotJob::dispatch((int) $run->id);
        }

        return $run;
    }

    public function createOrReuseQueuedRun(EpmDataMartScope $scope, ?int $requestedBy = null): array
    {
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
