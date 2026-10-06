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
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SiteRequestRagSource;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

class RagIndexerTest extends TestCase
{
    public function test_dimension_mismatch_preserves_the_existing_index(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $chunk = $this->chunk($organizationId, $projectId, 'original content');
        (new RagIndexer(new RecordingEmbeddingProvider([1.0]), new RagSourceRegistry([])))->indexChunk($chunk);
        $beforeSources = RagSource::query()->get()->toArray();
        $beforeChunks = DB::table('ai_rag_chunks')->get()->toArray();
        $provider = new class implements RagEmbeddingProviderInterface {
            public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array { return array_fill(0, 1024, 0.1); }
            public function provider(): string { return 'fake'; }
            public function model(): string { return 'fake-model'; }
            public function dimensions(): int { return 256; }
        };
        try {
            (new RagIndexer($provider, new RagSourceRegistry([])))->indexChunk($this->chunk($organizationId, $projectId, 'changed content'));
            $this->fail('An invalid profile must not replace the existing index.');
        } catch (\App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingDimensionMismatch $exception) {
            $this->assertSame(1024, $exception->actual);
        }
        $this->assertSame($beforeSources, RagSource::query()->get()->toArray());
        $this->assertEquals($beforeChunks, DB::table('ai_rag_chunks')->get()->toArray());
    }

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

    public function test_refreshes_source_metadata_without_reembedding_unchanged_chunks(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $original = $this->chunk($organizationId, $projectId, 'Контекст без изменений');
        $indexer->indexChunk($original);
        $source = RagSource::query()->firstOrFail();
        $storedChunk = RagChunk::query()->firstOrFail();
        $updatedAt = now()->addMinute();
        $updated = $this->chunk(
            $organizationId,
            $projectId,
            'Контекст без изменений',
            title: 'Новое название',
            metadata: ['status' => 'active', 'access_user_ids' => [17], 'provenance' => 'source-v2'],
            updatedAt: $updatedAt
        );

        $indexer->indexChunk($updated);

        $source->refresh();
        $storedChunk->refresh();
        $this->assertSame(1, $provider->calls);
        $this->assertSame(1, RagSource::query()->count());
        $this->assertSame(1, RagChunk::query()->count());
        $this->assertSame($source->id, RagSource::query()->value('id'));
        $this->assertSame($storedChunk->id, RagChunk::query()->value('id'));
        $this->assertSame('Новое название', $source->title);
        $this->assertSame($updatedAt->format(DateTimeInterface::ATOM), $source->source_version);
        $sourceMetadata = $source->metadata;
        $chunkMetadata = $storedChunk->metadata;
        ksort($sourceMetadata);
        ksort($chunkMetadata);
        $this->assertSame(['access_user_ids' => [17], 'provenance' => 'source-v2', 'status' => 'active'], $sourceMetadata);
        $this->assertSame(['access_user_ids' => [17], 'chunk_count' => 1, 'chunk_index' => 0, 'provenance' => 'source-v2', 'status' => 'active'], $chunkMetadata);
        $this->assertSame($original->content, $storedChunk->content);
        $this->assertSame($indexer->coverageIdentity($updated)['checksum'], $source->checksum);
    }

    public function test_reembeds_when_embedding_model_changes(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $sourceRegistry = new RagSourceRegistry([]);
        $originalProvider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $original = $this->chunk($organizationId, $projectId, 'Контекст модели');
        (new RagIndexer($originalProvider, $sourceRegistry))->indexChunk($original);
        $firstChunkId = RagChunk::query()->value('id');
        $updatedProvider = new RecordingEmbeddingProvider([0.4, 0.5, 0.6], modelName: 'new-model');

        (new RagIndexer($updatedProvider, $sourceRegistry))->indexChunk($original);

        $this->assertSame(1, $originalProvider->calls);
        $this->assertSame(1, $updatedProvider->calls);
        $this->assertNotSame($firstChunkId, RagChunk::query()->value('id'));
        $this->assertSame('new-model', RagChunk::query()->value('embedding_model'));
    }

