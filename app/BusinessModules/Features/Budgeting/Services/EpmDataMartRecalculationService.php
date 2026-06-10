<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartAggregate;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

use function trans_message;

final class EpmDataMartRecalculationService
{
    public function __construct(
        private readonly EpmDataMartRecalculationCoordinator $coordinator,
        private readonly EpmDataMartPayloadProjector $projector,
        private readonly CfoCommandCenterService $cfoCommandCenterService,
        private readonly ProjectPortfolioDashboardService $projectPortfolioDashboardService,
        private readonly ProjectMarginReportService $projectMarginReportService,
        private readonly WipForecastReportService $wipForecastReportService,
        private readonly PlanFactReportService $planFactReportService,
        private readonly CashGapForecastReadService $cashGapForecastReadService,
    ) {
    }

    public function recalculateRun(int $runId, bool $markFailedOnException = true): ?EpmDataMartSnapshot
    {
        $run = $this->coordinator->markRunning($runId);

        if (!$run instanceof EpmDataMartRecalculationRun) {
            return null;
        }

        try {
            $scope = $this->scopeFromRun($run);
            $payload = $this->payload($scope);
            $snapshotPayload = $this->projector->build($scope, $payload);

            return DB::transaction(function () use ($run, $scope, $snapshotPayload): EpmDataMartSnapshot {
                EpmDataMartSnapshot::query()
                    ->forScope($scope->organizationId, $scope->reportScope, $scope->scopeHash())
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => now()]);

                $snapshot = EpmDataMartSnapshot::query()->create([
                    'organization_id' => $scope->organizationId,
                    'report_scope' => $scope->reportScope,
                    'scope_hash' => $scope->scopeHash(),
                    'status' => $snapshotPayload->status,
                    'formula_version' => $snapshotPayload->formulaVersion,
                    'source_hash' => $snapshotPayload->sourceHash,
                    'period_start' => $scope->periodStart,
                    'period_end' => $scope->periodEnd,
                    'as_of_date' => $scope->asOfDate,
                    'project_id' => $scope->projectId,
                    'currency' => $scope->currency,
                    'filters' => $scope->toArray(),
                    'payload' => $snapshotPayload->payload,
                    'freshness' => $snapshotPayload->freshness,
                    'source_refs' => $snapshotPayload->sourceRefs,
                    'generated_at' => $snapshotPayload->generatedAt,
                    'stale_at' => $this->staleAt($snapshotPayload->generatedAt),
                ]);

                foreach ($snapshotPayload->aggregates as $aggregate) {
                    EpmDataMartAggregate::query()->create([
                        ...$aggregate,
                        'snapshot_id' => $snapshot->id,
                    ]);
                }

                $this->coordinator->markSucceeded(
                    run: $run,
                    snapshot: $snapshot,
                    status: $snapshotPayload->status,
                    sourceHash: $snapshotPayload->sourceHash,
                    sourceRefs: $snapshotPayload->sourceRefs,
                    generatedAt: $snapshotPayload->generatedAt,
                );

                return $snapshot->refresh();
            });
        } catch (Throwable $throwable) {
            if ($markFailedOnException) {
                $this->coordinator->markFailed($run, $throwable);
            }

            throw $throwable;
        }
    }

    private function payload(EpmDataMartScope $scope): array
    {
        $input = [
            ...$scope->reportInput(),
            '_skip_data_mart_meta' => true,
        ];

        return match ($scope->reportScope) {
            EpmDataMartScope::CFO_COMMAND_CENTER => $this->cfoCommandCenterService->dashboard($input),
            EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD => $this->projectPortfolioDashboardService->dashboard($input),
            EpmDataMartScope::PROJECT_MARGIN => $this->projectMarginReportService->report($input),
            EpmDataMartScope::WIP_FORECAST => $this->wipForecastReportService->report($input),
            EpmDataMartScope::PLAN_FACT => $this->planFactReportService->report($input),
            EpmDataMartScope::CASH_GAP => $this->cashGapForecastReadService->build($input),
            default => throw new DomainException(trans_message('budgeting.epm_data_mart.messages.unsupported_scope')),
        };
    }

    private function scopeFromRun(EpmDataMartRecalculationRun $run): EpmDataMartScope
    {
        $filters = is_array($run->filters) ? $run->filters : [];
        $innerFilters = is_array($filters['filters'] ?? null) ? $filters['filters'] : [];

        return new EpmDataMartScope(
            organizationId: (int) $run->organization_id,
            reportScope: (string) $run->report_scope,
            periodStart: is_string($filters['period_start'] ?? null) ? $filters['period_start'] : null,
            periodEnd: is_string($filters['period_end'] ?? null) ? $filters['period_end'] : null,
            asOfDate: is_string($filters['as_of_date'] ?? null) ? $filters['as_of_date'] : null,
            projectId: is_numeric($filters['project_id'] ?? null) ? (int) $filters['project_id'] : null,
            currency: is_string($filters['currency'] ?? null) ? $filters['currency'] : null,
            filters: $innerFilters,
        );
    }

    private function staleAt(string $generatedAt): string
    {
        try {
            return CarbonImmutable::parse($generatedAt)
                ->addMinutes($this->staleAfterMinutes())
                ->toIso8601String();
        } catch (Throwable) {
            return now()->addMinutes($this->staleAfterMinutes())->toIso8601String();
        }
    }

    private function staleAfterMinutes(): int
    {
        $minutes = (int) config('budgeting.epm_data_mart.stale_after_minutes', 120);

        return max(5, min(10080, $minutes));
    }
}
