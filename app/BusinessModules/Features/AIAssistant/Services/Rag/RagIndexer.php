<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class RagIndexer
{
    public function __construct(
        private readonly RagEmbeddingProviderInterface $embeddingProvider,
        private readonly RagSourceRegistry $sourceRegistry,
        private readonly ?UsageTracker $usageTracker = null
    ) {}

    public function indexChunk(RagChunkData $chunk, ?DateTimeInterface $reconciledAt = null, ?callable $guard = null): void
    {
        if ($guard !== null) {
            $guard();
        }
        $checksum = $this->checksum($chunk);
        $existing = RagSource::query()
            ->where('organization_id', $chunk->organizationId)
            ->where('identity_project_id', $chunk->projectId ?? 0)
            ->where('identity_part_key', $this->partKey($chunk))
            ->where('source_type', $chunk->sourceType)
            ->where('entity_type', $chunk->entityType)
            ->where('entity_id', (string) $chunk->entityId)
            ->first();

        if ($existing instanceof RagSource && $this->matchesSource($existing, $chunk)) {
            $values = $existing->checksum === $checksum ? [] : ['checksum' => $checksum];
            if ($reconciledAt instanceof DateTimeInterface) {
                $values['last_reconciled_at'] = $reconciledAt;
            }
            if ($values !== []) $existing->forceFill($values)->save();

            return;
        }

        $contentChunks = $this->splitContent($chunk->content);
        if ($contentChunks === []) {
            return;
        }

        $embeddedChunks = $this->embedContentChunks($chunk, $contentChunks);

        DB::transaction(function () use ($chunk, $checksum, $embeddedChunks, $reconciledAt, $guard): void {
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
                [
                    'project_id' => $chunk->projectId,
                    'source_version' => $this->sourceVersion($chunk),
                    'title' => $this->sourceTitle($chunk->title),
                    'checksum' => $checksum,
                    'metadata' => $chunk->metadata,
                    'indexed_at' => now(),
                    'last_reconciled_at' => $reconciledAt,
                ]
            );

            $source->chunks()->delete();

            foreach ($embeddedChunks as $index => $embeddedChunk) {
                $ragChunk = RagChunk::query()->create([
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
                    'embedding_provider' => $this->embeddingProvider->provider(),
                    'embedding_model' => $this->embeddingProvider->model(),
                    'embedding_created_at' => now(),
                ]);

                $this->storeVector($ragChunk, $embeddedChunk['vector']);
            }
        });
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

    private function deleteIndexedEntity(
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

    private function checksum(RagChunkData $chunk, bool $includeEmbedding = true): string
    {
        $payload = [
            'title' => $this->normalizeText($chunk->title),
            'content' => $this->normalizeText($chunk->content),
            'metadata' => $this->normalizeValue($chunk->metadata),
            'updated_at' => $this->sourceVersion($chunk),
        ];
        if ($includeEmbedding) $payload['embedding'] = ['provider' => $this->embeddingProvider->provider(),
            'model' => $this->embeddingProvider->model(), 'dimensions' => $this->embeddingProvider->dimensions()];

        return hash('sha256', $this->json($payload));
    }

    public function matchesSource(RagSource $source, RagChunkData $chunk): bool
    {
        return in_array($source->checksum, [$this->checksum($chunk), $this->checksum($chunk, false)], true)
            && $this->compatibleSourceEmbeddings($source);
    }

    private function compatibleSourceEmbeddings(RagSource $source): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $counts = $source->chunks()->selectRaw('COUNT(*) AS total, SUM(CASE WHEN embedding IS NOT NULL AND embedding_provider = ? AND embedding_model = ? AND vector_dims(embedding) = ? THEN 1 ELSE 0 END) AS compatible',
                [$this->embeddingProvider->provider(), $this->embeddingProvider->model(), $this->embeddingProvider->dimensions()])->first();

            return $counts !== null && (int) $counts->getAttribute('total') > 0
                && (int) $counts->getAttribute('total') === (int) $counts->getAttribute('compatible');
        }
        $chunks = $source->chunks()->get(['embedding_provider', 'embedding_model', 'embedding']);
        if ($chunks->isEmpty()) return false;
        foreach ($chunks as $storedChunk) {
            $vector = json_decode((string) $storedChunk->getAttribute('embedding'), true);
            if ($storedChunk->embedding_provider !== $this->embeddingProvider->provider()
                || $storedChunk->embedding_model !== $this->embeddingProvider->model()
                || ! is_array($vector) || count($vector) !== $this->embeddingProvider->dimensions()) return false;
        }

        return true;
    }

    public function coverageIdentity(RagChunkData $chunk): array
    {
        return [
            'organization_id' => $chunk->organizationId,
            'project_id' => $chunk->projectId,
            'identity_project_id' => $chunk->projectId ?? 0,
            'identity_part_key' => $this->partKey($chunk),
            'source_type' => $chunk->sourceType,
            'entity_type' => $chunk->entityType,
            'entity_id' => (string) $chunk->entityId,
            'checksum' => $this->checksum($chunk),
        ];
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
    private function embedContentChunks(RagChunkData $chunk, array $contentChunks): array
    {
        $embeddedChunks = [];

        foreach ($contentChunks as $index => $content) {
            try {
                $embedding = $this->embeddingProvider->embed(
                    $content,
                    RagEmbeddingProviderInterface::PURPOSE_DOCUMENT
                );
            } catch (Throwable $throwable) {
                $this->recordEmbeddingUsage($chunk, $content, $index, false);
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

            $this->recordEmbeddingUsage($chunk, $content, $index);

            $embeddedChunks[] = [
                'content' => $content,
                'vector' => $this->vectorLiteral($embedding),
            ];
        }

        return $embeddedChunks;
    }

    private function recordEmbeddingUsage(RagChunkData $chunk, string $content, int $chunkIndex, bool $successful = true): void
    {
        try {
            $usage = $this->embeddingUsage($content);
            $tracker = $this->usageTracker ?? app(UsageTracker::class);
            $attempts = method_exists($this->embeddingProvider, 'usageAttempts') ? $this->embeddingProvider->usageAttempts() : [];
            if (! is_array($attempts) || $attempts === []) $attempts = [$usage + ['is_successful' => $successful, 'usage_key' => 'embedding:'.bin2hex(random_bytes(16))]];
            foreach ($attempts as $attempt) {
                $usage = OpenAIRagEmbeddingProvider::usageEvidence($attempt, $content);
                $tracker->recordUsage(
                    $chunk->organizationId,
                    null,
                    $this->embeddingProvider->provider(),
                    $this->embeddingProvider->model(),
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
    private function embeddingUsage(string $content): array
    {
        $provider = $this->embeddingProvider;

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

    private function storeVector(RagChunk $chunk, string $vector): void
    {
        $sql = DB::connection()->getDriverName() === 'pgsql'
            ? 'UPDATE ai_rag_chunks SET embedding = ?::vector WHERE id = ?'
            : 'UPDATE ai_rag_chunks SET embedding = ? WHERE id = ?';

        DB::update($sql, [$vector, $chunk->id]);
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
