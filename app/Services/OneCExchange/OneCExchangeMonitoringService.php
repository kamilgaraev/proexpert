<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Enums\OneCExchangeStatus;
use App\Models\OneCExchangeMessage;
use App\Models\OneCExchangeOperation;
use App\Models\OneCExchangeToken;
use App\Services\OneCExchange\Support\OneCExchangeMonitoringFormatter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class OneCExchangeMonitoringService
{
    private const SUCCESS_STATUSES = [
        'delivered',
        'accepted',
        'posted',
        'completed',
    ];

    private const FAILURE_STATUSES = [
        'failed',
        'dead_letter',
        'rejected',
        'requires_mapping',
    ];

    private const BACKLOG_STATUSES = [
        'pending',
        'queued',
        'retry_scheduled',
    ];

    public function __construct(
        private readonly OneCExchangeJournalService $journal,
        private readonly OneCExchangeMonitoringFormatter $formatter,
        private readonly OneCExchangeIncidentRuleResolver $incidentResolver,
        private readonly OneCExchangeRunbookService $runbook,
    ) {
    }

    public function monitoring(int $organizationId, array $filters): array
    {
        $now = CarbonImmutable::now();
        $window = $this->window($filters, $now);
        $baseQuery = $this->operationQuery($organizationId, $filters);
        $statusCounts = $this->statusCounts($baseQuery);
        $staleProcessingCount = $this->staleProcessingCount($baseQuery, $now);
        $oldestPendingAgeMinutes = $this->oldestPendingAgeMinutes($baseQuery, $now);
        $latency = $this->latency($organizationId, $filters, $window['from'], $window['to']);
        $windowTotalCount = (clone $baseQuery)
            ->whereBetween('created_at', [$window['from'], $window['to']])
            ->count();
        $windowSuccessCount = $this->windowStatusCount($baseQuery, self::SUCCESS_STATUSES, $window['from'], $window['to']);
        $windowFailureCount = $this->windowStatusCount($baseQuery, self::FAILURE_STATUSES, $window['from'], $window['to']);
        $windowRetryCount = (clone $baseQuery)
            ->whereBetween('updated_at', [$window['from'], $window['to']])
            ->where(static function (Builder $query): void {
                $query
                    ->where('status', 'retry_scheduled')
                    ->orWhere('retry_count', '>', 0);
            })
            ->count();

        $summary = [
            ...$this->formatter->summary(
                configured: $this->configured($organizationId),
                deliveryEnabled: (bool) config('one_c_exchange.delivery.enabled', false),
                statusCounts: $statusCounts,
                staleProcessingCount: $staleProcessingCount,
                oldestPendingAgeMinutes: $oldestPendingAgeMinutes,
                lastSuccessAt: $this->lastStatusAt($baseQuery, self::SUCCESS_STATUSES),
                lastFailureAt: $this->lastStatusAt($baseQuery, self::FAILURE_STATUSES),
                windowTotalCount: $windowTotalCount,
                windowSuccessCount: $windowSuccessCount,
                windowFailureCount: $windowFailureCount,
                windowRetryCount: $windowRetryCount,
                avgDurationMs: $latency['avg_duration_ms'],
                p95DurationMs: $latency['p95_duration_ms'],
            ),
            'window_hours' => $window['window_hours'],
            'window_from' => $this->date($window['from']),
            'window_to' => $this->date($window['to']),
            'delivered_completed_window_count' => $this->windowStatusCount(
                $baseQuery,
                ['delivered', 'completed'],
                $window['from'],
                $window['to'],
            ),
            'failed_dead_letter_window_count' => $this->windowStatusCount(
                $baseQuery,
                ['failed', 'dead_letter'],
                $window['from'],
                $window['to'],
            ),
        ];
        $problemOperations = $this->problemOperations($baseQuery, $now);
        $incidents = $this->incidents($summary, $problemOperations, $now);

        return [
            'generated_at' => $this->date($now),
            'summary' => $summary,
            'status_counts' => $this->countRows($statusCounts, 'status'),
            'scope_counts' => $this->groupCounts($baseQuery, 'scope'),
            'direction_counts' => $this->groupCounts($baseQuery, 'direction'),
            'failure_types' => $this->failureTypes($baseQuery, $window['from'], $window['to']),
            'problem_operations' => $problemOperations,
            'incidents' => $incidents,
            'notification_summary' => $this->incidentResolver->summary($incidents, $now),
            'runbook' => $this->runbook->items(),
        ];
    }

    public function health(int $organizationId, array $filters): array
    {
        $now = CarbonImmutable::now();
        $baseQuery = $this->operationQuery($organizationId, $filters);
        $monitoring = $this->monitoring($organizationId, $filters);

        return [
            'summary' => $monitoring['summary'],
            'scopes' => $this->scopeMetrics(
                $baseQuery,
                $now,
                (bool) $monitoring['summary']['configured'],
                (bool) $monitoring['summary']['delivery_enabled'],
            ),
            'generated_at' => $monitoring['generated_at'],
        ];
    }

    public function metrics(int $organizationId, array $filters): array
    {
        $monitoring = $this->monitoring($organizationId, $filters);
        $summary = $monitoring['summary'];

        return [
            'status_counts' => $this->keyedCounts($monitoring['status_counts'], 'status'),
            'direction_counts' => $this->keyedCounts($monitoring['direction_counts'], 'direction'),
            'scope_metrics' => $this->scopeMetrics(
                $this->operationQuery($organizationId, $filters),
                CarbonImmutable::now(),
                (bool) $summary['configured'],
                (bool) $summary['delivery_enabled'],
            ),
            'window' => [
                'minutes' => ((int) $summary['window_hours']) * 60,
                'from' => $summary['window_from'],
                'to' => $summary['window_to'],
                'total_count' => (int) $summary['window_total_count'],
                'success_count' => (int) $summary['window_success_count'],
                'failure_count' => (int) $summary['window_failure_count'],
                'retry_count' => (int) $summary['window_retry_count'],
                'avg_duration_ms' => $summary['avg_duration_ms'],
                'p95_duration_ms' => $summary['p95_duration_ms'],
            ],
            'generated_at' => $monitoring['generated_at'],
        ];
    }

    private function operationQuery(int $organizationId, array $filters): Builder
    {
        return OneCExchangeOperation::query()
            ->where('organization_id', $organizationId)
            ->when($this->filter($filters, 'scope'), static fn (Builder $query, string $scope): Builder => $query->where('scope', $scope))
            ->when($this->filter($filters, 'direction'), static fn (Builder $query, string $direction): Builder => $query->where('direction', $direction));
    }

    private function statusCounts(Builder $baseQuery): array
    {
        $counts = array_fill_keys(
            array_map(static fn (OneCExchangeStatus $status): string => $status->value, OneCExchangeStatus::cases()),
            0
        );

        (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->each(static function (mixed $count, string $status) use (&$counts): void {
                $counts[$status] = (int) $count;
            });

        return $counts;
    }

    private function groupCounts(Builder $baseQuery, string $field): array
    {
        return (clone $baseQuery)
            ->selectRaw("{$field}, COUNT(*) as count")
            ->groupBy($field)
            ->orderByDesc('count')
            ->get()
            ->map(static fn (OneCExchangeOperation $row): array => [
                $field => (string) $row->getAttribute($field),
                'count' => (int) $row->getAttribute('count'),
            ])
            ->values()
            ->all();
    }

    private function countRows(array $counts, string $key): array
    {
        return collect($counts)
            ->map(static fn (int $count, string $value): array => [
                $key => $value,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    private function keyedCounts(array $rows, string $key): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($key, $row)) {
                continue;
            }

            $result[(string) $row[$key]] = (int) ($row['count'] ?? 0);
        }

        return $result;
    }

    private function scopeMetrics(
        Builder $baseQuery,
        CarbonImmutable $now,
        bool $configured,
        bool $deliveryEnabled,
    ): array
    {
        return (clone $baseQuery)
            ->selectRaw('scope, direction, COUNT(*) as count')
            ->groupBy('scope', 'direction')
            ->orderBy('scope')
            ->orderBy('direction')
            ->get()
            ->map(function (OneCExchangeOperation $row) use ($baseQuery, $now, $configured, $deliveryEnabled): array {
                $scope = (string) $row->getAttribute('scope');
                $direction = (string) $row->getAttribute('direction');
                $query = (clone $baseQuery)
                    ->where('scope', $scope)
                    ->where('direction', $direction);
                $statusCounts = $this->statusCounts($query);
                $staleProcessingCount = $this->staleProcessingCount($query, $now);
                $oldestPendingAgeMinutes = $this->oldestPendingAgeMinutes($query, $now);
                $summary = $this->formatter->summary(
                    configured: $configured,
                    deliveryEnabled: $deliveryEnabled,
                    statusCounts: $statusCounts,
                    staleProcessingCount: $staleProcessingCount,
                    oldestPendingAgeMinutes: $oldestPendingAgeMinutes,
                    lastSuccessAt: $this->lastStatusAt($query, self::SUCCESS_STATUSES),
                    lastFailureAt: $this->lastStatusAt($query, self::FAILURE_STATUSES),
                    windowTotalCount: (int) $row->getAttribute('count'),
                    windowSuccessCount: (clone $query)->whereIn('status', self::SUCCESS_STATUSES)->count(),
                    windowFailureCount: (clone $query)->whereIn('status', self::FAILURE_STATUSES)->count(),
                    windowRetryCount: (clone $query)
                        ->where(static function (Builder $retry): void {
                            $retry
                                ->where('status', 'retry_scheduled')
                                ->orWhere('retry_count', '>', 0);
                        })
                        ->count(),
                    avgDurationMs: null,
                    p95DurationMs: null,
                );

                return [
                    'scope' => $scope,
                    'direction' => $direction,
                    'total_count' => (int) $row->getAttribute('count'),
                    'health' => $summary['health'],
                    'pending_count' => $summary['pending_count'],
                    'queued_count' => $summary['queued_count'],
                    'processing_count' => $summary['processing_count'],
                    'retry_scheduled_count' => $summary['retry_scheduled_count'],
                    'failed_count' => $summary['failed_count'],
                    'dead_letter_count' => $summary['dead_letter_count'],
                    'requires_mapping_count' => $summary['requires_mapping_count'],
                    'stale_processing_count' => $summary['stale_processing_count'],
                    'backlog_count' => $summary['backlog_count'],
                    'oldest_pending_age_minutes' => $summary['oldest_pending_age_minutes'],
                    'last_success_at' => $summary['last_success_at'],
                    'last_failure_at' => $summary['last_failure_at'],
                ];
            })
            ->values()
            ->all();
    }

    private function staleProcessingCount(Builder $baseQuery, CarbonImmutable $now): int
    {
        return (clone $baseQuery)
            ->where('status', 'processing')
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $this->staleProcessingBefore($now))
            ->count();
    }

    private function oldestPendingAgeMinutes(Builder $baseQuery, CarbonImmutable $now): ?int
    {
        $oldestPendingAt = (clone $baseQuery)
            ->whereIn('status', self::BACKLOG_STATUSES)
            ->min('created_at');

        if ($oldestPendingAt === null) {
            return null;
        }

        return max(0, (int) CarbonImmutable::parse($oldestPendingAt)->diffInMinutes($now));
    }

    private function lastStatusAt(Builder $baseQuery, array $statuses): ?string
    {
        $value = (clone $baseQuery)
            ->whereIn('status', $statuses)
            ->selectRaw('MAX(COALESCE(finished_at, updated_at)) as value')
            ->value('value');

        return $this->date($value);
    }

    private function windowStatusCount(
        Builder $baseQuery,
        array $statuses,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        return (clone $baseQuery)
            ->whereIn('status', $statuses)
            ->whereBetween('updated_at', [$from, $to])
            ->count();
    }

    private function latency(int $organizationId, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = OneCExchangeMessage::query()
            ->join('one_c_exchange_operations', 'one_c_exchange_operations.id', '=', 'one_c_exchange_messages.operation_id')
            ->where('one_c_exchange_messages.organization_id', $organizationId)
            ->whereNotNull('one_c_exchange_messages.duration_ms')
            ->whereBetween('one_c_exchange_messages.created_at', [$from, $to])
            ->when($this->filter($filters, 'scope'), static fn (Builder $query, string $scope): Builder => $query->where('one_c_exchange_operations.scope', $scope))
            ->when($this->filter($filters, 'direction'), static fn (Builder $query, string $direction): Builder => $query->where('one_c_exchange_operations.direction', $direction));

        $count = (clone $query)->count();

        if ($count === 0) {
            return ['avg_duration_ms' => null, 'p95_duration_ms' => null];
        }

        return [
            'avg_duration_ms' => (float) (clone $query)->avg('one_c_exchange_messages.duration_ms'),
            'p95_duration_ms' => $count > 1 ? $this->p95Duration($query) : null,
        ];
    }

    private function p95Duration(Builder $query): ?float
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $value = (clone $query)
                ->selectRaw('percentile_cont(0.95) within group (order by one_c_exchange_messages.duration_ms) as value')
                ->value('value');

            return $value === null ? null : (float) $value;
        }

        $durations = (clone $query)
            ->orderBy('one_c_exchange_messages.duration_ms')
            ->pluck('one_c_exchange_messages.duration_ms')
            ->map(static fn (mixed $value): int => (int) $value)
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $index = max(0, (int) ceil($durations->count() * 0.95) - 1);

        return (float) $durations->get($index);
    }

    private function failureTypes(Builder $baseQuery, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return (clone $baseQuery)
            ->whereBetween('updated_at', [$from, $to])
            ->where(static function (Builder $query): void {
                $query
                    ->whereNotNull('failure_type')
                    ->orWhereNotNull('safe_error_code');
            })
            ->selectRaw('failure_type, safe_error_code, COUNT(*) as count')
            ->groupBy('failure_type', 'safe_error_code')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(static fn (OneCExchangeOperation $row): array => [
                'failure_type' => $row->failure_type,
                'safe_error_code' => $row->safe_error_code,
                'count' => (int) $row->getAttribute('count'),
            ])
            ->values()
            ->all();
    }

    private function problemOperations(Builder $baseQuery, CarbonImmutable $now): array
    {
        $staleProcessingBefore = $this->staleProcessingBefore($now);

        return (clone $baseQuery)
            ->withCount('messages')
            ->where(static function (Builder $query) use ($now, $staleProcessingBefore): void {
                $query
                    ->whereIn('status', ['failed', 'dead_letter', 'requires_mapping'])
                    ->orWhere(static function (Builder $processing) use ($staleProcessingBefore): void {
                        $processing
                            ->where('status', 'processing')
                            ->whereNotNull('started_at')
                            ->where('started_at', '<=', $staleProcessingBefore);
                    })
                    ->orWhere(static function (Builder $retry) use ($now): void {
                        $retry
                            ->where('status', 'retry_scheduled')
                            ->whereNotNull('next_retry_at')
                            ->where('next_retry_at', '<', $now);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'dead_letter' THEN 0 WHEN status = 'failed' THEN 1 WHEN status = 'requires_mapping' THEN 2 ELSE 3 END")
            ->orderBy('updated_at')
            ->limit(20)
            ->get()
            ->map(function (OneCExchangeOperation $operation) use ($now, $staleProcessingBefore): array {
                $payload = $this->journal->operationPayload($operation);
                $reason = $this->problemReason($operation, $now, $staleProcessingBefore);

                return [
                    ...$payload,
                    'problem_reason' => $reason,
                    'problem_label' => $this->problemLabel($reason),
                    'incident' => $this->incidentResolver->resolveOperation($operation, $now, $reason),
                ];
            })
            ->values()
            ->all();
    }

    private function incidents(array $summary, array $problemOperations, CarbonImmutable $now): array
    {
        $incidents = [];
        $systemIncident = $this->incidentResolver->resolveSystem(
            configured: (bool) ($summary['configured'] ?? false),
            deliveryEnabled: (bool) ($summary['delivery_enabled'] ?? false),
            now: $now,
        );

        if ($systemIncident !== null) {
            $incidents[] = $systemIncident;
        }

        foreach ($problemOperations as $operation) {
            if (is_array($operation) && is_array($operation['incident'] ?? null)) {
                $incidents[] = $operation['incident'];
            }
        }

        usort($incidents, static function (array $left, array $right): int {
            $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $leftRank = $severityRank[(string) ($left['severity'] ?? 'info')] ?? 2;
            $rightRank = $severityRank[(string) ($right['severity'] ?? 'info')] ?? 2;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) ($left['response_deadline_at'] ?? ''), (string) ($right['response_deadline_at'] ?? ''));
        });

        return array_values($incidents);
    }

    private function problemReason(
        OneCExchangeOperation $operation,
        CarbonImmutable $now,
        CarbonImmutable $staleProcessingBefore,
    ): string {
        if ($operation->status === 'dead_letter') {
            return 'dead_letter';
        }

        if ($operation->status === 'failed') {
            return 'failed';
        }

        if ($operation->status === 'requires_mapping') {
            return 'requires_mapping';
        }

        if (
            $operation->status === 'processing'
            && $operation->started_at !== null
            && CarbonImmutable::parse($operation->started_at)->lessThanOrEqualTo($staleProcessingBefore)
        ) {
            return 'stale_processing';
        }

        if (
            $operation->status === 'retry_scheduled'
            && $operation->next_retry_at !== null
            && CarbonImmutable::parse($operation->next_retry_at)->lessThan($now)
        ) {
            return 'overdue_retry';
        }

        return 'attention_required';
    }

    private function problemLabel(string $reason): string
    {
        return trans_message("one_c_exchange.problem_labels.{$reason}");
    }

    private function configured(int $organizationId): bool
    {
        return OneCExchangeToken::query()
            ->where('organization_id', $organizationId)
            ->whereNull('revoked_at')
            ->exists();
    }

    private function window(array $filters, CarbonImmutable $now): array
    {
        $windowHours = max(1, min(720, (int) ($filters['window_hours'] ?? 24)));
        $to = $this->dateTime($filters['to'] ?? null) ?? $now;
        $from = $this->dateTime($filters['from'] ?? null) ?? $to->subHours($windowHours);

        return [
            'from' => $from,
            'to' => $to,
            'window_hours' => $windowHours,
        ];
    }

    private function staleProcessingBefore(CarbonImmutable $now): CarbonImmutable
    {
        $timeoutMinutes = max(1, (int) config('one_c_exchange.delivery.processing_timeout_minutes', 15));

        return $now->subMinutes($timeoutMinutes);
    }

    private function filter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        return $this->dateTime($value)?->toJSON();
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
