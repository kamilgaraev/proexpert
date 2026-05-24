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

        try {
            $embedding = $this->embeddingProvider->embed(
                $chunk->content,
                RagEmbeddingProviderInterface::PURPOSE_DOCUMENT
            );
        } catch (Throwable $throwable) {
            Log::warning('ai_assistant.rag.embedding_failed', [
                'organization_id' => $chunk->organizationId,
                'project_id' => $chunk->projectId,
                'source_type' => $chunk->sourceType,
                'entity_type' => $chunk->entityType,
                'entity_id' => (string) $chunk->entityId,
                'exception_class' => $throwable::class,
            ]);

            throw $throwable;
        }

        $vector = $this->vectorLiteral($embedding);

        DB::transaction(function () use ($chunk, $checksum, $vector): void {
            $source = RagSource::query()->updateOrCreate(
                [
                    'organization_id' => $chunk->organizationId,
                    'source_type' => $chunk->sourceType,
                    'entity_type' => $chunk->entityType,
                    'entity_id' => (string) $chunk->entityId,
                ],
                [
                    'project_id' => $chunk->projectId,
                    'title' => $chunk->title,
                    'checksum' => $checksum,
                    'metadata' => $chunk->metadata,
                    'indexed_at' => now(),
                ]
            );

            $source->chunks()->delete();

            $ragChunk = RagChunk::query()->create([
                'source_id' => $source->id,
                'organization_id' => $chunk->organizationId,
                'project_id' => $chunk->projectId,
                'chunk_index' => 0,
                'content' => $chunk->content,
                'content_hash' => hash('sha256', $this->normalizeText($chunk->content)),
                'metadata' => $chunk->metadata,
                'embedding_provider' => $this->embeddingProvider->provider(),
                'embedding_model' => $this->embeddingProvider->model(),
                'embedding_created_at' => now(),
            ]);

            $this->storeVector($ragChunk, $vector);
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
