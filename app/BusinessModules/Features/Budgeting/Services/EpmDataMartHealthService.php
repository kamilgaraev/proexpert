<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

use function config;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function max;
use function mb_strtolower;
use function mb_strtoupper;
use function min;
use function now;
use function round;
use function sort;
use function sprintf;
use function str_contains;
use function trim;
use function trans_message;

final class EpmDataMartHealthService
{
    private const REPORT_HREFS = [
        EpmDataMartScope::CFO_COMMAND_CENTER => '/budgeting?tab=cfo',
        EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD => '/budgeting?tab=project_portfolio',
        EpmDataMartScope::PROJECT_MARGIN => '/budgeting?tab=project_margin',
        EpmDataMartScope::WIP_FORECAST => '/budgeting?tab=wip_forecast',
        EpmDataMartScope::PLAN_FACT => '/budgeting?tab=plan_fact',
        EpmDataMartScope::CASH_GAP => '/budgeting?tab=cfo',
    ];

    private const FORBIDDEN_REASON_PARTS = [
        'trace',
        'stack',
        'sqlstate',
        'select ',
        'insert ',
        'update ',
        'delete ',
        'truncate ',
        'alter ',
        'drop ',
        'payload',
        'dto',
        'fallback',
        'legacy',
        'exception',
        'constraint',
        'token',
        'secret',
        'password',
    ];

    public function status(int $organizationId, array $filters = []): array
    {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException(trans_message('epm_data_mart.health.errors.organization_required'));
        }

        $thresholds = $this->thresholds();

        $snapshotsQuery = EpmDataMartSnapshot::query()
            ->where('organization_id', $organizationId)
            ->whereNull('superseded_at');
        $this->applySnapshotFilters($snapshotsQuery, $filters);

        $runsQuery = EpmDataMartRecalculationRun::query()
            ->where('organization_id', $organizationId);
        $this->applyRunFilters($runsQuery, $filters);

        $snapshots = $snapshotsQuery
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit($thresholds['health_history_limit'])
            ->get()
            ->all();
        $runs = $runsQuery
            ->orderByDesc('id')
            ->limit($thresholds['health_history_limit'])
            ->get()
            ->all();

