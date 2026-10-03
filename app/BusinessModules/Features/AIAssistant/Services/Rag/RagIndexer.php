<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingDimensionMismatch;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

class RagIndexer
{
    private const SOURCE_INDEX_LOCK_TTL_MIN_SECONDS = 7200;

    private const SOURCE_INDEX_LOCK_WAIT_MAX_SECONDS = 120;

    private const COVERAGE_CHUNK_PACKET_SIZE = 200;

    public function __construct(
        private readonly RagEmbeddingProviderInterface $embeddingProvider,
        private readonly RagSourceRegistry $sourceRegistry,
        private readonly ?UsageTracker $usageTracker = null,
        private readonly ?RagEmbeddingProviderRegistry $embeddingProviderRegistry = null
    ) {}

    public function indexChunk(RagChunkData $chunk, ?DateTimeInterface $reconciledAt = null, ?callable $guard = null): void
    {
        Cache::lock($this->sourceIndexLockKey($chunk), $this->sourceIndexLockTtlSeconds())
            ->block($this->sourceIndexLockWaitSeconds(), function () use ($chunk, $reconciledAt, $guard): void {
                $this->indexChunkUnderLock($chunk, $reconciledAt, $guard);
            });
    }

    private function indexChunkUnderLock(RagChunkData $chunk, ?DateTimeInterface $reconciledAt, ?callable $guard): void
    {
        if ($guard !== null) {
            $guard();
        }
        $existing = RagSource::query()
            ->where('organization_id', $chunk->organizationId)
            ->where('identity_project_id', $chunk->projectId ?? 0)
            ->where('identity_part_key', $this->partKey($chunk))
            ->where('source_type', $chunk->sourceType)
            ->where('entity_type', $chunk->entityType)
            ->where('entity_id', (string) $chunk->entityId)
            ->first();

        $loadedChunks = $existing instanceof RagSource ? $this->loadSourceChunks($existing) : null;
        $embeddingProvider = $this->providerForIndexing($existing instanceof RagSource ? $existing : null, $loadedChunks);
        $checksum = $this->checksum($chunk, true, $embeddingProvider);

        $contentChunks = $this->splitContent($chunk->content);
        if ($contentChunks === []) {
            return;
        }

        $storedChunks = $existing instanceof RagSource
            ? $this->matchingContentChunks($existing, $contentChunks, $loadedChunks)
            : null;
        $vectorsCompatible = $existing instanceof RagSource
            && $storedChunks !== null
            && $this->compatibleSourceEmbeddings($existing, count($contentChunks), $embeddingProvider, $storedChunks);
        $sourceChecksumMatches = $existing instanceof RagSource
            && in_array($existing->checksum, [$checksum, $this->sourceFingerprint($chunk)], true);

        if ($existing instanceof RagSource && $sourceChecksumMatches && $vectorsCompatible) {
            $values = $existing->checksum === $checksum ? [] : [
                'source_version' => $this->sourceVersion($chunk),
                'title' => $this->sourceTitle($chunk->title),
                'checksum' => $checksum,
                'metadata' => $chunk->metadata,
            ];
            if ($reconciledAt instanceof DateTimeInterface) {
                $values['last_reconciled_at'] = $reconciledAt;
            }
            if ($values !== []) $existing->forceFill($values)->save();

            return;
        }

        if ($existing instanceof RagSource && $vectorsCompatible) {
            $reused = DB::transaction(function () use ($existing, $chunk, $checksum, $contentChunks, $reconciledAt, $guard, $embeddingProvider): bool {
                if ($guard !== null) {
                    $guard();
                }

                $source = RagSource::query()->whereKey($existing->id)->lockForUpdate()->first();
                if (! $source instanceof RagSource) {
                    return false;
                }

                $storedChunks = $this->matchingContentChunks($source, $contentChunks);
                if ($storedChunks === null || ! $this->compatibleSourceEmbeddings($source, count($contentChunks), $embeddingProvider, $storedChunks)) {
                    return false;
                }

                $source->forceFill($this->sourceAttributes($chunk, $checksum, $reconciledAt))->save();
                $chunkCount = count($contentChunks);
                foreach ($storedChunks as $index => $storedChunk) {
                    $storedChunk->forceFill([
                        'metadata' => array_merge($chunk->metadata, [
                            'chunk_index' => $index,
                            'chunk_count' => $chunkCount,
                        ]),
                    ])->save();
                }

                return true;
            });

            if ($reused) {
                return;
            }
        }

        $embeddedChunks = $this->embedContentChunks($chunk, $contentChunks, $embeddingProvider);

        DB::transaction(function () use ($chunk, $checksum, $embeddedChunks, $reconciledAt, $guard, $embeddingProvider): void {
            if ($guard !== null) {
                $guard();
            }
            $source = RagSource::query()->updateOrCreate(
                [
                    'organization_id' => $chunk->organizationId,
                    'identity_project_id' => $chunk->projectId ?? 0,
                    'identity_part_key' => $this->partKey($chunk),
                    'source_type' => $chunk->sourceType,
                    'entity_type' => $chunk->entityType,
                    'entity_id' => (string) $chunk->entityId,
                ],
                $this->sourceAttributes($chunk, $checksum, $reconciledAt)
            );

            $source->chunks()->delete();

            foreach ($embeddedChunks as $index => $embeddedChunk) {
                RagChunk::query()->forceCreate([
                    'source_id' => $source->id,
                    'organization_id' => $chunk->organizationId,
                    'project_id' => $chunk->projectId,
                    'chunk_index' => $index,
                    'content' => $embeddedChunk['content'],
                    'content_hash' => hash('sha256', $this->normalizeText($embeddedChunk['content'])),
                    'metadata' => array_merge($chunk->metadata, [
                        'chunk_index' => $index,
                        'chunk_count' => count($embeddedChunks),
                    ]),
                    'embedding_provider' => $embeddingProvider->provider(),
                    'embedding_model' => $embeddingProvider->model(),
                    'embedding_created_at' => now(),
                    'embedding' => $embeddedChunk['vector'],
                ]);
            }
        });
    }

