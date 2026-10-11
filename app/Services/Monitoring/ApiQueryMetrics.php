<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;

final class ApiQueryMetrics
{
    public const REQUEST_ATTRIBUTE = 'most.api_query_metrics';

    private const MAX_SOURCE_GROUPS = 32;

    private const MAX_VARIANTS = 16;

    private int $count = 0;

    private float $total = 0;

    private float $maximum = 0;

    private array $groups = [];

    private array $sources = [];

    private int $sourcesDropped = 0;

    private float $sourceCaptureMilliseconds = 0;

    private array $processingPhases = [];

    private ?array $assistantSnapshot = null;

    private ?array $assistantSnapshotEpoch = null;

    public function __construct(private readonly bool $captureSources = false) {}

    public static function recordQuery(Request $request, QueryExecuted $query): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite((float) $query->time) || $query->time < 0) {
            return;
        }
        self::record($request, (float) $query->time);
        $sql = strtolower($query->sql);
        $group = match (true) {
            str_starts_with($sql, 'set '), str_contains($sql, "set_config('statement_timeout'") => 'settings',
            str_contains($sql, 'pg_catalog'), str_contains($sql, 'information_schema'), str_contains($sql, 'from pg_attribute ') => 'schema',
            str_contains($sql, 'stored_count'), str_contains($sql, 'expected_count') => 'rag_counts',
            preg_match('/\b(?:from|join)\s+"?role_conditions\b/', $sql) === 1 => 'role_conditions',
            preg_match('/\b(?:from|join)\s+"?authorization_contexts\b/', $sql) === 1 => 'contexts',
            preg_match('/\b(?:from|join)\s+"?ai_rag_sources\b/', $sql) === 1 => 'rag_sources',
            preg_match('/\b(?:from|join)\s+"?(?:files|ai_assistant_documents)\b/', $sql) === 1 => 'documents',
            default => 'other',
        };
        $metrics->groups[$group]['count'] = ($metrics->groups[$group]['count'] ?? 0) + 1;
        $metrics->groups[$group]['total_ms'] = ($metrics->groups[$group]['total_ms'] ?? 0) + (float) $query->time;
        if ($metrics->captureSources) {
            $sourceStartedAt = hrtime(true);
            try {
                $metrics->recordSource($query, $group);
            } catch (\Throwable) {
                $metrics->sourcesDropped++;
            } finally {
                $metrics->sourceCaptureMilliseconds += (hrtime(true) - $sourceStartedAt) / 1_000_000;
            }
        }
    }

    public static function record(Request $request, float $milliseconds): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }

        $metrics->count++;
        $metrics->total += $milliseconds;
        $metrics->maximum = max($metrics->maximum, $milliseconds);
    }

    public static function measureProcessingPhase(string $phase, callable $operation): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $checkpoint = $request instanceof Request ? self::processingCheckpoint($request) : null;
        if ($checkpoint === null) { return $operation(); }

        try {
            return $operation();
        } finally {
            self::recordProcessingPhase($request, $phase, $checkpoint['started_at'], $checkpoint);
        }
    }

    public static function processingCheckpoint(Request $request): ?array
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! $metrics->captureSources) {
            return null;
        }

        return ['metrics' => $metrics, 'started_at' => hrtime(true), 'sql_count' => $metrics->count,
            'sql_total_ms' => $metrics->total, 'process_cpu_ms' => self::processCpuMilliseconds()];
    }

    public static function recordProcessingPhase(Request $request, string $phase, int $startedAt, ?array $checkpoint = null): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! $metrics->captureSources || $startedAt < 0 || ! in_array($phase, [
            'list_prepare', 'list_encode', 'request_render', 'request_chain',
            'order_render', 'order_workflow', 'order_payment', 'order_chain',
            'admin_authorize', 'purchase_authorize', 'interface_access', 'procurement_modules', 'response_normalize',
            'rag_prepare', 'rag_schema_prefetch', 'rag_source_prepare', 'rag_source_acl', 'rag_source_counts',
            'rag_expected_counts', 'rag_documents', 'rag_finalize',
            'sql_tracing',
            'current_access_check', 'role_catalog', 'rag_acl_discovery', 'rag_acl_batch_compile', 'rag_acl_finish',
            'current_access_evaluate', 'rag_acl_entity_build', 'rag_acl_register',
        ], true)) {
            return;
        }
        if ($checkpoint !== null && (($checkpoint['metrics'] ?? null) !== $metrics
            || ($checkpoint['started_at'] ?? null) !== $startedAt
            || ! is_int($checkpoint['sql_count'] ?? null) || $checkpoint['sql_count'] < 0 || $checkpoint['sql_count'] > $metrics->count
            || ! is_float($checkpoint['sql_total_ms'] ?? null) || ! is_finite($checkpoint['sql_total_ms'])
            || $checkpoint['sql_total_ms'] < 0 || $checkpoint['sql_total_ms'] > $metrics->total)) {
            return;
        }
        $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        if (! is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }
        $metrics->processingPhases[$phase]['count'] = ($metrics->processingPhases[$phase]['count'] ?? 0) + 1;
        $metrics->processingPhases[$phase]['total_ms'] = ($metrics->processingPhases[$phase]['total_ms'] ?? 0) + $milliseconds;
        $metrics->processingPhases[$phase]['max_ms'] = max($metrics->processingPhases[$phase]['max_ms'] ?? 0, $milliseconds);
        if ($checkpoint !== null) {
            $metrics->processingPhases[$phase]['sql_count'] = ($metrics->processingPhases[$phase]['sql_count'] ?? 0) + $metrics->count - $checkpoint['sql_count'];
            $metrics->processingPhases[$phase]['sql_total_ms'] = ($metrics->processingPhases[$phase]['sql_total_ms'] ?? 0) + $metrics->total - $checkpoint['sql_total_ms'];
            $cpu = self::processCpuMilliseconds();
            $startedCpu = $checkpoint['process_cpu_ms'] ?? null;
            if ($cpu !== null && is_float($startedCpu) && is_finite($startedCpu) && $startedCpu >= 0 && $cpu >= $startedCpu) {
                $metrics->processingPhases[$phase]['process_cpu_ms'] = ($metrics->processingPhases[$phase]['process_cpu_ms'] ?? 0) + $cpu - $startedCpu;
            }
        }
    }

    public function summary(): array
    {
        $groups = $this->groups;
        foreach ($groups as &$group) {
            $group['total_ms'] = round($group['total_ms'], 2);
        }

        $summary = [
            'sql_count' => $this->count,
            'sql_total_ms' => round($this->total, 2),
            'sql_max_ms' => round($this->maximum, 2),
            'sql_groups' => $groups,
        ];
        if ($this->captureSources) {
            $summary['sql_sources'] = array_map(static function (array $source): array {
                $source['total_ms'] = round($source['total_ms'], 2);
                $source['max_ms'] = round($source['max_ms'], 2);
                $source['distinct_variants'] = count($source['variants']);
                unset($source['variants']);

                return $source;
            }, array_values($this->sources));
            $summary['sql_sources_dropped_count'] = $this->sourcesDropped;
            $summary['sql_source_capture_ms'] = round($this->sourceCaptureMilliseconds, 2);
            $summary['processing_phases'] = array_map(static function (array $phase): array {
                $phase['total_ms'] = round($phase['total_ms'], 2);
                $phase['max_ms'] = round($phase['max_ms'], 2);
                if (isset($phase['sql_total_ms'])) {
                    $phase['sql_total_ms'] = round($phase['sql_total_ms'], 2);
                }
                if (isset($phase['process_cpu_ms'])) {
                    $phase['process_cpu_ms'] = round($phase['process_cpu_ms'], 2);
                }

                return $phase;
            }, $this->processingPhases);
        }
        if ($this->assistantSnapshot !== null) {
            $summary['assistant_snapshot'] = $this->assistantSnapshot;
        }
        if ($this->assistantSnapshotEpoch !== null) {
            $summary['assistant_snapshot_epoch'] = $this->assistantSnapshotEpoch;
        }

        return $summary;
    }

    public function recordAssistantSnapshot(array $context): void
    {
        if (! in_array($context['phase'] ?? null, ['missing_release', 'ready', 'snapshot_rejected', 'snapshot_missing', 'fresh_read', 'fresh_read_unavailable'], true)
            || ! in_array($context['section'] ?? null, ['all', 'sources', 'documents'], true)) {
            return;
        }
        $snapshot = ['phase' => $context['phase'], 'section' => $context['section']];
        foreach (['release_sha' => 40, 'key_hash' => 64, 'cache_namespace_hash' => 64, 'queue_namespace_hash' => 64] as $field => $length) {
            $value = $context[$field] ?? null;
            $snapshot[$field] = is_string($value) && preg_match('/^[a-f0-9]{'.$length.'}$/D', $value) === 1 ? $value : null;
        }
        $age = $context['age_seconds'] ?? null;
        $snapshot['age_seconds'] = is_int($age) && $age >= 0 ? $age : null;
        $queued = $context['refresh_queued'] ?? null;
        $snapshot['refresh_queued'] = is_bool($queued) ? $queued : null;
        $this->assistantSnapshot = $snapshot;
    }

    private static function processCpuMilliseconds(): ?float
    {
        if (! function_exists('getrusage')) { return null; }
        try {
            $usage = getrusage();
            foreach (['ru_utime.tv_sec', 'ru_utime.tv_usec', 'ru_stime.tv_sec', 'ru_stime.tv_usec'] as $field) {
                if (! is_int($usage[$field] ?? null) || $usage[$field] < 0) { return null; }
            }
            $milliseconds = ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000.0
                + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000.0;

            return is_finite($milliseconds) ? $milliseconds : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function recordAssistantSnapshotEpoch(string $phase, ?string $relation): void
    {
        if (! in_array($phase, ['state_rejected', 'transaction_rejected', 'schema_rejected', 'valid', 'relation_mutation_present', 'epoch_guard_rejected'], true)) {
            return;
        }
        $this->assistantSnapshotEpoch = [
            'phase' => $phase,
            'relation' => $relation !== null && strlen($relation) <= 63 && preg_match('/^[a-z_][a-z0-9_]*$/D', $relation) === 1 ? $relation : null,
        ];
    }

    private function recordSource(QueryExecuted $query, string $group): void
    {
        $source = SlowQueryContext::source(['app/Services/Monitoring/ApiQueryMetrics.php', 'app/Providers/TracingServiceProvider.php']);
        $key = $group.'|'.($source['file'] ?? '').'|'.($source['line'] ?? '');
        if (! isset($this->sources[$key])) {
            if (count($this->sources) >= self::MAX_SOURCE_GROUPS) {
                $this->sourcesDropped++;

                return;
            }
            $this->sources[$key] = ['group' => $group, 'source' => $source, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0,
                'variants' => [], 'distinct_variants_capped' => false, 'unclassified_variants' => 0];
        }
        $record = &$this->sources[$key];
        $record['count']++;
        $record['total_ms'] += (float) $query->time;
        $record['max_ms'] = max($record['max_ms'], (float) $query->time);
        $variant = self::variant($query);
        if ($variant === null) {
            $record['unclassified_variants']++;

            return;
        }
        if (isset($record['variants'][$variant])) {
            return;
        }
        if (count($record['variants']) >= self::MAX_VARIANTS) {
            $record['distinct_variants_capped'] = true;

            return;
        }
        $record['variants'][$variant] = true;
    }

    private static function variant(QueryExecuted $query): ?string
    {
        $bytes = strlen($query->sql) + 1;
        if ($bytes > 32769 || count($query->bindings) > 1024) {
            return null;
        }
        $hash = hash_init('sha256');
        hash_update($hash, $query->sql."\0");
        foreach ($query->bindings as $key => $value) {
            if ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))) {
                return null;
            }
            if ((is_string($value) && strlen($value) > 4096) || (is_string($key) && strlen($key) > 4096)) {
                return null;
            }
            $part = serialize($key).serialize($value)."\0";
            $bytes += strlen($part);
            if ($bytes > 65536) {
                return null;
            }
            hash_update($hash, $part);
        }

        return hash_final($hash);
    }
}
