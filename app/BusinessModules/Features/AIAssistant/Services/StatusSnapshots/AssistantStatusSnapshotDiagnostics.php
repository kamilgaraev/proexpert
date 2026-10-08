<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantStatusSnapshotDiagnostics
{
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

    public static function refresh(string $phase, string $section, string $cacheKey, ?string $expectedKey = null): void
    {
        if (! in_array($phase, ['started', 'failed', 'snapshot_unproven', 'snapshot_expired_or_changed', 'cache_key_mismatch', 'written'], true)
            || ! in_array($section, ['all', 'sources', 'documents'], true)) {
            return;
        }
        try {
            Log::channel('api_latency')->info('assistant_status_snapshot_refresh', self::context($phase, $section, $cacheKey) + [
                'expected_key_hash' => $expectedKey === null ? null : hash('sha256', $expectedKey),
            ]);
        } catch (Throwable) {
        }
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
        $release = config('ai-assistant.status_snapshot_release') ?: getenv('MOST_RELEASE_SHA');
        $store = (string) config('cache.default');
        $cache = config('cache.stores.'.$store, []);
        $cacheConnection = is_array($cache) ? ($cache['connection'] ?? null) : null;
        $queueConnection = config('queue.connections.redis.connection');

        return [
            'phase' => $phase,
            'section' => $section,
            'release_sha' => is_string($release) && preg_match('/^[a-f0-9]{40}$/D', $release) === 1 ? $release : null,
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