    private function sourceIndexLockKey(RagChunkData $chunk): string
    {
        return 'ai-rag-source-index:'.hash('sha256', $this->json([
            'organization_id' => $chunk->organizationId,
            'identity_project_id' => $chunk->projectId ?? 0,
            'identity_part_key' => $this->partKey($chunk),
            'source_type' => $chunk->sourceType,
            'entity_type' => $chunk->entityType,
            'entity_id' => (string) $chunk->entityId,
        ]));
    }

    private function sourceIndexLockTtlSeconds(): int
    {
        return max(self::SOURCE_INDEX_LOCK_TTL_MIN_SECONDS, $this->configInt('ai-assistant.rag.job_timeout', self::SOURCE_INDEX_LOCK_TTL_MIN_SECONDS));
    }

    private function sourceIndexLockWaitSeconds(): int
    {
        try {
            $leaseMinutes = max(1, (int) config('ai-assistant.rag.lease_minutes', 15));
        } catch (Throwable) {
            $leaseMinutes = 15;
        }
        $leaseSeconds = $leaseMinutes * 60;

        return max(1, min(self::SOURCE_INDEX_LOCK_WAIT_MAX_SECONDS, intdiv($leaseSeconds, 2)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceAttributes(RagChunkData $chunk, string $checksum, ?DateTimeInterface $reconciledAt): array
    {
        return [
            'project_id' => $chunk->projectId,
            'source_version' => $this->sourceVersion($chunk),
            'title' => $this->sourceTitle($chunk->title),
            'checksum' => $checksum,
            'metadata' => $chunk->metadata,
            'indexed_at' => now(),
            'last_reconciled_at' => $reconciledAt,
        ];
    }

    public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null, ?callable $progress = null): int
    {
        $collectors = $sourceType === null
            ? $this->sourceRegistry->enabledCollectors()
            : $this->collectorForSourceType($sourceType);
        $indexed = 0;

        foreach ($collectors as $collector) {
            $reconciledAt = now();
            foreach ($collector->collectForOrganization($organizationId, $projectId) as $chunk) {
                if ($progress !== null) {
                    $progress($indexed);
                }
                $this->indexChunk($chunk, $reconciledAt, $progress === null ? null : static fn () => $progress($indexed));
                $indexed++;
            }
            if ($progress !== null) {
                $progress($indexed);
            }
            $this->pruneReconciledScope($organizationId, $projectId, $collector->sourceType(), $reconciledAt, $progress === null ? null : static fn () => $progress($indexed));
        }

        return $indexed;
    }

    public function indexEntity(
        int $organizationId,
        ?string $sourceType,
        string $entityType,
        string|int $entityId,
        ?callable $progress = null
    ): int {
        if ($sourceType === null) {
            return 0;
        }

        $collector = $this->sourceRegistry->collector($sourceType);
        if (! $collector instanceof RagSourceCollectorInterface || ! $collector->enabled()) {
            return 0;
        }

        $indexed = 0;
        $collected = false;
        $reconciledAt = now();
        if ($progress !== null) {
            $progress(0);
        }

        foreach ($collector->collectEntity($organizationId, $entityType, $entityId) as $chunk) {
            if ($progress !== null) {
                $progress($indexed);
            }
            $collected = true;
            $this->indexChunk($chunk, $reconciledAt, $progress === null ? null : static fn () => $progress($indexed));
            $indexed++;
        }

        if (! $collected) {
            if ($progress !== null) {
                $progress(0);
            }
            $guard = $progress === null ? null : static fn () => $progress(0);
            $this->deleteIndexedEntity($organizationId, $sourceType, $entityType, $entityId, $guard);
            if ($sourceType === 'estimate' && $entityType === 'estimate') {
                $this->deleteChildSources($organizationId, $sourceType, 'estimate_id', $entityId, $guard);
            }
            if ($sourceType === 'estimate' && $entityType === 'estimate_item') {
                $this->deleteChildSources($organizationId, $sourceType, 'estimate_item_id', $entityId, $guard);
            }
        }

        if ($collected && (($sourceType === 'estimate' && $entityType === 'estimate') || $sourceType === 'file_document')) {
            if ($progress !== null) {
                $progress($indexed);
            }
            RagSource::query()->where('organization_id', $organizationId)->where('source_type', $sourceType)
                ->where(function ($query) use ($sourceType, $entityType, $entityId): void {
                    $query->where(function ($identity) use ($entityType, $entityId): void {
                        $identity->where('entity_type', $entityType)->where('entity_id', (string) $entityId);
                    });
                    if ($sourceType === 'estimate') {
                        $query->orWhere('metadata->estimate_id', (int) $entityId);
                    }
                })
                ->where(function ($query) use ($reconciledAt): void {
                    $query->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', $reconciledAt);
                })->lazyById(100)->each(static function (RagSource $source) use ($progress, $indexed, $reconciledAt): void {
                    DB::transaction(static function () use ($source, $progress, $indexed, $reconciledAt): void {
                        if ($progress !== null) {
                            $progress($indexed);
                        }
                        $current = RagSource::query()->whereKey($source->id)->where(static function ($query) use ($reconciledAt): void {
                            $query->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', $reconciledAt);
                        })->lockForUpdate()->first();
                        if ($current !== null) {
                            $current->chunks()->delete();
                            $current->delete();
                        }
                    });
                });
        }

        return $indexed;
    }

    private function deleteChildSources(int $organizationId, string $sourceType, string $parentField, string|int $parentId, ?callable $guard = null): void
    {
        $this->deleteSources(RagSource::query()->where('organization_id', $organizationId)->where('source_type', $sourceType)
            ->where('metadata->'.$parentField, (int) $parentId), $guard);
    }

    private function pruneReconciledScope(
        int $organizationId,
        ?int $projectId,
        string $sourceType,
        DateTimeInterface $reconciledAt,
        ?callable $guard = null
    ): void {
        RagSource::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->where(function ($query) use ($reconciledAt): void {
                $query->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', $reconciledAt);
            })
            ->lazyById(100)
            ->each(static function (RagSource $source) use ($guard, $reconciledAt): void {
                DB::transaction(static function () use ($source, $guard, $reconciledAt): void {
                    if ($guard !== null) {
                        $guard();
                    }
                    $current = RagSource::query()->whereKey($source->id)->where(static function ($query) use ($reconciledAt): void {
                        $query->whereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', $reconciledAt);
                    })->lockForUpdate()->first();
                    if ($current !== null) {
                        $current->chunks()->delete();
                        $current->delete();
                    }
                });
            });
    }

    public function deleteIndexedEntity(
        int $organizationId,
        string $sourceType,
        string $entityType,
        string|int $entityId,
        ?callable $guard = null
    ): void {
        $this->deleteSources(RagSource::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId), $guard);
    }

    private function deleteSources(Builder $query, ?callable $guard): void
    {
        foreach ($query->lazyById(100) as $source) {
            DB::transaction(static function () use ($source, $guard): void {
                if ($guard !== null) {
                    $guard();
                }
                $current = RagSource::query()->whereKey($source->id)->lockForUpdate()->first();
                if ($current !== null) {
                    $current->chunks()->delete();
                    $current->delete();
                }
            });
        }
    }

    /**
     * @return array<string, RagSourceCollectorInterface>
     */
    private function collectorForSourceType(string $sourceType): array
    {
        $collector = $this->sourceRegistry->collector($sourceType);

        if (! $collector instanceof RagSourceCollectorInterface || ! $collector->enabled()) {
            return [];
        }

        return [$sourceType => $collector];
    }

    private function providerForIndexing(?RagSource $source, ?\Illuminate\Database\Eloquent\Collection $chunks = null): RagEmbeddingProviderInterface
    {
        if ($source === null) {
            return $this->embeddingProviderRegistry?->newIndexProvider() ?? $this->embeddingProvider;
        }

        return $this->providerForExistingSource($source, true, $chunks) ?? $this->embeddingProvider;
    }

    private function providerForExistingSource(RagSource $source, bool $strict, ?\Illuminate\Database\Eloquent\Collection $chunks = null): ?RagEmbeddingProviderInterface
    {
        if ($this->embeddingProviderRegistry === null) {
            return $this->embeddingProvider;
        }

        $chunksCount = $chunks?->count() ?? $source->getAttribute('chunks_count');
        if ($chunksCount === null) {
            $chunksCount = $source->chunks()->count();
        }
        if ((int) $chunksCount === 0) {
            return $this->embeddingProvider;
        }

        $profile = $chunks === null
            ? ($this->embeddingProfilesBySourceIds([(int) $source->id])[(int) $source->id] ?? null)
            : $this->embeddingProfileForChunks($chunks);
        if ($profile === null) {
            if ($strict) {
                throw new RuntimeException('rag_embedding_profile_unavailable');
            }

            return null;
        }

        $provider = $this->embeddingProviderRegistry->forProfile($profile['provider'], $profile['model'], $profile['dimensions']);
        if ($provider === null && $strict) {
            throw new RuntimeException('rag_embedding_profile_unavailable');
        }

        return $provider;
    }

    public function coverageIdentities(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        if ($this->embeddingProviderRegistry === null) {
            $identities = [];
            foreach ($chunks as $key => $chunk) {
                $identities[$key] = $this->coverageIdentity($chunk, $this->embeddingProvider);
            }

            return $identities;
        }

        $sources = $this->existingSourcesForChunks($chunks);
        $profiles = $this->embeddingProfilesBySourceIds(array_map(
            static fn (RagSource $source): int => (int) $source->id,
            array_values($sources)
        ));
        $identities = [];

        foreach ($chunks as $key => $chunk) {
            $source = $sources[$this->sourceIdentityKey($chunk)] ?? null;
            if (! $source instanceof RagSource) {
                $provider = $this->embeddingProviderRegistry->newIndexProvider();
            } elseif ((int) $source->getAttribute('chunks_count') === 0) {
                $provider = $this->embeddingProvider;
            } else {
                $profile = $profiles[(int) $source->id] ?? null;
                $provider = $profile === null
                    ? null
                    : $this->embeddingProviderRegistry->forProfile($profile['provider'], $profile['model'], $profile['dimensions']);
                if ($provider === null) {
                    $identities[$key] = $this->coverageIdentityForUnavailableProfile($chunk);
                    continue;
                }
            }

            $identities[$key] = $this->coverageIdentity($chunk, $provider);
        }

        return $identities;
    }

    public function coverageBatch(array $chunks, ?callable $guard = null): array
    {
        $sources = $this->existingSourcesForChunks($chunks);
        $identities = [];
        $matches = [];
        $packets = [];
        $packet = [];
        $chunkCount = 0;
        foreach ($sources as $source) {
            $count = (int) $source->getAttribute('chunks_count');
            if ($packet !== [] && $chunkCount + $count > self::COVERAGE_CHUNK_PACKET_SIZE) {
                $packets[] = $packet;
                $packet = [];
                $chunkCount = 0;
            }
            $packet[] = (int) $source->id;
            $chunkCount += $count;
        }
        if ($packet !== []) {
            $packets[] = $packet;
        }
        foreach ($packets as $sourceIds) {
            if ($guard !== null) {
                $guard();
            }
            $loaded = $this->sourceChunksQuery(RagChunk::query()->whereIn('source_id', $sourceIds))->get()->groupBy('source_id');
            $sourceIds = array_fill_keys($sourceIds, true);
            foreach ($chunks as $key => $chunk) {
                $source = $sources[$this->sourceIdentityKey($chunk)] ?? null;
                if (! $source instanceof RagSource || ! isset($sourceIds[(int) $source->id])) {
                    continue;
                }
                $storedChunks = $loaded->get((int) $source->id) ?? new \Illuminate\Database\Eloquent\Collection;
                $provider = $this->providerForExistingSource($source, false, $storedChunks);
                $identities[$key] = $provider === null
                    ? $this->coverageIdentityForUnavailableProfile($chunk)
                    : $this->coverageIdentity($chunk, $provider);
                $matches[$key] = $this->matchesLoadedSource($source, $chunk, $storedChunks);
            }
        }
        foreach ($chunks as $key => $chunk) {
            if (! array_key_exists($key, $identities)) {
                $identities[$key] = $this->coverageIdentity($chunk, $this->embeddingProviderRegistry?->newIndexProvider() ?? $this->embeddingProvider);
                $matches[$key] = false;
            }
        }

        return ['identities' => $identities, 'matches' => $matches];
    }

    private function existingSourcesForChunks(array $chunks): array
    {
        $first = reset($chunks);
        if (! $first instanceof RagChunkData) {
            return [];
        }

        $uniqueChunks = [];
        foreach ($chunks as $chunk) {
            if ($chunk->organizationId !== $first->organizationId || $chunk->sourceType !== $first->sourceType) {
                throw new RuntimeException('rag_coverage_collector_scope_mismatch');
            }
            $uniqueChunks[$this->sourceIdentityKey($chunk)] = $chunk;
        }

        $sources = RagSource::query()
            ->where('organization_id', $first->organizationId)
            ->where('source_type', $first->sourceType)
            ->select(['id', 'organization_id', 'project_id', 'identity_project_id', 'identity_part_key', 'source_type', 'entity_type', 'entity_id', 'checksum'])
            ->withCount('chunks')
            ->where(static function (Builder $identities) use ($uniqueChunks): void {
                foreach ($uniqueChunks as $chunk) {
                    $identities->orWhere(static function (Builder $identity) use ($chunk): void {
                        $identity->where('identity_project_id', $chunk->projectId ?? 0)
                            ->where('identity_part_key', (string) ($chunk->metadata['unit_id'] ?? ''))
                            ->where('entity_type', $chunk->entityType)
                            ->where('entity_id', (string) $chunk->entityId);
                    });
                }
            })
            ->get();

        $sourcesByIdentity = [];
        foreach ($sources as $source) {
            $sourcesByIdentity[$this->sourceIdentityKey($source)] = $source;
        }

        return $sourcesByIdentity;
    }

    private function sourceChunksQuery(Builder $query): Builder
    {
        $query->select(['id', 'source_id', 'chunk_index', 'content', 'content_hash', 'embedding_provider', 'embedding_model']);
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->selectRaw('vector_dims(embedding) AS embedding_dimensions');
        }

        return $query->addSelect('embedding');
    }

