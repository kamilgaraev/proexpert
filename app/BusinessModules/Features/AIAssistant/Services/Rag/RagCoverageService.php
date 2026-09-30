<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshRagCoverageJob;
use Throwable;
use Illuminate\Support\Str;
use RuntimeException;

final class RagCoverageService
{
    public function __construct(private readonly RagSourceRegistry $registry, private readonly RagIndexer $indexer, private readonly ?AssistantDataAccessPolicy $access = null, private readonly ?RagExpectedSourceProjection $projection = null) {}

    public function coverageForActor(int $organizationId, User $actor, ?callable $checkpoint = null): array
    {
        if ($checkpoint !== null) { $checkpoint(); }
        $policy = $this->access ?? app(AssistantDataAccessPolicy::class);
        $enabledTypes = $this->registry->enabledSourceTypes();
        $allowedTypes = array_values(array_intersect($enabledTypes, $policy->allowedSourceTypes($actor, $organizationId)));
        if ($checkpoint !== null) { $checkpoint(); }
        $sources = $policy->applyToSources(RagSource::query(), $actor, $organizationId)
            ->whereIn('ai_rag_sources.source_type', $allowedTypes);
        $projects = $policy->entityQuery($actor, $organizationId, 'project');
        $sources->where(static function (Builder $scope) use ($projects): void {
            $scope->where('ai_rag_sources.source_type', 'file_document')->orWhereNull('ai_rag_sources.project_id');
            if ($projects !== null) {
                $scope->orWhereIn('ai_rag_sources.project_id', $projects->select('projects.id'));
            }
        });
        $scoped = $sources->select(['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.project_id'])->toBase();
        if ($checkpoint !== null) { $checkpoint(); }
        $counts = DB::query()->fromSub($scoped, 'accessible_sources')
            ->leftJoin('ai_rag_chunks as accessible_chunks', static function (JoinClause $join) use ($organizationId): void {
                $join->on('accessible_chunks.source_id', '=', 'accessible_sources.id')
                    ->where('accessible_chunks.organization_id', $organizationId)
                    ->whereRaw('accessible_chunks.project_id IS NOT DISTINCT FROM accessible_sources.project_id');
            })->groupBy('accessible_sources.source_type')
            ->selectRaw('accessible_sources.source_type, COUNT(DISTINCT accessible_sources.id) AS stored_count, COUNT(accessible_chunks.id) AS chunk_count, COUNT(DISTINCT CASE WHEN accessible_chunks.embedding IS NOT NULL THEN accessible_sources.id END) AS indexed_count')
            ->get()->keyBy('source_type');
        $catalog = [];
        $stored = 0;
        $indexed = 0;
        $chunks = 0;
        foreach ($allowedTypes as $type) {
            if ($checkpoint !== null) { $checkpoint(); }
            $count = $counts->get($type);
            $typeStored = (int) ($count->stored_count ?? 0);
            $typeIndexed = (int) ($count->indexed_count ?? 0);
            $stored += $typeStored;
            $indexed += $typeIndexed;
            $chunks += (int) ($count->chunk_count ?? 0);
            $catalog[] = ['type' => $type, 'enabled' => true, 'expected_count' => null, 'indexed_count' => $typeIndexed,
                'stored_count' => $typeStored, 'stale_count' => null, 'pending_count' => null, 'error' => null];
        }
        $enabled = (bool) config('ai-assistant.rag.enabled', true);
        $status = ['enabled' => $enabled, 'ready' => $enabled && $indexed > 0,
            'source_count' => $stored, 'chunk_count' => $chunks, 'stored_source_count' => $stored, 'indexed_source_count' => $indexed,
            'expected_source_count' => null, 'pending_source_count' => null, 'stale_source_count' => null,
            'eligible_count_known' => false, 'coverage_complete' => false, 'processing' => false,
            'lag_seconds' => null, 'lag_goal_seconds' => 300, 'lag_exceeded' => false, 'snapshot_at' => null,
            'latest_run' => null, 'last_successful_run' => null, 'last_failed_run' => null, 'source_catalog' => $catalog];
        $key = $this->key($organizationId, null, null);
        $snapshot = Cache::get($key);
        if ((! is_array($snapshot) || (isset($snapshot['projection_generation']) && ! $this->usableProjection($snapshot, $enabledTypes)))
            && $enabled && $allowedTypes !== []) {
            $this->queueRefresh($organizationId, null, null, $key);
        }
        if (is_array($snapshot) && $this->usableProjection($snapshot, $enabledTypes)) {
            $projection = $this->projection ?? new RagExpectedSourceProjection($this->indexer);
            $expectedCounts = $projection->actorCounts($organizationId, $actor, $policy, $allowedTypes, $snapshot['projection_generation'], $checkpoint);
            if ($key === $this->key($organizationId, null, null)) {
                $expected = 0;
                $matched = 0;
                $oldest = null;
                foreach ($status['source_catalog'] as &$source) {
                    $count = $expectedCounts[$source['type']] ?? null;
                    $source['expected_count'] = (int) ($count->expected_count ?? 0);
                    $source['indexed_count'] = (int) ($count->indexed_count ?? 0);
                    $source['pending_count'] = max(0, $source['expected_count'] - $source['indexed_count']);
                    $source['stale_count'] = max(0, $source['stored_count'] - $source['indexed_count']);
                    $expected += $source['expected_count'];
                    $matched += $source['indexed_count'];
                    if (($count->pending_since ?? null) !== null) {
                        $pending = Carbon::parse($count->pending_since)->getTimestamp();
                        $oldest = $oldest === null ? $pending : min($oldest, $pending);
                    }
                }
                unset($source);
                $pendingCount = max(0, $expected - $matched);
                $status['expected_source_count'] = $expected;
                $status['indexed_source_count'] = $matched;
                $status['pending_source_count'] = $pendingCount;
                $status['stale_source_count'] = max(0, $stored - $matched);
                $status['eligible_count_known'] = true;
                $status['coverage_complete'] = $pendingCount === 0 && $stored === $matched;
                $status['ready'] = $enabled && $matched > 0;
                $status['snapshot_at'] = $snapshot['snapshot_at'];
                $status['lag_seconds'] = $oldest === null ? ($status['stale_source_count'] === 0 ? 0 : null) : max(0, now()->getTimestamp() - $oldest);
                $status['lag_exceeded'] = $status['lag_seconds'] !== null && $status['lag_seconds'] > 300;
                return $status;
            }
        }
        if ($checkpoint !== null) { $checkpoint(); }
        if ($this->actorCoversCompleteSnapshot($organizationId, $snapshot, $catalog, $stored, $indexed, $enabledTypes)
            && $key === $this->key($organizationId, null, null)) {
            $status['expected_source_count'] = $stored;
            $status['pending_source_count'] = 0;
            $status['stale_source_count'] = 0;
            $status['eligible_count_known'] = true;
            $status['coverage_complete'] = true;
            $status['lag_seconds'] = 0;
            $status['snapshot_at'] = $snapshot['snapshot_at'];
            foreach ($status['source_catalog'] as &$source) {
                $source['expected_count'] = $source['stored_count'];
                $source['pending_count'] = 0;
                $source['stale_count'] = 0;
            }
            unset($source);
        }
        return $status;
    }