    public function test_reembeds_when_chunking_changes_the_actual_embedding_inputs(): void
    {
        config()->set('ai-assistant.rag.chunk_chars', 80);
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $chunk = $this->chunk($organizationId, $projectId, str_repeat('a', 201));

        $indexer->indexChunk($chunk);
        config()->set('ai-assistant.rag.chunk_chars', 100);
        $indexer->indexChunk($chunk);

        $this->assertSame(4, $provider->calls);
        $this->assertSame(3, RagChunk::query()->count());
        $this->assertSame([100, 100, 1], RagChunk::query()->orderBy('chunk_index')->pluck('content')
            ->map(static fn (string $content): int => mb_strlen($content))->all());
    }

    public function test_repairs_only_the_missing_vector_and_reuses_valid_fragments(): void
    {
        config()->set('ai-assistant.rag.chunk_chars', 80);
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $chunk = $this->chunk($organizationId, $projectId, str_repeat('a', 80).str_repeat('b', 80).str_repeat('c', 41));
        $indexer->indexChunk($chunk);
        $missingVectorChunkId = (int) RagChunk::query()->where('chunk_index', 1)->value('id');

        DB::table('ai_rag_chunks')->where('id', $missingVectorChunkId)->update(['embedding' => null]);
        $indexer->indexChunk($chunk);

        $this->assertSame(4, $provider->calls);
        $this->assertSame(3, RagChunk::query()->count());
        $this->assertSame(0, RagChunk::query()->whereNull('embedding')->count());
        $this->assertNotSame($missingVectorChunkId, RagChunk::query()->where('chunk_index', 1)->value('id'));
    }

    public function test_partial_text_change_embeds_only_the_changed_fragment(): void
    {
        config()->set('ai-assistant.rag.chunk_chars', 80);
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $original = str_repeat('a', 80).str_repeat('b', 80).str_repeat('c', 41);
        $updated = str_repeat('a', 80).str_repeat('d', 80).str_repeat('c', 41);
        $indexer->indexChunk($this->chunk($organizationId, $projectId, $original));
        $indexer->indexChunk($this->chunk($organizationId, $projectId, $updated));

        $this->assertSame(4, $provider->calls);
        $this->assertSame([str_repeat('a', 80), str_repeat('d', 80), str_repeat('c', 41)], RagChunk::query()->orderBy('chunk_index')->pluck('content')->all());
    }

    public function test_retry_reuses_successful_fragments_after_a_later_embedding_failure(): void
    {
        config()->set('ai-assistant.rag.chunk_chars', 80);
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new class implements RagEmbeddingProviderInterface {
            public int $calls = 0;
            public bool $fail = true;
            public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
            {
                $this->calls++;
                if ($this->calls === 2 && $this->fail) {
                    throw new RuntimeException('embedding_interrupted');
                }
                return RagTestEmbedding::fromLeadingValues([0.1, 0.2, 0.3]);
            }
            public function provider(): string { return 'fake'; }
            public function model(): string { return 'fake-model'; }
            public function dimensions(): int { return RagTestEmbedding::DIMENSIONS; }
        };
        $chunk = $this->chunk($organizationId, $projectId, str_repeat('a', 80).str_repeat('b', 80).str_repeat('c', 41));
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        try {
            $indexer->indexChunk($chunk);
            $this->fail('Embedding failure must interrupt publication.');
        } catch (RuntimeException $exception) {
            $this->assertSame('embedding_interrupted', $exception->getMessage());
        }
        $this->assertSame(0, RagSource::query()->count());
        $this->assertSame(1, DB::table('ai_rag_embedding_checkpoints')->count());
        $provider->fail = false;
        $indexer->indexChunk($chunk);

        $this->assertSame(4, $provider->calls);
        $this->assertSame(3, RagChunk::query()->count());
        $this->assertSame(0, DB::table('ai_rag_embedding_checkpoints')->count());
    }

    public function test_cancelled_metadata_refresh_preserves_existing_source_and_chunk_metadata(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Сохраненный текст'));
        $source = RagSource::query()->firstOrFail();
        $storedChunk = RagChunk::query()->firstOrFail();
        $updated = $this->chunk(
            $organizationId,
            $projectId,
            'Сохраненный текст',
            title: 'Отмененное название',
            metadata: ['status' => 'changed', 'acl' => ['user_ids' => [25]]],
            updatedAt: now()->addMinute()
        );
        $guardCalls = 0;

        try {
            $indexer->indexChunk($updated, guard: static function () use (&$guardCalls): void {
                $guardCalls++;
                if ($guardCalls === 2) {
                    throw new RuntimeException('indexing_cancelled');
                }
            });
            $this->fail('Cancellation guard must abort the metadata refresh.');
        } catch (RuntimeException $exception) {
            $this->assertSame('indexing_cancelled', $exception->getMessage());
        }

        $source->refresh();
        $storedChunk->refresh();
        $this->assertSame(1, $provider->calls);
        $this->assertSame('Проект Литер А', $source->title);
        $this->assertSame(['status' => 'active'], $source->metadata);
        $this->assertSame('Сохраненный текст', $storedChunk->content);
        $chunkMetadata = $storedChunk->metadata;
        ksort($chunkMetadata);
        $this->assertSame(['chunk_count' => 1, 'chunk_index' => 0, 'status' => 'active'], $chunkMetadata);
        $this->assertNotNull($storedChunk->getRawOriginal('embedding'));
    }