    private function loadSourceChunks(RagSource $source): \Illuminate\Database\Eloquent\Collection
    {
        return $this->sourceChunksQuery($source->chunks()->getQuery())->get();
    }

    private function chunkDimensions(RagChunk $chunk): int
    {
        if (array_key_exists('embedding_dimensions', $chunk->getAttributes())) {
            return (int) $chunk->getAttribute('embedding_dimensions');
        }
        $vector = is_array($chunk->embedding) ? $chunk->embedding : json_decode((string) $chunk->embedding, true);

        return is_array($vector) && array_is_list($vector) ? count($vector) : 0;
    }

    private function embeddingProfileForChunks(\Illuminate\Database\Eloquent\Collection $chunks): ?array
    {
        $providers = [];
        $models = [];
        $dimensions = [];
        foreach ($chunks as $chunk) {
            if ($chunk->embedding_provider !== null) {
                $providers[$chunk->embedding_provider] = $chunk->embedding_provider;
            }
            if ($chunk->embedding_model !== null) {
                $models[$chunk->embedding_model] = $chunk->embedding_model;
            }
            $dimension = $this->chunkDimensions($chunk);
            if ($dimension > 0) {
                $dimensions[$dimension] = true;
            }
        }

        return $this->embeddingProfileFromSummary($chunks->count(), count($providers), $providers === [] ? null : reset($providers),
            count($models), $models === [] ? null : reset($models), count($dimensions), array_key_first($dimensions));
    }