    private function usableProjection(array $snapshot, array $enabledTypes): bool
    {
        if (! ($snapshot['eligible_count_known'] ?? false) || ! is_string($snapshot['projection_generation'] ?? null)
            || ! is_string($snapshot['snapshot_at'] ?? null)) {
            return false;
        }
        $types = array_column($snapshot['source_catalog'] ?? [], 'type');
        if (array_diff($enabledTypes, $types) !== [] || array_diff($types, $enabledTypes) !== []) {
            return false;
        }
        try {
            $timestamp = Carbon::parse($snapshot['snapshot_at']);
            return ! $timestamp->isFuture() && $timestamp->gte(now()->subMinutes(5));
        } catch (Throwable) {
            return false;
        }
    }

    private function actorCoversCompleteSnapshot(int $organizationId, mixed $snapshot, array $catalog, int $stored, int $indexed, array $enabledTypes): bool
    {
        if (! is_array($snapshot) || ! ($snapshot['eligible_count_known'] ?? false) || ! ($snapshot['coverage_complete'] ?? false)
            || ! is_string($snapshot['snapshot_at'] ?? null) || ($snapshot['stored_source_count'] ?? null) !== $stored
            || ($snapshot['expected_source_count'] ?? null) !== $stored || ($snapshot['indexed_source_count'] ?? null) !== $indexed || $indexed !== $stored
            || array_diff($enabledTypes, array_column($catalog, 'type')) !== []
            || array_diff($enabledTypes, array_column($snapshot['source_catalog'] ?? [], 'type')) !== []
            || array_diff(array_column($snapshot['source_catalog'] ?? [], 'type'), $enabledTypes) !== []) {
            return false;
        }
        try {
            $timestamp = Carbon::parse($snapshot['snapshot_at']);
            if ($timestamp->isFuture() || $timestamp->lt(now()->subMinutes(5))) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        $visible = array_column($catalog, null, 'type');
        foreach ($snapshot['source_catalog'] ?? [] as $source) {
            $count = (int) ($visible[$source['type']]['indexed_count'] ?? 0);
            if (($source['expected_count'] ?? null) !== $count || ($source['indexed_count'] ?? null) !== $count
                || ($source['stored_count'] ?? null) !== $count) {
                return false;
            }
        }
        return RagSource::query()->where('organization_id', $organizationId)->whereIn('source_type', $enabledTypes)->count() === $stored;
    }

    public static function invalidate(int $organizationId): void
    {
        Cache::add('ai-rag-coverage-revision:'.$organizationId, 0, 86400);
        Cache::increment('ai-rag-coverage-revision:'.$organizationId);
        Cache::add('ai-rag-coverage-dirty-since:'.$organizationId, now()->getTimestamp(), 86400);
    }

    public function coverage(int $organizationId, ?int $projectId = null, ?string $sourceType = null): array
    {
        $key = $this->key($organizationId, $projectId, $sourceType);
        $snapshot = Cache::get($key);
        $processing = RagIndexRun::query()->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->when($sourceType !== null, static fn ($query) => $query->where('source_type', $sourceType))
            ->whereIn('status', [RagIndexRun::STATUS_QUEUED, RagIndexRun::STATUS_RUNNING])->exists();
        if (! is_array($snapshot)) {
            $this->queueRefresh($organizationId, $projectId, $sourceType, $key);
            $snapshot = [
                'expected_source_count' => null, 'indexed_source_count' => null, 'eligible_count_known' => false,
                'pending_source_count' => null, 'coverage_complete' => false,
                'stored_source_count' => null, 'stale_source_count' => null, 'snapshot_at' => null,
                'pending_since' => Cache::get('ai-rag-coverage-dirty-since:'.$organizationId),
                'source_catalog' => array_map(static fn (array $source): array => $source + ['expected_count' => null, 'indexed_count' => null, 'pending_count' => null, 'error' => null], $this->registry->sourceCatalog()),
            ];
            $processing = $processing || Cache::has($key.':queued');
        }
        $since = $snapshot['pending_since'] ?? null;
        $lag = is_numeric($since) ? max(0, now()->getTimestamp() - (int) $since) : null;
        return $snapshot + ['processing' => $processing, 'lag_seconds' => $lag, 'lag_goal_seconds' => 300, 'lag_exceeded' => $lag !== null && $lag > 300];
    }

    private function key(int $organizationId, ?int $projectId, ?string $sourceType): string
    {
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organizationId, 0);
        return 'ai-rag-coverage:'.$organizationId.':'.($projectId ?? 0).':'.($sourceType ?? '*').':'.$revision;
    }

