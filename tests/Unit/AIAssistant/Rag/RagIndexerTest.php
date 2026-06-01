<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RagIndexerTest extends TestCase
{
    public function test_indexes_new_source_skips_unchanged_source_and_replaces_changed_chunks(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));

        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Первый контекст'));

        $this->assertSame(1, RagSource::query()->count());
        $this->assertSame(1, RagChunk::query()->count());
        $this->assertSame(1, $provider->calls);
        $this->assertSame(RagEmbeddingProviderInterface::PURPOSE_DOCUMENT, $provider->lastPurpose);
        $firstChunkId = RagChunk::query()->value('id');

        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Первый контекст'));

        $this->assertSame(1, RagSource::query()->count());
        $this->assertSame(1, RagChunk::query()->count());
        $this->assertSame(1, $provider->calls);
        $this->assertSame($firstChunkId, RagChunk::query()->value('id'));

        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Обновленный контекст'));

        $this->assertSame(1, RagSource::query()->count());
        $this->assertSame(1, RagChunk::query()->count());
        $this->assertSame(2, $provider->calls);
        $this->assertNotSame($firstChunkId, RagChunk::query()->value('id'));
        $this->assertSame('Обновленный контекст', RagChunk::query()->value('content'));
    }

    public function test_vector_is_stored_with_bound_parameters(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'ai_rag_chunks SET embedding')) {
                $queries[] = [$query->sql, $query->bindings];
            }
        });

        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Контекст для вектора'));

        $this->assertNotEmpty($queries);
        [$sql, $bindings] = $queries[0];
        $this->assertStringContainsString('embedding = ?', $sql);
        $this->assertStringNotContainsString('[0.1,0.2,0.3]', $sql);
        $this->assertSame('[0.1,0.2,0.3]', $bindings[0]);
    }

    public function test_indexes_selected_enabled_source_type_for_organization(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $projectCollector = new RecordingRagCollector('project', true, [
            $this->chunk($organizationId, $projectId, 'project context'),
        ]);
        $scheduleCollector = new RecordingRagCollector('schedule', true, [
            $this->chunk($organizationId, $projectId, 'schedule context', 'schedule', 'schedule', 200),
        ]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([
            $projectCollector,
            $scheduleCollector,
        ]));

        $indexed = $indexer->indexOrganization($organizationId, $projectId, 'schedule');

        $this->assertSame(1, $indexed);
        $this->assertSame([], $projectCollector->calls);
        $this->assertSame([[$organizationId, $projectId]], $scheduleCollector->calls);
        $this->assertSame(1, RagSource::query()->where('source_type', 'schedule')->count());
        $this->assertSame(1, $provider->calls);
    }

    public function test_indexes_long_content_into_multiple_chunks(): void
    {
        config()->set('ai-assistant.rag.chunk_chars', 80);

        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));

        $indexer->indexChunk(new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: 'project',
            entityType: 'project',
            entityId: $projectId,
            title: 'Большой проект',
            content: implode("\n\n", [
                'Первый длинный блок про сроки и закупки.',
                'Второй длинный блок про оплату и подрядчика.',
                'Третий длинный блок про качество и замечания.',
            ]),
            metadata: ['test' => true],
            updatedAt: now()
        ));

        $this->assertDatabaseCount('ai_rag_chunks', 3);
        $this->assertDatabaseHas('ai_rag_chunks', [
            'chunk_index' => 0,
            'content' => 'Первый длинный блок про сроки и закупки.',
        ]);
        $this->assertDatabaseHas('ai_rag_chunks', [
            'chunk_index' => 1,
            'content' => 'Второй длинный блок про оплату и подрядчика.',
        ]);
        $this->assertDatabaseHas('ai_rag_chunks', [
            'chunk_index' => 2,
            'content' => 'Третий длинный блок про качество и замечания.',
        ]);
        $this->assertSame(3, $provider->calls);
    }

    public function test_truncates_source_title_to_database_limit(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $longTitle = str_repeat('Very long imported estimate section title ', 12);

        $indexer->indexChunk(new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: 'estimate',
            entityType: 'estimate_section',
            entityId: 987,
            title: $longTitle,
            content: 'estimate section content',
            metadata: ['estimate_id' => 123],
            updatedAt: now()
        ));

        $storedTitle = (string) RagSource::query()->value('title');

        $this->assertLessThanOrEqual(255, mb_strlen($storedTitle));
        $this->assertStringStartsWith('Very long imported estimate section title', $storedTitle);
        $this->assertStringEndsWith('...', $storedTitle);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedScope(): array
    {
        $organizationId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Тестовая организация',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectId = (int) DB::table('projects')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Литер А',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organizationId, $projectId];
    }

    private function chunk(
        int $organizationId,
        int $projectId,
        string $content,
        string $sourceType = 'project',
        string $entityType = 'project',
        string|int|null $entityId = null
    ): RagChunkData {
        $entityId ??= $projectId;

        return new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: $sourceType,
            entityType: $entityType,
            entityId: $entityId,
            title: 'Проект Литер А',
            content: $content,
            metadata: ['status' => 'active'],
            updatedAt: now()
        );
    }
}

final class RecordingRagCollector implements RagSourceCollectorInterface
{
    /**
     * @var array<int, array{0: int, 1: int|null}>
     */
    public array $calls = [];

    /**
     * @param  array<int, RagChunkData>  $chunks
     */
    public function __construct(
        private readonly string $sourceType,
        private readonly bool $enabled,
        private readonly array $chunks
    ) {
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $this->calls[] = [$organizationId, $projectId];

        return $this->chunks;
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return [];
    }
}

final class RecordingEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public int $calls = 0;

    public ?string $lastPurpose = null;

    /**
     * @param  array<int, float>  $embedding
     */
    public function __construct(private readonly array $embedding)
    {
    }

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->calls++;
        $this->lastPurpose = $purpose;

        return $this->embedding;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function dimensions(): int
    {
        return count($this->embedding);
    }
}