    private function embeddingProfilesBySourceIds(array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        if ($sourceIds === []) {
            return [];
        }

        $profiles = [];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $summaries = DB::table('ai_rag_chunks')
                ->whereIn('source_id', $sourceIds)
                ->groupBy('source_id')
                ->select('source_id')
                ->selectRaw('COUNT(*) AS chunks_count, COUNT(DISTINCT embedding_provider) AS provider_count, MIN(embedding_provider) AS provider')
                ->selectRaw('COUNT(DISTINCT embedding_model) AS model_count, MIN(embedding_model) AS model')
                ->selectRaw('COUNT(DISTINCT vector_dims(embedding)) FILTER (WHERE embedding IS NOT NULL) AS dimensions_count, MIN(vector_dims(embedding)) FILTER (WHERE embedding IS NOT NULL) AS dimensions')
                ->get();

            foreach ($summaries as $summary) {
                $profiles[(int) $summary->source_id] = $this->embeddingProfileFromSummary(
                    (int) $summary->chunks_count,
                    (int) $summary->provider_count,
                    $summary->provider,
                    (int) $summary->model_count,
                    $summary->model,
                    (int) $summary->dimensions_count,
                    $summary->dimensions
                );
            }

            return $profiles;
        }

        $groupedChunks = [];
        foreach (RagChunk::query()->whereIn('source_id', $sourceIds)->get(['source_id', 'embedding_provider', 'embedding_model', 'embedding']) as $chunk) {
            $sourceId = (int) $chunk->source_id;
            $groupedChunks[$sourceId] ??= ['chunks_count' => 0, 'providers' => [], 'models' => [], 'dimensions' => []];
            $groupedChunks[$sourceId]['chunks_count']++;
            if (is_string($chunk->embedding_provider) && $chunk->embedding_provider !== '') {
                $groupedChunks[$sourceId]['providers'][$chunk->embedding_provider] = true;
            }
            if (is_string($chunk->embedding_model) && $chunk->embedding_model !== '') {
                $groupedChunks[$sourceId]['models'][$chunk->embedding_model] = true;
            }
            if ($chunk->embedding !== null) {
                $vector = is_array($chunk->embedding) ? $chunk->embedding : json_decode((string) $chunk->embedding, true);
                if (is_array($vector) && array_is_list($vector) && $vector !== []) {
                    $groupedChunks[$sourceId]['dimensions'][count($vector)] = true;
                }
            }
        }