    private function queueRefresh(int $organizationId, ?int $projectId, ?string $sourceType, string $key): void
    {
        if (! Cache::add($key.':queued', true, 7200)) {
            return;
        }
        try {
            dispatch(new RefreshRagCoverageJob($organizationId, $projectId, $sourceType, $key));
        } catch (Throwable $exception) {
            Cache::forget($key.':queued');
            Log::warning('ai_assistant.rag.coverage_queue_failed', ['organization_id' => $organizationId, 'exception_class' => $exception::class]);
        }
    }

    public function refreshCoverage(int $organizationId, ?int $projectId = null, ?string $sourceType = null): array
    {
        if ($projectId !== null || $sourceType !== null) {
            return $this->buildCoverageSnapshot($organizationId, $projectId, $sourceType);
        }
        $lease = Cache::lock('ai-rag-coverage-projection:'.$organizationId, 7500);
        if (! $lease->get()) {
            return $this->coverage($organizationId);
        }
        $cacheKey = $this->key($organizationId, null, null);
        $deadline = microtime(true) + 7200;
        $guard = function () use ($organizationId, $cacheKey, $deadline): void {
            if (microtime(true) >= $deadline || $cacheKey !== $this->key($organizationId, null, null)) {
                throw new RuntimeException('rag_coverage_projection_expired');
            }
        };
        try {
            return $this->buildCoverageSnapshot($organizationId, null, null, (string) Str::uuid(), $guard);
        } finally {
            $lease->release();
        }
    }