        return $this->buildPayload(
            snapshots: $snapshots,
            runs: $this->filterRunsInMemory($runs, $filters),
            generatedAt: CarbonImmutable::parse(now()->toIso8601String()),
        );
    }

    public function buildPayload(iterable $snapshots, iterable $runs, ?CarbonInterface $generatedAt = null): array
    {
        $now = $generatedAt instanceof CarbonInterface
            ? CarbonImmutable::parse($generatedAt->toIso8601String())
            : CarbonImmutable::parse(now()->toIso8601String());
        $thresholds = $this->thresholds();
        $latestSnapshots = $this->latestSnapshots($snapshots);
        $latestRuns = $this->latestRuns($runs);
        $scopeKeys = array_values(array_unique([...array_keys($latestSnapshots), ...array_keys($latestRuns)]));
        $counts = $this->emptyCounts();
        $items = [];
        $failedScopes = [];

        foreach ($scopeKeys as $scopeKey) {
            $snapshot = $latestSnapshots[$scopeKey] ?? null;
            $run = $latestRuns[$scopeKey] ?? null;
            $status = $this->scopeStatus($snapshot, $run, $now, $thresholds);

            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }

            $item = $this->scopeItem($snapshot, $run, $status, $now, $thresholds);
            $items[] = $item;

            if ($status === EpmDataMartStatus::FAILED && $run instanceof EpmDataMartRecalculationRun) {
                $failedScopes[] = $this->failedScope($run, $snapshot);
            }
        }

        usort(
            $items,
            static fn (array $left, array $right): int => ($right['lag_minutes'] ?? -1) <=> ($left['lag_minutes'] ?? -1),
        );

        $status = $this->overallStatus($counts, $scopeKeys);
        $duration = $this->durationStats($runs);
        $lastSuccessAt = $this->lastSuccessAt($latestSnapshots);
        $lastAttemptAt = $this->lastAttemptAt($latestRuns);

        return [
            'status' => $status,
            'last_success_at' => $this->dateTime($lastSuccessAt),
            'last_attempt_at' => $this->dateTime($lastAttemptAt),
            'counts' => $counts,
            'duration_ms' => $duration,
            'staleness' => [
                'max_lag_minutes' => $this->maxLagMinutes($items),
                'stale_scopes_count' => $counts[EpmDataMartStatus::STALE],
                'slow_scopes_count' => $this->flaggedCount($items, 'is_slow'),
                'stuck_scopes_count' => $this->flaggedCount($items, 'is_stuck'),
                'items' => $items,
            ],
            'failed_scopes' => $failedScopes,
            'thresholds' => $this->publicThresholds($thresholds),
            'generated_at' => $now->toIso8601String(),
            'source_of_truth' => $this->sourceOfTruth(),
            'freshness' => [
                'status' => $status,
                'message' => $this->message($status),
                'last_success_at' => $this->dateTime($lastSuccessAt),
                'last_attempt_at' => $this->dateTime($lastAttemptAt),
                'source' => 'epm_data_mart',
            ],
        ];
    }

    private function applySnapshotFilters(Builder $query, array $filters): void
    {
        $reportScope = $this->reportScopeFilter($filters);
        if ($reportScope !== null) {
            $query->where('report_scope', $reportScope);
        }

        $projectId = $this->projectIdFilter($filters);
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $currency = $this->currency($filters['currency'] ?? null);
        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        foreach (['period_start', 'period_end', 'as_of_date'] as $field) {
            $value = $this->dateFilter($filters[$field] ?? null);
            if ($value !== null) {
                $query->whereDate($field, $value);
            }
        }
    }

    private function applyRunFilters(Builder $query, array $filters): void
    {
        $reportScope = $this->reportScopeFilter($filters);
        if ($reportScope !== null) {
            $query->where('report_scope', $reportScope);
        }
    }

    private function filterRunsInMemory(array $runs, array $filters): array
    {
        $projectId = $this->projectIdFilter($filters);
        $currency = $this->currency($filters['currency'] ?? null);
        $periodStart = $this->dateFilter($filters['period_start'] ?? null);
        $periodEnd = $this->dateFilter($filters['period_end'] ?? null);
        $asOfDate = $this->dateFilter($filters['as_of_date'] ?? null);

        if ($projectId === null && $currency === null && $periodStart === null && $periodEnd === null && $asOfDate === null) {
            return $runs;
        }

        return array_values(array_filter(
            $runs,
            function (EpmDataMartRecalculationRun $run) use ($projectId, $currency, $periodStart, $periodEnd, $asOfDate): bool {
                $runFilters = $this->arrayAttribute($run, 'filters');

                return ($projectId === null || (int) ($runFilters['project_id'] ?? 0) === $projectId)
                    && ($currency === null || $this->currency($runFilters['currency'] ?? null) === $currency)
                    && ($periodStart === null || (string) ($runFilters['period_start'] ?? '') === $periodStart)
                    && ($periodEnd === null || (string) ($runFilters['period_end'] ?? '') === $periodEnd)
                    && ($asOfDate === null || (string) ($runFilters['as_of_date'] ?? '') === $asOfDate);
            },
        ));
    }

    private function latestSnapshots(iterable $snapshots): array
    {
        $latest = [];

        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof EpmDataMartSnapshot) {
                continue;
            }

            $key = $this->scopeKey($snapshot);
            $current = $latest[$key] ?? null;

            if (!$current instanceof EpmDataMartSnapshot || $this->snapshotIsNewer($snapshot, $current)) {
                $latest[$key] = $snapshot;
            }
        }

        return $latest;
    }

    private function latestRuns(iterable $runs): array
    {
        $latest = [];

        foreach ($runs as $run) {
            if (!$run instanceof EpmDataMartRecalculationRun) {
                continue;
            }

            $key = $this->scopeKey($run);
            $current = $latest[$key] ?? null;

            if (!$current instanceof EpmDataMartRecalculationRun || $this->runIsNewer($run, $current)) {
                $latest[$key] = $run;
            }
        }

        return $latest;
    }

    private function scopeStatus(
        ?EpmDataMartSnapshot $snapshot,
        ?EpmDataMartRecalculationRun $run,
        CarbonInterface $now,
        array $thresholds,
    ): string {
        if ($run instanceof EpmDataMartRecalculationRun && EpmDataMartStatus::isActive((string) $run->status)) {
            return (string) $run->status;
        }

        if ($run instanceof EpmDataMartRecalculationRun && (string) $run->status === EpmDataMartStatus::FAILED) {
            if (!$snapshot instanceof EpmDataMartSnapshot || $this->runIsNewerThanSnapshot($run, $snapshot)) {
                return EpmDataMartStatus::FAILED;
            }
        }

        if (!$snapshot instanceof EpmDataMartSnapshot) {
            return EpmDataMartStatus::UNAVAILABLE;
        }

        return $this->snapshotStatus($snapshot, $now, $thresholds);
    }

    private function snapshotStatus(EpmDataMartSnapshot $snapshot, CarbonInterface $now, array $thresholds): string
    {
        if ((string) $snapshot->formula_version !== EpmDataMartPayloadProjector::FORMULA_VERSION) {
            return EpmDataMartStatus::STALE;
        }

        $staleAt = $this->modelDateTime($snapshot, 'stale_at');
        if ($staleAt instanceof CarbonInterface && $staleAt->lte($now)) {
            return EpmDataMartStatus::STALE;
        }

        $generatedAt = $this->modelDateTime($snapshot, 'generated_at');
        if (
            $generatedAt instanceof CarbonInterface
            && $this->minutesBetween($generatedAt, $now) > $thresholds['stale_after_minutes']
        ) {
            return EpmDataMartStatus::STALE;
        }

        return EpmDataMartStatus::normalize($snapshot->status);
    }

    private function scopeItem(
        ?EpmDataMartSnapshot $snapshot,
        ?EpmDataMartRecalculationRun $run,
        string $status,
        CarbonInterface $now,
        array $thresholds,
    ): array {
        $model = $snapshot instanceof EpmDataMartSnapshot ? $snapshot : $run;
        $generatedAt = $snapshot instanceof EpmDataMartSnapshot ? $this->modelDateTime($snapshot, 'generated_at') : null;
        $attemptAt = $run instanceof EpmDataMartRecalculationRun ? $this->runAttemptAt($run) : null;
        $lagBase = $generatedAt ?? $attemptAt;
        $durationMs = $run instanceof EpmDataMartRecalculationRun ? $this->positiveInt($run->duration_ms) : null;
        $isSlow = $durationMs !== null && $durationMs > $thresholds['slow_after_ms'];
        $isStuck = $run instanceof EpmDataMartRecalculationRun && $this->runIsStuck($run, $now, $thresholds);
        $filters = $run instanceof EpmDataMartRecalculationRun ? $this->arrayAttribute($run, 'filters') : [];

        return [
            'report_scope' => $this->stringAttribute($model, 'report_scope'),
            'report_label' => $this->reportLabel($this->stringAttribute($model, 'report_scope')),
            'scope_hash' => $this->stringAttribute($model, 'scope_hash'),
            'project_id' => $this->projectId($snapshot, $filters),
            'currency' => $this->currency($snapshot?->currency ?? ($filters['currency'] ?? null)),
            'period_start' => $this->dateValue($snapshot, 'period_start') ?? $this->nullableScalar($filters['period_start'] ?? null),
            'period_end' => $this->dateValue($snapshot, 'period_end') ?? $this->nullableScalar($filters['period_end'] ?? null),
            'as_of_date' => $this->dateValue($snapshot, 'as_of_date') ?? $this->nullableScalar($filters['as_of_date'] ?? null),
            'status' => $status,
            'freshness' => $status,
            'generated_at' => $this->dateTime($generatedAt),
            'stale_at' => $this->dateTime($snapshot instanceof EpmDataMartSnapshot ? $this->modelDateTime($snapshot, 'stale_at') : null),
            'last_attempt_at' => $this->dateTime($attemptAt),
            'lag_minutes' => $lagBase instanceof CarbonInterface ? $this->minutesBetween($lagBase, $now) : null,
            'threshold_minutes' => $thresholds['stale_after_minutes'],
            'duration_ms' => $durationMs,
            'duration_status' => $isSlow ? 'slow' : $this->durationStatus($run),
            'is_slow' => $isSlow,
            'is_stuck' => $isStuck,
            'message' => $this->message($status),
            'impact' => $this->impact($status),
            'report_href' => self::REPORT_HREFS[$this->stringAttribute($model, 'report_scope')] ?? null,
        ];
    }

    private function failedScope(EpmDataMartRecalculationRun $run, ?EpmDataMartSnapshot $snapshot): array
    {
        $filters = $this->arrayAttribute($run, 'filters');

        return [
            'report_scope' => (string) $run->report_scope,
            'report_label' => $this->reportLabel((string) $run->report_scope),
            'scope_hash' => (string) $run->scope_hash,
            'project_id' => $this->projectId($snapshot, $filters),
            'currency' => $this->currency($snapshot?->currency ?? ($filters['currency'] ?? null)),
            'failed_at' => $this->dateTime($this->modelDateTime($run, 'finished_at') ?? $this->runAttemptAt($run)),
            'reason' => $this->safeReason($run),
            'retryable' => $this->retryable($run),
        ];
    }

    private function durationStats(iterable $runs): array
    {
        $durations = [];
        $latestRunWithDuration = null;

        foreach ($runs as $run) {
            if (!$run instanceof EpmDataMartRecalculationRun) {
                continue;
            }

            $duration = $this->positiveInt($run->duration_ms);
            if ($duration === null) {
                continue;
            }

            $durations[] = $duration;

            if (
                !$latestRunWithDuration instanceof EpmDataMartRecalculationRun
                || $this->runIsNewer($run, $latestRunWithDuration)
            ) {
                $latestRunWithDuration = $run;
            }
        }

        return [
            'last' => $latestRunWithDuration instanceof EpmDataMartRecalculationRun
                ? $this->positiveInt($latestRunWithDuration->duration_ms)
                : null,
            'p50' => $this->percentile($durations, 0.50),
            'p95' => $this->percentile($durations, 0.95),
            'max' => $durations === [] ? null : max($durations),
        ];
    }

    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) max(0, min(count($values) - 1, (int) ceil(count($values) * $percentile) - 1));

        return $values[$index];
    }

    private function lastSuccessAt(array $snapshots): ?CarbonInterface
    {
        $latest = null;

        foreach ($snapshots as $snapshot) {
            if (
                !$snapshot instanceof EpmDataMartSnapshot
                || EpmDataMartStatus::normalize($snapshot->status) !== EpmDataMartStatus::SUCCEEDED
            ) {
                continue;
            }

            $generatedAt = $this->modelDateTime($snapshot, 'generated_at');
            if ($generatedAt instanceof CarbonInterface && (!$latest instanceof CarbonInterface || $generatedAt->gt($latest))) {
                $latest = $generatedAt;
            }
        }

        return $latest;
    }

    private function lastAttemptAt(array $runs): ?CarbonInterface
    {
        $latest = null;

        foreach ($runs as $run) {
            if (!$run instanceof EpmDataMartRecalculationRun) {
                continue;
            }

            $attemptAt = $this->runAttemptAt($run);
            if ($attemptAt instanceof CarbonInterface && (!$latest instanceof CarbonInterface || $attemptAt->gt($latest))) {
                $latest = $attemptAt;
            }
        }

        return $latest;
    }

    private function overallStatus(array $counts, array $scopeKeys): string
    {
        if ($scopeKeys === []) {
            return EpmDataMartStatus::UNAVAILABLE;
        }

        foreach ([
            EpmDataMartStatus::FAILED,
            EpmDataMartStatus::STALE,
            EpmDataMartStatus::PARTIAL,
            EpmDataMartStatus::RUNNING,
            EpmDataMartStatus::QUEUED,
            EpmDataMartStatus::SUCCEEDED,
        ] as $status) {
            if (($counts[$status] ?? 0) > 0) {
                return $status;
            }
        }

        return EpmDataMartStatus::UNAVAILABLE;
    }

    private function emptyCounts(): array
    {
        return [
            EpmDataMartStatus::QUEUED => 0,
            EpmDataMartStatus::RUNNING => 0,
            EpmDataMartStatus::SUCCEEDED => 0,
            EpmDataMartStatus::PARTIAL => 0,
            EpmDataMartStatus::STALE => 0,
            EpmDataMartStatus::FAILED => 0,
        ];
    }

    private function thresholds(): array
    {
        return [
            'stale_after_minutes' => max(1, (int) config('budgeting.epm_data_mart.stale_after_minutes', 120)),
            'slow_after_ms' => max(1, (int) config('budgeting.epm_data_mart.slow_after_ms', 30000)),
            'running_stuck_after_minutes' => max(1, (int) config('budgeting.epm_data_mart.running_stuck_after_minutes', 30)),
            'health_history_limit' => max(50, min(5000, (int) config('budgeting.epm_data_mart.health_history_limit', 1000))),
        ];
    }

    private function publicThresholds(array $thresholds): array
    {
        return [
            'stale_after_minutes' => $thresholds['stale_after_minutes'],
            'slow_after_ms' => $thresholds['slow_after_ms'],
            'running_stuck_after_minutes' => $thresholds['running_stuck_after_minutes'],
            'health_history_limit' => $thresholds['health_history_limit'],
        ];
    }

    private function sourceOfTruth(): array
    {
        return [
            'primary' => [
                'code' => 'prohelper',
                'label' => 'МОСТ',
                'role' => trans_message('epm_data_mart.health.source_of_truth.primary_role'),
            ],
            'external_confirmation' => [
                '1c' => [
                    'label' => '1С',
                    'role' => trans_message('epm_data_mart.health.source_of_truth.one_c_role'),
                    'stores_accounting_duplicate' => false,
                ],
            ],
            'excluded' => [
                trans_message('epm_data_mart.health.source_of_truth.excluded.accounting'),
                trans_message('epm_data_mart.health.source_of_truth.excluded.tax'),
                trans_message('epm_data_mart.health.source_of_truth.excluded.regulated_reporting'),
                trans_message('epm_data_mart.health.source_of_truth.excluded.payroll'),
            ],
        ];
    }

    private function snapshotIsNewer(EpmDataMartSnapshot $left, EpmDataMartSnapshot $right): bool
    {
        $leftDate = $this->modelDateTime($left, 'generated_at');
        $rightDate = $this->modelDateTime($right, 'generated_at');

        if ($leftDate instanceof CarbonInterface && $rightDate instanceof CarbonInterface && !$leftDate->equalTo($rightDate)) {
            return $leftDate->gt($rightDate);
        }

        return (int) ($left->id ?? 0) > (int) ($right->id ?? 0);
    }

    private function runIsNewer(EpmDataMartRecalculationRun $left, EpmDataMartRecalculationRun $right): bool
    {
        $leftDate = $this->runAttemptAt($left);
        $rightDate = $this->runAttemptAt($right);

        if ($leftDate instanceof CarbonInterface && $rightDate instanceof CarbonInterface && !$leftDate->equalTo($rightDate)) {
            return $leftDate->gt($rightDate);
        }

        return (int) ($left->id ?? 0) > (int) ($right->id ?? 0);
    }

    private function runIsNewerThanSnapshot(EpmDataMartRecalculationRun $run, EpmDataMartSnapshot $snapshot): bool
    {
        $attemptAt = $this->runAttemptAt($run);
        $generatedAt = $this->modelDateTime($snapshot, 'generated_at');

        return $attemptAt instanceof CarbonInterface
            && $generatedAt instanceof CarbonInterface
            && $attemptAt->gte($generatedAt);
    }

    private function runIsStuck(EpmDataMartRecalculationRun $run, CarbonInterface $now, array $thresholds): bool
    {
        if (!EpmDataMartStatus::isActive((string) $run->status)) {
            return false;
        }

        $startedAt = $this->modelDateTime($run, 'started_at') ?? $this->modelDateTime($run, 'queued_at');

        return $startedAt instanceof CarbonInterface
            && $this->minutesBetween($startedAt, $now) > $thresholds['running_stuck_after_minutes'];
    }

    private function runAttemptAt(EpmDataMartRecalculationRun $run): ?CarbonInterface
    {
        return $this->modelDateTime($run, 'finished_at')
            ?? $this->modelDateTime($run, 'started_at')
            ?? $this->modelDateTime($run, 'queued_at')
            ?? $this->modelDateTime($run, 'generated_at');
    }

    private function scopeKey(Model $model): string
    {
        return sprintf('%s:%s', $this->stringAttribute($model, 'report_scope'), $this->stringAttribute($model, 'scope_hash'));
    }

    private function reportLabel(string $scope): string
    {
        return match ($scope) {
            EpmDataMartScope::CFO_COMMAND_CENTER => trans_message('epm_data_mart.health.reports.cfo_command_center'),
            EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD => trans_message('epm_data_mart.health.reports.project_portfolio_dashboard'),
            EpmDataMartScope::PROJECT_MARGIN => trans_message('epm_data_mart.health.reports.project_margin'),
            EpmDataMartScope::WIP_FORECAST => trans_message('epm_data_mart.health.reports.wip_forecast'),
            EpmDataMartScope::PLAN_FACT => trans_message('epm_data_mart.health.reports.plan_fact'),
            EpmDataMartScope::CASH_GAP => trans_message('epm_data_mart.health.reports.cash_gap'),
            default => trans_message('epm_data_mart.health.reports.unknown'),
        };
    }

    private function message(string $status): string
    {
        return match ($status) {
            EpmDataMartStatus::QUEUED => trans_message('epm_data_mart.health.messages.queued'),
            EpmDataMartStatus::RUNNING => trans_message('epm_data_mart.health.messages.running'),
            EpmDataMartStatus::SUCCEEDED => trans_message('epm_data_mart.health.messages.succeeded'),
            EpmDataMartStatus::PARTIAL => trans_message('epm_data_mart.health.messages.partial'),
            EpmDataMartStatus::STALE => trans_message('epm_data_mart.health.messages.stale'),
            EpmDataMartStatus::FAILED => trans_message('epm_data_mart.health.messages.failed'),
            default => trans_message('epm_data_mart.health.messages.unavailable'),
        };
    }

    private function impact(string $status): string
    {
        return match ($status) {
            EpmDataMartStatus::FAILED => trans_message('epm_data_mart.health.impact.failed'),
            EpmDataMartStatus::STALE => trans_message('epm_data_mart.health.impact.stale'),
            EpmDataMartStatus::PARTIAL => trans_message('epm_data_mart.health.impact.partial'),
            EpmDataMartStatus::RUNNING => trans_message('epm_data_mart.health.impact.running'),
            EpmDataMartStatus::QUEUED => trans_message('epm_data_mart.health.impact.queued'),
            EpmDataMartStatus::SUCCEEDED => trans_message('epm_data_mart.health.impact.succeeded'),
            default => trans_message('epm_data_mart.health.impact.unavailable'),
        };
    }

    private function safeReason(EpmDataMartRecalculationRun $run): string
    {
        $summary = $this->arrayAttribute($run, 'error_summary');
        $message = is_string($summary['message'] ?? null) ? trim((string) $summary['message']) : '';

        if ($message === '') {
            return trans_message('epm_data_mart.health.reasons.recalculation_failed');
        }

        $normalized = mb_strtolower($message);
        foreach (self::FORBIDDEN_REASON_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return trans_message('epm_data_mart.health.reasons.recalculation_failed');
            }
        }

        return $message;
    }

    private function retryable(EpmDataMartRecalculationRun $run): bool
    {
        $summary = $this->arrayAttribute($run, 'error_summary');

        return is_bool($summary['retryable'] ?? null) ? (bool) $summary['retryable'] : true;
    }

    private function durationStatus(?EpmDataMartRecalculationRun $run): string
    {
        if (!$run instanceof EpmDataMartRecalculationRun) {
            return 'unknown';
        }

        return match ((string) $run->status) {
            EpmDataMartStatus::QUEUED => EpmDataMartStatus::QUEUED,
            EpmDataMartStatus::RUNNING => EpmDataMartStatus::RUNNING,
            default => 'normal',
        };
    }

    private function maxLagMinutes(array $items): ?int
    {
        $max = null;

        foreach ($items as $item) {
            $lag = $this->positiveInt($item['lag_minutes'] ?? null);
            if ($lag !== null && ($max === null || $lag > $max)) {
                $max = $lag;
            }
        }

        return $max;
    }

    private function flaggedCount(array $items, string $field): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (($item[$field] ?? false) === true) {
                $count++;
            }
        }

        return $count;
    }

    private function projectId(?EpmDataMartSnapshot $snapshot, array $filters): ?int
    {
        return $this->positiveInt($snapshot?->project_id ?? ($filters['project_id'] ?? null));
    }

    private function projectIdFilter(array $filters): ?int
    {
        return $this->positiveInt($filters['project_id'] ?? null)
            ?? $this->positiveInt($filters['current_project_id'] ?? null);
    }

    private function modelDateTime(?Model $model, string $attribute): ?CarbonInterface
    {
        if (!$model instanceof Model) {
            return null;
        }

        $value = $model->getRawOriginal($attribute);
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value);
        }

        $castValue = $model->getAttribute($attribute);
        if ($castValue instanceof CarbonInterface) {
            return $castValue;
        }

        return null;
    }

    private function dateTime(?CarbonInterface $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function dateValue(?Model $model, string $attribute): ?string
    {
        $date = $this->modelDateTime($model, $attribute);

        return $date instanceof CarbonInterface ? $date->toDateString() : null;
    }

    private function arrayAttribute(Model $model, string $attribute): array
    {
        $value = $model->getAttribute($attribute);

        return is_array($value) ? $value : [];
    }

    private function stringAttribute(?Model $model, string $attribute): string
    {
        $value = $model instanceof Model ? $model->getAttribute($attribute) : null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableScalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private function reportScopeFilter(array $filters): ?string
    {
        $value = is_string($filters['report_scope'] ?? null) ? trim((string) $filters['report_scope']) : '';
        if ($value === '') {
            return null;
        }

        $scope = mb_strtolower($value);

        return in_array($scope, EpmDataMartScope::SUPPORTED_REPORT_SCOPES, true) ? $scope : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function currency(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(trim($value));
    }

    private function dateFilter(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) max(0, round(($to->getTimestamp() - $from->getTimestamp()) / 60));
    }
}