        foreach ($groupedChunks as $sourceId => $summary) {
            $profiles[$sourceId] = $this->embeddingProfileFromSummary(
                $summary['chunks_count'],
                count($summary['providers']),
                array_key_first($summary['providers']),
                count($summary['models']),
                array_key_first($summary['models']),
                count($summary['dimensions']),
                array_key_first($summary['dimensions'])
            );
        }

        return $profiles;
    }

    private function embeddingProfileFromSummary(
        int $chunksCount,
        int $providerCount,
        mixed $provider,
        int $modelCount,
        mixed $model,
        int $dimensionsCount,
        mixed $dimensions
    ): ?array {
        if ($chunksCount === 0 || $providerCount !== 1 || $modelCount !== 1 || ! is_string($provider) || $provider === '' || ! is_string($model) || $model === '') {
            return null;
        }

        $configuredDimensions = $this->configuredProfileDimensions($provider, $model);
        $dimensions = $configuredDimensions ?? ($dimensionsCount === 1 ? (int) $dimensions : null);
        if (! is_int($dimensions) || $dimensions < 1) {
            return null;
        }

        return ['provider' => $provider, 'model' => $model, 'dimensions' => $dimensions];
    }

    private function configuredProfileDimensions(string $provider, string $model): ?int
    {
        $profiles = $this->embeddingProviderRegistry?->configuredProfiles() ?? [[
            'provider' => $this->embeddingProvider->provider(),
            'model' => $this->embeddingProvider->model(),
            'dimensions' => $this->embeddingProvider->dimensions(),
        ]];

        foreach ($profiles as $profile) {
            if (is_array($profile) && ($profile['provider'] ?? null) === $provider && ($profile['model'] ?? null) === $model) {
                $dimensions = (int) ($profile['dimensions'] ?? 0);

                return $dimensions > 0 ? $dimensions : null;
            }
        }

        $knownProfile = $this->embeddingProviderRegistry?->forProfile($provider, $model, 256);
        if ($knownProfile instanceof RagEmbeddingProviderInterface) {
            return $knownProfile->dimensions();
        }

        return null;
    }

    private function sourceIdentityKey(RagChunkData|RagSource $source): string
    {
        if ($source instanceof RagChunkData) {
            $identity = [
                $source->organizationId,
                $source->projectId ?? 0,
                $this->partKey($source),
                $source->sourceType,
                $source->entityType,
                (string) $source->entityId,
            ];
        } else {
            $identity = [
                (int) $source->organization_id,
                (int) $source->identity_project_id,
                (string) $source->identity_part_key,
                (string) $source->source_type,
                (string) $source->entity_type,
                (string) $source->entity_id,
            ];
        }

        return json_encode($identity, JSON_THROW_ON_ERROR);
    }

    private function checksum(RagChunkData $chunk, bool $includeEmbedding = true, ?RagEmbeddingProviderInterface $embeddingProvider = null): string
    {
        $payload = $this->sourceFingerprintPayload($chunk);
        $embeddingProvider ??= $this->embeddingProvider;
        if ($includeEmbedding) $payload['embedding'] = ['provider' => $embeddingProvider->provider(),
            'model' => $embeddingProvider->model(), 'dimensions' => $embeddingProvider->dimensions()];

        return hash('sha256', $this->json($payload));
    }

    private function sourceFingerprint(RagChunkData $chunk): string
    {
        return hash('sha256', $this->json($this->sourceFingerprintPayload($chunk)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceFingerprintPayload(RagChunkData $chunk): array
    {
        return [
            'title' => $this->normalizeText($chunk->title),
            'content' => $this->normalizeText($chunk->content),
            'metadata' => $this->normalizeValue($chunk->metadata),
            'updated_at' => $this->sourceVersion($chunk),
        ];
    }

    public function matchesSource(RagSource $source, RagChunkData $chunk): bool
    {
        return $this->matchesLoadedSource($source, $chunk, $this->loadSourceChunks($source));
    }

    private function matchesLoadedSource(RagSource $source, RagChunkData $chunk, \Illuminate\Database\Eloquent\Collection $storedChunks): bool
    {
        $embeddingProvider = $this->providerForExistingSource($source, false, $storedChunks);
        if ($embeddingProvider === null
            || ! in_array($source->checksum, [$this->checksum($chunk, true, $embeddingProvider), $this->sourceFingerprint($chunk)], true)) {
            return false;
        }

        $contentChunks = $this->splitContent($chunk->content);

        return $contentChunks !== []
            && $this->matchingContentChunks($source, $contentChunks, $storedChunks) !== null
            && $this->compatibleSourceEmbeddings($source, count($contentChunks), $embeddingProvider, $storedChunks);
    }

    /**
     * @param  array<int, string>  $contentChunks
     * @return \Illuminate\Database\Eloquent\Collection<int, RagChunk>|null
     */
    private function matchingContentChunks(RagSource $source, array $contentChunks, ?\Illuminate\Database\Eloquent\Collection $storedChunks = null): ?\Illuminate\Database\Eloquent\Collection
    {
        if ($contentChunks === []) {
            return null;
        }

        $storedChunks = ($storedChunks ?? $this->loadSourceChunks($source))->sortBy('chunk_index')->values();
        if ($storedChunks->count() !== count($contentChunks)) {
            return null;
        }

        foreach ($storedChunks as $index => $storedChunk) {
            $expectedHash = hash('sha256', $this->normalizeText($contentChunks[$index]));
            $storedHash = (string) $storedChunk->content_hash;
            $storedContentHash = hash('sha256', $this->normalizeText((string) $storedChunk->content));

            if ((int) $storedChunk->chunk_index !== $index
                || ! hash_equals($expectedHash, $storedHash)
                || ! hash_equals($storedHash, $storedContentHash)) {
                return null;
            }
        }

        return $storedChunks;
    }

    private function compatibleSourceEmbeddings(RagSource $source, int $expectedChunkCount, RagEmbeddingProviderInterface $embeddingProvider, \Illuminate\Database\Eloquent\Collection $chunks): bool
    {
        if ($chunks->count() !== $expectedChunkCount) {
            return false;
        }
        foreach ($chunks as $storedChunk) {
            if ($storedChunk->embedding_provider !== $embeddingProvider->provider()
                || $storedChunk->embedding_model !== $embeddingProvider->model()
                || $this->chunkDimensions($storedChunk) !== $embeddingProvider->dimensions()) {
                return false;
            }
        }

        return true;
    }

    public function coverageIdentity(RagChunkData $chunk, ?RagEmbeddingProviderInterface $embeddingProvider = null): array
    {
        return [
            'organization_id' => $chunk->organizationId,
            'project_id' => $chunk->projectId,
            'identity_project_id' => $chunk->projectId ?? 0,
            'identity_part_key' => $this->partKey($chunk),
            'source_type' => $chunk->sourceType,
            'entity_type' => $chunk->entityType,
            'entity_id' => (string) $chunk->entityId,
            'checksum' => $this->checksum($chunk, true, $embeddingProvider),
        ];
    }

    private function coverageIdentityForUnavailableProfile(RagChunkData $chunk): array
    {
        $identity = $this->coverageIdentity($chunk, $this->embeddingProvider);
        $identity['checksum'] = hash('sha256', $this->json($this->sourceFingerprintPayload($chunk) + [
            'embedding' => ['provider' => 'unavailable', 'model' => 'unavailable', 'dimensions' => 0],
        ]));

        return $identity;
    }

    private function partKey(RagChunkData $chunk): string
    {
        return isset($chunk->metadata['unit_id']) ? (string) $chunk->metadata['unit_id'] : '';
    }

    private function sourceVersion(RagChunkData $chunk): ?string
    {
        return $chunk->updatedAt?->format(DateTimeInterface::ATOM);
    }

    private function normalizeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;

        return trim($value);
    }

    private function sourceTitle(string $title): string
    {
        return RagSource::normalizeTitle($title);
    }

    /**
     * @param  array<int, string>  $contentChunks
     * @return array<int, array{content: string, vector: string}>
     */
    private function embedContentChunks(RagChunkData $chunk, array $contentChunks, RagEmbeddingProviderInterface $embeddingProvider): array
    {
        $embeddedChunks = [];

        foreach ($contentChunks as $index => $content) {
            try {
                $embedding = $embeddingProvider->embed(
                    $content,
                    RagEmbeddingProviderInterface::PURPOSE_DOCUMENT
                );
                if (count($embedding) !== $embeddingProvider->dimensions()) {
                    throw new RagEmbeddingDimensionMismatch($embeddingProvider->dimensions(), count($embedding));
                }
            } catch (Throwable $throwable) {
                $this->recordEmbeddingUsage($chunk, $content, $index, $embeddingProvider, false);
                Log::warning('ai_assistant.rag.embedding_failed', [
                    'organization_id' => $chunk->organizationId,
                    'project_id' => $chunk->projectId,
                    'source_type' => $chunk->sourceType,
                    'entity_type' => $chunk->entityType,
                    'entity_id' => (string) $chunk->entityId,
                    'chunk_index' => $index,
                    'exception_class' => $throwable::class,
                ]);

                throw $throwable;
            }

            $this->recordEmbeddingUsage($chunk, $content, $index, $embeddingProvider);

            $embeddedChunks[] = [
                'content' => $content,
                'vector' => $this->vectorLiteral($embedding),
            ];
        }

        return $embeddedChunks;
    }

    private function recordEmbeddingUsage(
        RagChunkData $chunk,
        string $content,
        int $chunkIndex,
        RagEmbeddingProviderInterface $embeddingProvider,
        bool $successful = true
    ): void
    {
        try {
            $usage = $this->embeddingUsage($content, $embeddingProvider);
            $tracker = $this->usageTracker ?? app(UsageTracker::class);
            $attempts = method_exists($embeddingProvider, 'usageAttempts') ? $embeddingProvider->usageAttempts() : [];
            if (! is_array($attempts) || $attempts === []) $attempts = [$usage + ['is_successful' => $successful, 'usage_key' => 'embedding:'.bin2hex(random_bytes(16))]];
            foreach ($attempts as $attempt) {
                $usage = OpenAIRagEmbeddingProvider::usageEvidence($attempt, $content);
                $tracker->recordUsage(
                    $chunk->organizationId,
                    null,
                    $embeddingProvider->provider(),
                    $embeddingProvider->model(),
                    'rag_index',
                    $usage['input_tokens'],
                    $usage['output_tokens'],
                    $usage['total_tokens'],
                    [
                        'purpose' => RagEmbeddingProviderInterface::PURPOSE_DOCUMENT,
                        'project_id' => $chunk->projectId,
                        'source_type' => $chunk->sourceType,
                        'entity_type' => $chunk->entityType,
                        'entity_id' => (string) $chunk->entityId,
                        'chunk_index' => $chunkIndex,
                        'text_chars' => mb_strlen($content, 'UTF-8'),
                        'usage_source' => $usage['usage_source'],
                        'provider_usage_available' => $usage['provider_usage_available'],
                        'estimated_input_tokens' => $usage['estimated_input_tokens'],
                        'pricing_estimate' => false,
                        'usage_key' => $attempt['usage_key'] ?? null,
                        'attempt' => $attempt['attempt'] ?? 1,
                        'is_successful' => $attempt['is_successful'] ?? $successful,
                    ]
                );
            }
        } catch (Throwable $throwable) {
            Log::warning('ai_assistant.rag.usage_record_failed', [
                'organization_id' => $chunk->organizationId,
                'project_id' => $chunk->projectId,
                'source_type' => $chunk->sourceType,
                'entity_type' => $chunk->entityType,
                'entity_id' => (string) $chunk->entityId,
                'exception_class' => $throwable::class,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingUsage(string $content, RagEmbeddingProviderInterface $embeddingProvider): array
    {
        $provider = $embeddingProvider;

        if (method_exists($provider, 'lastUsage')) {
            try {
                $usage = $provider->lastUsage();

                if (is_array($usage)) {
                    return OpenAIRagEmbeddingProvider::usageEvidence($usage, $content);
                }
            } catch (Throwable) {
            }
        }

        return OpenAIRagEmbeddingProvider::usageEvidence(null, $content);
    }

    /**
     * @return array<int, string>
     */
    private function splitContent(string $content): array
    {
        $content = $this->normalizeText($content);
        if ($content === '') {
            return [];
        }

        $limit = $this->configInt('ai-assistant.rag.chunk_chars', 1200);
        $paragraphs = preg_split("/\n{2,}/u", $content) ?: [$content];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach ($this->splitLongParagraph($paragraph, $limit) as $part) {
                    $chunks[] = $part;
                }

                continue;
            }

            $candidate = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            if (mb_strlen($candidate) > $limit && $current !== '') {
                $chunks[] = $current;
                $current = $paragraph;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : [$content];
    }

    /**
     * @return array<int, string>
     */
    private function splitLongParagraph(string $paragraph, int $limit): array
    {
        $parts = [];
        $offset = 0;
        $length = mb_strlen($paragraph);

        while ($offset < $length) {
            $parts[] = trim(mb_substr($paragraph, $offset, $limit));
            $offset += $limit;
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function configInt(string $key, int $default): int
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * @param  array<int, float>  $embedding
     */
    private function vectorLiteral(array $embedding): string
    {
        return '['.implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') ?: '0',
            $embedding
        )).']';
    }

    /**
     * @return array<string|int, mixed>|string|int|float|bool|null
     */
    private function normalizeValue(mixed $value): array|string|int|float|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            Log::warning('ai_assistant.rag.checksum_json_failed', [
                'exception_class' => $exception::class,
            ]);

            return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';
        }
    }
}