    private function buildCoverageSnapshot(int $organizationId, ?int $projectId, ?string $sourceType, ?string $generation = null, ?callable $guard = null): array
    {
        $cacheKey = $this->key($organizationId, $projectId, $sourceType);
        $catalog = [];
        $expected = 0;
        $current = 0;
        $stored = 0;
        $oldestPending = null;
        $known = true;
        foreach ($this->registry->enabledCollectors() as $type => $collector) {
            if ($sourceType !== null && $sourceType !== $type) {
                continue;
            }
            $eligible = 0;
            $indexed = 0;
            $error = null;
            $batch = [];
            $storedForType = RagSource::query()->where('organization_id', $organizationId)->where('source_type', $type)
                ->when($projectId !== null, static fn (Builder $query): Builder => $query->where('project_id', $projectId))->count();
            try {
                foreach ($collector->collectForOrganization($organizationId, $projectId) as $chunk) {
                    $eligible++;
                    $batch[] = $chunk;
                    if (count($batch) === 100) {
                        [$matched, $pendingAt] = $this->reconcileBatch($organizationId, $type, $batch, $generation, $guard);
                        $indexed += $matched;
                        if ($pendingAt !== null && ($oldestPending === null || $pendingAt < $oldestPending)) {
                            $oldestPending = $pendingAt;
                        }
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    [$matched, $pendingAt] = $this->reconcileBatch($organizationId, $type, $batch, $generation, $guard);
                    $indexed += $matched;
                    if ($pendingAt !== null && ($oldestPending === null || $pendingAt < $oldestPending)) {
                        $oldestPending = $pendingAt;
                    }
                }
            } catch (Throwable $exception) {
                $known = false;
                $error = 'collector_failed';
                Log::warning('ai_assistant.rag.coverage_failed', ['organization_id' => $organizationId, 'source_type' => $type, 'exception_class' => $exception::class]);
            }
            $expected += $eligible;
            $current += $indexed;
            $stored += $storedForType;
            $catalog[] = ['type' => $type, 'enabled' => true, 'expected_count' => $error === null ? $eligible : null, 'indexed_count' => $indexed, 'stored_count' => $storedForType, 'stale_count' => $error === null ? max(0, $storedForType - $indexed) : null, 'pending_count' => $error === null ? max(0, $eligible - $indexed) : null, 'error' => $error];
        }
        $lag = $oldestPending === null ? 0 : max(0, now()->getTimestamp() - $oldestPending->getTimestamp());
        $active = RagIndexRun::query()->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->when($sourceType !== null, static fn ($query) => $query->where('source_type', $sourceType))
            ->whereIn('status', [RagIndexRun::STATUS_QUEUED, RagIndexRun::STATUS_RUNNING])->exists();

        $snapshot = [
            'expected_source_count' => $known ? $expected : null,
            'indexed_source_count' => $current,
            'eligible_count_known' => $known,
            'pending_source_count' => $known ? max(0, $expected - $current) : null,
            'coverage_complete' => $known && $expected === $current && $stored === $current,
            'stored_source_count' => $stored,
            'stale_source_count' => $known ? max(0, $stored - $current) : null,
            'processing' => $active,
            'lag_seconds' => $lag,
            'lag_goal_seconds' => 300,
            'lag_exceeded' => $lag > 300,
            'source_catalog' => $catalog,
            'pending_since' => $oldestPending?->getTimestamp() ?? ((! $known || $stored !== $current) ? Cache::get('ai-rag-coverage-dirty-since:'.$organizationId) : null),
            'snapshot_at' => now()->toISOString(),
        ];
        if ($cacheKey !== $this->key($organizationId, $projectId, $sourceType)) {
            $snapshot['eligible_count_known'] = false;
            $snapshot['coverage_complete'] = false;
            $snapshot['expected_source_count'] = null;
            $snapshot['pending_source_count'] = null;
        }
        if ($generation !== null && $snapshot['eligible_count_known']) {
            if ($guard !== null) {
                try {
                    $guard();
                    $snapshot['projection_generation'] = $generation;
                } catch (Throwable) {
                    $snapshot['eligible_count_known'] = false;
                    $snapshot['coverage_complete'] = false;
                    $snapshot['expected_source_count'] = null;
                    $snapshot['pending_source_count'] = null;
                }
            }
        }
        unset($snapshot['processing'], $snapshot['lag_seconds'], $snapshot['lag_exceeded']);
        Cache::put($cacheKey, $snapshot, 300);
        if (isset($snapshot['projection_generation']) && $cacheKey === $this->key($organizationId, $projectId, $sourceType)) {
            ($this->projection ?? new RagExpectedSourceProjection($this->indexer))->prune($organizationId, $snapshot['projection_generation']);
        }
        if ($snapshot['coverage_complete'] && $projectId === null && $sourceType === null) {
            Cache::forget('ai-rag-coverage-dirty-since:'.$organizationId);
        }
        return $this->coverage($organizationId, $projectId, $sourceType);
    }

    private function reconcileBatch(int $organizationId, string $sourceType, array $batch, ?string $generation = null, ?callable $guard = null): array
    {
        if ($guard !== null) {
            $guard();
        }
        if ($generation !== null) {
            ($this->projection ?? new RagExpectedSourceProjection($this->indexer))->stage($organizationId, $sourceType, $generation, $batch);
        }
        $sources = RagSource::query()->where('organization_id', $organizationId)->where('source_type', $sourceType)
            ->withCount('chunks')->where(static function (Builder $query) use ($batch): void {
                foreach ($batch as $chunk) {
                    $query->orWhere(static function (Builder $identity) use ($chunk): void {
                        $identity->where('identity_project_id', $chunk->projectId ?? 0)
                            ->where('identity_part_key', (string) ($chunk->metadata['unit_id'] ?? ''))
                            ->where('entity_type', $chunk->entityType)->where('entity_id', (string) $chunk->entityId);
                    });
                }
            })->get()->keyBy(static fn (RagSource $source): string => json_encode([$source->identity_project_id, $source->identity_part_key, $source->entity_type, $source->entity_id], JSON_THROW_ON_ERROR));
        $indexed = 0;
        $oldestPending = null;
        foreach ($batch as $chunk) {
            $identity = json_encode([$chunk->projectId ?? 0, (string) ($chunk->metadata['unit_id'] ?? ''), $chunk->entityType, (string) $chunk->entityId], JSON_THROW_ON_ERROR);
            $source = $sources->get($identity);
            if ($source instanceof RagSource && (int) $source->getAttribute('chunks_count') > 0 && $this->indexer->matchesSource($source, $chunk)) {
                $indexed++;
                continue;
            }
            $changedAt = $chunk->updatedAt ?? now();
            if ($oldestPending === null || $changedAt < $oldestPending) {
                $oldestPending = $changedAt;
            }
        }
        return [$indexed, $oldestPending];
    }
}
