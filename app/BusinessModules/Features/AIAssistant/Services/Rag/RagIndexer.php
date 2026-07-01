<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class RagIndexer
{
    public function __construct(
        private readonly RagEmbeddingProviderInterface $embeddingProvider,
        private readonly RagSourceRegistry $sourceRegistry
    ) {
    }

    public function indexChunk(RagChunkData $chunk): void
    {
        $checksum = $this->checksum($chunk);
        $existing = RagSource::query()
            ->where('organization_id', $chunk->organizationId)
            ->where('source_type', $chunk->sourceType)
            ->where('entity_type', $chunk->entityType)
            ->where('entity_id', (string) $chunk->entityId)
            ->first();

        if ($existing instanceof RagSource && $existing->checksum === $checksum) {
            return;
        }

        $contentChunks = $this->splitContent($chunk->content);
        if ($contentChunks === []) {
            return;
        }

        $embeddedChunks = $this->embedContentChunks($chunk, $contentChunks);

        DB::transaction(function () use ($chunk, $checksum, $embeddedChunks): void {
            $source = RagSource::query()->updateOrCreate(
                [
                    'organization_id' => $chunk->organizationId,
                    'source_type' => $chunk->sourceType,
                    'entity_type' => $chunk->entityType,
                    'entity_id' => (string) $chunk->entityId,
                ],
                [
                    'project_id' => $chunk->projectId,
                    'title' => $this->sourceTitle($chunk->title),
                    'checksum' => $checksum,
                    'metadata' => $chunk->metadata,
                    'indexed_at' => now(),
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

    public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null): int
    {
        $collectors = $sourceType === null
            ? $this->sourceRegistry->enabledCollectors()
            : $this->collectorForSourceType($sourceType);
        $indexed = 0;

        foreach ($collectors as $collector) {
            foreach ($collector->collectForOrganization($organizationId, $projectId) as $chunk) {
                $this->indexChunk($chunk);
                $indexed++;
            }

            if ($collector instanceof RagSourcePrunerInterface) {
                $collector->pruneForOrganization($organizationId, $projectId);
            }
        }

        return $indexed;
    }

    public function indexEntity(
        int $organizationId,
        ?string $sourceType,
        string $entityType,
        string|int $entityId
    ): int {
        if ($sourceType === null) {
            return 0;
        }

        $collector = $this->sourceRegistry->collector($sourceType);
        if (! $collector instanceof RagSourceCollectorInterface || ! $collector->enabled()) {
            return 0;
        }

        $indexed = 0;

        foreach ($collector->collectEntity($organizationId, $entityType, $entityId) as $chunk) {
            $this->indexChunk($chunk);
            $indexed++;
        }

        return $indexed;
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

    private function checksum(RagChunkData $chunk): string
    {
        $payload = [
            'title' => $this->normalizeText($chunk->title),
            'content' => $this->normalizeText($chunk->content),
            'metadata' => $this->normalizeValue($chunk->metadata),
        ];

        return hash('sha256', $this->json($payload));
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

            $embeddedChunks[] = [
                'content' => $content,
                'vector' => $this->vectorLiteral($embedding),
            ];
        }

        return $embeddedChunks;
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