    public function test_cancelled_text_refresh_keeps_old_chunks_and_vectors(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Старый текст'));
        $source = RagSource::query()->firstOrFail();
        $storedChunk = RagChunk::query()->firstOrFail();
        $updated = $this->chunk(
            $organizationId,
            $projectId,
            'Новый текст',
            title: 'Новое название',
            metadata: ['status' => 'changed'],
            updatedAt: now()->addMinute()
        );
        $guardCalls = 0;

        try {
            $indexer->indexChunk($updated, guard: static function () use (&$guardCalls): void {
                $guardCalls++;
                if ($guardCalls === 3) {
                    throw new RuntimeException('indexing_cancelled');
                }
            });
            $this->fail('Cancellation guard must abort the chunk replacement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('indexing_cancelled', $exception->getMessage());
        }

        $source->refresh();
        $storedChunk->refresh();
        $this->assertSame(2, $provider->calls);
        $this->assertSame(1, RagChunk::query()->count());
        $this->assertSame('Старый текст', $storedChunk->content);
        $this->assertSame('Проект Литер А', $source->title);
        $this->assertSame(['status' => 'active'], $source->metadata);
        $this->assertNotNull($storedChunk->getRawOriginal('embedding'));
        $indexer->indexChunk($updated);
        $this->assertSame(2, $provider->calls);
        $this->assertSame('Новый текст', RagChunk::query()->firstOrFail()->content);
        $this->assertSame(0, DB::table('ai_rag_embedding_checkpoints')->count());
    }

    public function test_vector_is_stored_with_bound_parameters(): void
    {
        [$organizationId, $projectId] = $this->seedScope();
        $provider = new RecordingEmbeddingProvider([0.1, 0.2, 0.3]);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'ai_rag_chunks') && preg_match('/^(insert|update)/i', $query->sql)) {
                $queries[] = [$query->sql, $query->bindings];
            }
        });

        $indexer->indexChunk($this->chunk($organizationId, $projectId, 'Контекст для вектора'));

        $this->assertCount(1, $queries);
        [$sql, $bindings] = $queries[0];
        $this->assertStringStartsWith('insert into', strtolower($sql));
        $this->assertStringContainsString('"embedding"', $sql);
        $this->assertStringNotContainsString('[0.1,0.2,0.3]', $sql);
        $vectorBindings = array_values(array_filter($bindings, static fn ($value): bool => is_string($value) && str_starts_with($value, '[')));
        $this->assertCount(1, $vectorBindings);
        $boundEmbedding = json_decode($vectorBindings[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($boundEmbedding);
        $this->assertCount(RagTestEmbedding::DIMENSIONS, $boundEmbedding);
        $this->assertSame([0.1, 0.2, 0.3], array_slice($boundEmbedding, 0, 3));
        $this->assertSame([0], array_values(array_unique(array_slice($boundEmbedding, 3))));
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

    public function test_site_request_organization_reindex_prunes_persisted_draft_and_deleted_sources(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $pending = $this->siteRequest($organization, $project, $user, SiteRequestStatusEnum::PENDING, 'Pending request');
        $draft = $this->siteRequest($organization, $project, $user, SiteRequestStatusEnum::DRAFT, 'Private draft');
        $deleted = $this->siteRequest($organization, $project, $user, SiteRequestStatusEnum::PENDING, 'Deleted request');
        $pendingSource = $this->persistedSiteRequestSource($pending);
        $draftSource = $this->persistedSiteRequestSource($draft);
        $deletedSource = $this->persistedSiteRequestSource($deleted);
        $deleted->delete();
        $indexer = new RagIndexer(
            new RecordingEmbeddingProvider([0.1, 0.2, 0.3]),
            new RagSourceRegistry([new SiteRequestRagSource])
        );

        $indexer->indexOrganization($organization->id, null, 'site_request');

        $this->assertDatabaseHas('ai_rag_sources', ['id' => $pendingSource->id]);
        $this->assertDatabaseMissing('ai_rag_sources', ['id' => $draftSource->id]);
        $this->assertDatabaseMissing('ai_rag_sources', ['id' => $deletedSource->id]);
        $this->assertDatabaseMissing('ai_rag_chunks', ['source_id' => $draftSource->id]);
        $this->assertDatabaseMissing('ai_rag_chunks', ['source_id' => $deletedSource->id]);
    }

    public function test_site_request_entity_reindex_removes_exact_source_when_entity_becomes_draft(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $request = $this->siteRequest($organization, $project, $user, SiteRequestStatusEnum::PENDING, 'Request to hide');
        $sibling = $this->siteRequest($organization, $project, $user, SiteRequestStatusEnum::PENDING, 'Visible sibling');
        $indexer = new RagIndexer(
            new RecordingEmbeddingProvider([0.1, 0.2, 0.3]),
            new RagSourceRegistry([new SiteRequestRagSource])
        );
        $indexer->indexEntity($organization->id, 'site_request', 'site_request', $request->id);
        $indexer->indexEntity($organization->id, 'site_request', 'site_request', $sibling->id);
        $requestSource = RagSource::query()->where('entity_id', (string) $request->id)->firstOrFail();
        $siblingSource = RagSource::query()->where('entity_id', (string) $sibling->id)->firstOrFail();

        $request->update(['status' => SiteRequestStatusEnum::DRAFT->value]);
        $indexed = $indexer->indexEntity($organization->id, 'site_request', 'site_request', $request->id);

        $this->assertSame(0, $indexed);
        $this->assertDatabaseMissing('ai_rag_sources', ['id' => $requestSource->id]);
        $this->assertDatabaseMissing('ai_rag_chunks', ['source_id' => $requestSource->id]);
        $this->assertDatabaseHas('ai_rag_sources', ['id' => $siblingSource->id]);
        $this->assertDatabaseHas('ai_rag_chunks', ['source_id' => $siblingSource->id]);
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
        string|int|null $entityId = null,
        ?string $title = null,
        ?array $metadata = null,
        ?DateTimeInterface $updatedAt = null
    ): RagChunkData {
        $entityId ??= $projectId;

        return new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: $sourceType,
            entityType: $entityType,
            entityId: $entityId,
            title: $title ?? 'Проект Литер А',
            content: $content,
            metadata: $metadata ?? ['status' => 'active'],
            updatedAt: $updatedAt ?? now()
        );
    }

    private function siteRequest(
        Organization $organization,
        Project $project,
        User $user,
        SiteRequestStatusEnum $status,
        string $title
    ): SiteRequest {
        return SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => $title,
            'request_type' => 'material_request',
            'status' => $status->value,
            'priority' => 'medium',
            'material_name' => 'Concrete',
            'material_quantity' => 1,
            'material_unit' => 'm3',
        ]);
    }

    private function persistedSiteRequestSource(SiteRequest $request): RagSource
    {
        $source = RagSource::query()->create([
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'source_type' => 'site_request',
            'entity_type' => 'site_request',
            'entity_id' => (string) $request->id,
            'title' => $request->title,
            'checksum' => 'persisted-'.uniqid(),
            'metadata' => ['status' => $request->status->value],
            'indexed_at' => now(),
        ]);

        RagChunk::query()->create([
            'source_id' => $source->id,
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'chunk_index' => 0,
            'content' => 'Persisted private content',
            'content_hash' => hash('sha256', 'Persisted private content'),
            'metadata' => ['status' => $request->status->value],
            'embedding_provider' => 'fake',
            'embedding_model' => 'fake-model',
            'embedding_created_at' => now(),
        ]);

        return $source;
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
    ) {}

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
    private readonly array $embedding;

    public function __construct(array $embedding, private readonly string $modelName = 'fake-model')
    {
        $this->embedding = RagTestEmbedding::fromLeadingValues($embedding);
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
        return $this->modelName;
    }

    public function dimensions(): int
    {
        return RagTestEmbedding::DIMENSIONS;
    }
}
