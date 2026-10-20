<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantStatusSnapshotDiagnostics
{
    public const EPOCH_CHECKS = [
        'input_organization', 'input_relations', 'transaction_context', 'transaction_mode',
        'global_change_table', 'global_control_table', 'global_mutation_function', 'global_change_columns',
        'global_control_columns', 'global_required_relations', 'global_name_resolution', 'global_relation_shapes',
        'global_dependency_functions', 'global_mutation_triggers', 'scoped_prerequisites', 'scoped_source_columns',
        'scoped_relation_count', 'scoped_control_body', 'scoped_change_relation', 'scoped_source_relations',
        'scoped_change_columns', 'scoped_change_primary_key', 'scoped_row_function', 'scoped_witness_function',
        'scoped_witness_not_public', 'scoped_witness_owner_access', 'scoped_truncate_function', 'scoped_triggers', 'control_row',
    ];

    public static function request(string $phase, string $section, ?string $cacheKey = null, ?int $age = null, ?bool $queued = null): void
    {
        try {
            $metrics = request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE);
            if ($metrics instanceof ApiQueryMetrics) {
                $metrics->recordAssistantSnapshot(self::context($phase, $section, $cacheKey) + [
                    'age_seconds' => $age,
                    'refresh_queued' => $queued,
                ]);
            }
        } catch (Throwable) {
        }
    }

    public static function refresh(string $phase, string $section, string $cacheKey, ?string $expectedKey = null, ?string $reason = null, array $epochChecks = []): void
    {
        if (! in_array($phase, ['started', 'failed', 'snapshot_unproven', 'snapshot_expired_or_changed', 'cache_key_mismatch', 'written'], true)
            || ! in_array($section, ['all', 'sources', 'documents'], true)) {
            return;
        }
        try {
            $context = self::context($phase, $section, $cacheKey) + [
                'expected_key_hash' => $expectedKey === null ? null : hash('sha256', $expectedKey),
                'reason' => $phase === 'snapshot_unproven' ? self::failureReason($reason) : null,
            ];
            if ($phase === 'snapshot_unproven' && $context['reason'] === 'unproven_database_epoch') {
                $context['epoch_checks'] = self::epochChecks($epochChecks);
            }
            Log::channel('api_latency')->info('assistant_status_snapshot_refresh', $context);
        } catch (Throwable) {
        }
    }

    public static function epochChecks(array $checks): array
    {
        $safe = [];
        foreach (self::EPOCH_CHECKS as $name) {
            if (array_key_exists($name, $checks) && is_bool($checks[$name])) { $safe[$name] = $checks[$name]; }
        }

        return $safe;
    }

    public static function coverageRefresh(string $phase, bool $global, ?float $durationMs = null, ?bool $countsKnown = null, ?bool $generationPresent = null, ?Throwable $exception = null): void
    {
        if (! in_array($phase, ['queued', 'queue_failed', 'started', 'finished', 'failed', 'terminal_failed', 'lease_metadata_failed'], true)) {
            return;
        }
        try {
            $exceptionClass = $exception === null ? null : $exception::class;
            Log::channel('api_latency')->info('assistant_rag_coverage_refresh', [
                'phase' => $phase,
                'scope' => $global ? 'global' : 'filtered',
                'release_sha' => self::releaseSha(),
                'duration_ms' => $durationMs !== null && is_finite($durationMs) && $durationMs >= 0 ? round($durationMs, 2) : null,
                'counts_known' => $countsKnown,
                'generation_present' => $generationPresent,
                'exception_class' => $exceptionClass === null || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,199}$/D', $exceptionClass) === 1 ? $exceptionClass : 'unclassified',
            ]);
        } catch (Throwable) {
        }
    }

    private static function releaseSha(): ?string
    {
        $release = config('ai-assistant.status_snapshot_release') ?: getenv('MOST_RELEASE_SHA');

        return is_string($release) && preg_match('/^[a-f0-9]{40}$/D', $release) === 1 ? $release : null;
    }

    private static function failureReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $category = explode(':', $reason, 2)[0];

        return in_array($category, ['connection_set_changed', 'unsupported_connection', 'unsupported_statement',
            'unsupported_sql_literal', 'unsupported_input_type', 'unsupported_function', 'unsupported_schema',
            'plan_unavailable', 'access_inputs_changed', 'unproven_dependencies', 'unproven_database_epoch'], true)
            ? $category : 'unclassified';
    }

    public static function epoch(string $phase, ?string $relation = null): void
    {
        try {
            $metrics = request()->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE);
            if ($metrics instanceof ApiQueryMetrics) {
                $metrics->recordAssistantSnapshotEpoch($phase, $relation);
            }
        } catch (Throwable) {
        }
    }

    private static function context(string $phase, string $section, ?string $cacheKey): array
    {
        $store = (string) config('cache.default');
        $cache = config('cache.stores.'.$store, []);
        $cacheConnection = is_array($cache) ? ($cache['connection'] ?? null) : null;
        $queueConnection = config('queue.connections.redis.connection');

        return [
            'phase' => $phase,
            'section' => $section,
            'release_sha' => self::releaseSha(),
            'key_hash' => $cacheKey === null ? null : hash('sha256', $cacheKey),
            'cache_namespace_hash' => hash('sha256', serialize([
                $store, is_array($cache) ? ($cache['driver'] ?? null) : null,
                is_array($cache) ? ($cache['prefix'] ?? config('cache.prefix')) : config('cache.prefix'),
                self::redisNamespace($cacheConnection),
            ])),
            'queue_namespace_hash' => hash('sha256', serialize([
                config('queue.connections.redis.driver'), 'default',
                self::redisNamespace($queueConnection),
            ])),
        ];
    }

    private static function redisNamespace(mixed $connection): ?array
    {
        $connection ??= 'default';
        if (! is_string($connection)) {
            return null;
        }
        $configuration = (new ConfigurationUrlParser)->parseConfiguration(config('database.redis.'.$connection, []));
        $options = array_merge(config('database.redis.options', []), $configuration['options'] ?? []);

        return [
            config('database.redis.client'), $configuration['prefix'] ?? ($options['prefix'] ?? null),
            $configuration['scheme'] ?? ($configuration['driver'] ?? null), $configuration['host'] ?? null,
            $configuration['port'] ?? null, $configuration['database'] ?? null,
        ];
    }
}
