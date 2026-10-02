<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Models\Project;
use App\Services\Project\UserProjectAccessService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class RagEmbeddingCompatibilityTest extends TestCase
{
    public function test_native_qwen_vectors_coexist_with_unchanged_legacy_vectors(): void
    {
        [$fixture, $project] = $this->scope();
        $legacy = new CompatibilityEmbeddingProvider('timeweb', 'openai/text-embedding-3-large', 256);
        $oldChunk = $this->chunk($project);
        $this->indexer($legacy)->indexChunk($oldChunk);
        $oldSource = RagSource::query()->where('entity_id', $project->id)->firstOrFail();
        $oldVector = DB::table('ai_rag_chunks')->where('source_id', $oldSource->id)->value('embedding');
        $newProject = Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]);
        $qwen = new CompatibilityEmbeddingProvider('timeweb', 'dashscope/text-embedding-v4', 1024);
        $this->indexer($qwen)->indexChunk($this->chunk($newProject));

        $this->assertSame($oldVector, DB::table('ai_rag_chunks')->where('source_id', $oldSource->id)->value('embedding'));
        $this->assertSame([256, 1024], DB::table('ai_rag_chunks')->orderBy('id')->selectRaw('vector_dims(embedding) AS dimensions')->pluck('dimensions')->all());
        $retriever = new RagRetriever($qwen, app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
        $result = $retriever->search('needle semantic', $fixture->organization->id, $fixture->owner);
        $this->assertSame([(string) $newProject->id], array_map(static fn ($row): string => (string) $row->entityId, $result));
        $oldResults = (new RagRetriever($legacy, app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class)))
            ->search('needle semantic', $fixture->organization->id, $fixture->owner);
        $this->assertSame([(string) $project->id], array_map(static fn ($row): string => (string) $row->entityId, $oldResults));
        $callsBeforeReindexing = $legacy->calls;
        $this->indexer($legacy)->indexChunk($oldChunk);
        $this->assertSame($callsBeforeReindexing, $legacy->calls);
    }

    public function test_mixed_dimension_migration_preserves_vectors_and_blocks_destructive_rollback(): void
    {
        [, $project] = $this->scope();
        $this->indexer(new CompatibilityEmbeddingProvider('timeweb', 'openai/text-embedding-3-large', 256))->indexChunk($this->chunk($project));
        $before = DB::table('ai_rag_chunks')->orderBy('id')->get()->toArray();
        $migration = require base_path('app/BusinessModules/Features/AIAssistant/migrations/2026_10_02_000001_allow_mixed_ai_rag_embedding_dimensions.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($before, DB::table('ai_rag_chunks')->orderBy('id')->get()->toArray());
        $indexes = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', ['ai_rag_chunks']);
        $this->assertContains('ai_rag_chunks_embedding_256_hnsw_idx', array_column($indexes, 'indexname'));
        $this->assertContains('ai_rag_chunks_embedding_1024_hnsw_idx', array_column($indexes, 'indexname'));
        DB::table('ai_rag_chunks')->update(['embedding' => '['.implode(',', array_fill(0, 1024, '0.1')).']']);
        try {
            $migration->down();
            $this->fail('Rollback must preserve native Qwen vectors.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rag_mixed_embedding_dimensions_prevent_rollback', $exception->getMessage());
        }
        $this->assertSame(1024, (int) DB::table('ai_rag_chunks')->selectRaw('vector_dims(embedding) AS dimensions')->value('dimensions'));
    }

    public function test_compatible_legacy_checksum_is_upgraded_without_another_embedding_call(): void
    {
        [$fixture, $project] = $this->scope();
        $provider = new CompatibilityEmbeddingProvider('current', 'fixture-model', 256);
        $indexer = $this->indexer($provider);
        $chunk = $this->chunk($project);
        $indexer->indexChunk($chunk);
        $source = RagSource::query()->where('entity_id', (string) $project->id)->firstOrFail();
        $fingerprinted = $source->checksum;
        $legacy = $this->legacyChecksum($chunk);
        $source->update(['checksum' => $legacy]);
        $firstChunkId = $source->chunks()->firstOrFail()->id;

        self::assertTrue($indexer->matchesSource($source->fresh(), $chunk));
        $indexer->indexChunk($chunk);
        self::assertSame(1, $provider->calls);
        self::assertSame($fingerprinted, $source->fresh()->checksum);
        self::assertNotSame($legacy, $source->fresh()->checksum);
        self::assertSame($firstChunkId, $source->chunks()->firstOrFail()->id);
        $indexer->indexChunk($chunk);
        self::assertSame(1, $provider->calls);
    }

    #[DataProvider('changedFingerprints')]
    public function test_unchanged_text_requires_reindex_when_embedding_fingerprint_changes(string $providerName, string $model, int $dimensions): void
    {
        if ($dimensions !== 256) $this->allowMixedVectorDimensions();
        [$fixture, $project] = $this->scope();
        $old = new CompatibilityEmbeddingProvider('current', 'fixture-model', 256);
        $chunk = $this->chunk($project);
        $this->indexer($old)->indexChunk($chunk);
        $source = RagSource::query()->where('entity_id', (string) $project->id)->firstOrFail();
        $source->update(['checksum' => $this->legacyChecksum($chunk)]);
        $oldId = $source->chunks()->firstOrFail()->id;
        $new = new CompatibilityEmbeddingProvider($providerName, $model, $dimensions);
        $indexer = $this->indexer($new);

        self::assertFalse($indexer->matchesSource($source->fresh(), $chunk));
        $indexer->indexChunk($chunk);
        self::assertSame(1, $new->calls);
        self::assertNotSame($oldId, $source->chunks()->firstOrFail()->id);
        self::assertTrue($indexer->matchesSource($source->fresh(), $chunk));
        $indexer->indexChunk($chunk);
        self::assertSame(1, $new->calls);
    }

    public static function changedFingerprints(): array
    {
        return [['another-provider', 'fixture-model', 256], ['current', 'another-model', 256], ['current', 'fixture-model', 128]];
    }

    public function test_empty_or_inconsistent_chunks_are_not_compatible_even_with_matching_text_checksum(): void
    {
        [$fixture, $project] = $this->scope();
        $provider = new CompatibilityEmbeddingProvider('current', 'fixture-model', 256);
        $indexer = $this->indexer($provider);
        $chunk = $this->chunk($project);
        $indexer->indexChunk($chunk);
        $source = RagSource::query()->where('entity_id', (string) $project->id)->firstOrFail();
        $source->chunks()->update(['embedding_model' => 'obsolete']);
        self::assertFalse($indexer->matchesSource($source->fresh(), $chunk));
        $indexer->indexChunk($chunk);
        self::assertSame(2, $provider->calls);
        $source->chunks()->delete();
        self::assertFalse($indexer->matchesSource($source->fresh(), $chunk));
        $indexer->indexChunk($chunk);
        self::assertSame(3, $provider->calls);
    }

    public function test_semantic_search_excludes_mixed_providers_models_and_dimensions_in_both_paths(): void
    {
        $this->allowMixedVectorDimensions();
        [$fixture, $project] = $this->scope();
        $current = new CompatibilityEmbeddingProvider('current', 'fixture-model', 256);
        $this->indexer($current)->indexChunk($this->chunk($project));
        foreach ([['old-provider', 'fixture-model', 256], ['current', 'old-model', 256], ['current', 'fixture-model', 128]] as [$name, $model, $dimension]) {
            $other = Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]);
            $this->indexer(new CompatibilityEmbeddingProvider($name, $model, $dimension))->indexChunk($this->chunk($other));
        }
        $foreign = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id, 'is_archived' => false]);
        $this->indexer($current)->indexChunk($this->chunk($foreign));
        config()->set('ai-assistant.rag.max_chunks', 1);
        config()->set('ai-assistant.rag.min_similarity', 0.1);
        $policy = app(AssistantDataAccessPolicy::class);
        $retriever = new RagRetriever($current, app(UserProjectAccessService::class), $policy);
        $results = $retriever->search('needle semantic', $fixture->organization->id, $fixture->owner);
        self::assertCount(1, $results);
        self::assertSame((string) $project->id, (string) $results[0]->entityId);

        $accessible = $policy->applyToSources(RagSource::query(), $fixture->owner, $fixture->organization->id)->select('ai_rag_sources.id');
        $projectIds = Project::query()->where('organization_id', $fixture->organization->id)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $rows = (new ReflectionMethod(RagRetriever::class, 'fallbackRows'))->invoke($retriever, $current->embed('query'), $current,
            $fixture->organization->id, 1, ['project'], $projectIds, null, false, $accessible);
        self::assertCount(1, $rows);
        self::assertSame((string) $project->id, (string) $rows->first()->entity_id);
    }

    public function test_lexical_fallback_can_read_accessible_legacy_text_without_compatible_vectors(): void
    {
        [$fixture, $project] = $this->scope();
        $this->indexer(new CompatibilityEmbeddingProvider('obsolete', 'old-model', 256))->indexChunk($this->chunk($project, 'legacyunique text'));
        $source = RagSource::query()->where('entity_id', (string) $project->id)->firstOrFail();
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update(['embedding' => null]);
        $provider = new CompatibilityEmbeddingProvider('current', 'fixture-model', 256, true);
        $retriever = new RagRetriever($provider, app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
        $results = $retriever->search('legacyunique', $fixture->organization->id, $fixture->owner);
        self::assertCount(1, $results);
        self::assertSame((string) $project->id, (string) $results[0]->entityId);
    }

    public function test_generic_semantic_search_keeps_organization_sources_when_file_corpus_is_absent(): void
    {
        [$fixture, $project] = $this->scope();
        $contractor = \App\Models\Contractor::withoutEvents(fn () => \App\Models\Contractor::create([
            'organization_id' => $fixture->organization->id, 'name' => 'Organization supplier', 'contractor_type' => 'manual',
        ]));
        $provider = new CompatibilityEmbeddingProvider('current', 'fixture-model', 256);
        $this->indexer($provider)->indexChunk(new RagChunkData($fixture->organization->id, null,
            AssistantDataAccessPolicy::entityDefinitions()['contractor'][0], 'contractor', $contractor->id, 'Organization supplier', 'Unrelated lexical words'));
        $retriever = new RagRetriever($provider, app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
        $results = $retriever->search('needle semantic', $fixture->organization->id, $fixture->owner, ['project_id' => $project->id]);
        self::assertCount(1, $results);
        self::assertSame('contractor', $results[0]->entityType);
        self::assertSame((string) $contractor->id, $results[0]->entityId);
    }

    public function test_search_diagnostics_distinguish_embedding_failure_from_genuine_empty_results(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        [$fixture, $project] = $this->scope();
        $this->indexer(new CompatibilityEmbeddingProvider('current', 'fixture-model', 256))->indexChunk($this->chunk($project, 'legacyunique text'));
        $failed = new RagRetriever(new CompatibilityEmbeddingProvider('current', 'fixture-model', 256, true),
            app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
        $partial = $failed->searchWithDiagnostics('legacyunique', $fixture->organization->id, $fixture->owner);
        self::assertCount(1, $partial['results']);
        $failedProfile = json_encode(['current', 'fixture-model', 256], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertSame(['status' => 'partial', 'error_code' => 'rag_query_embedding_partial_failure',
            'semantic_available' => false, 'lexical_used' => true, 'failed_embedding_profiles' => [$failedProfile]], $partial['diagnostics']);
        $unavailable = $failed->searchWithDiagnostics('absentunique', $fixture->organization->id, $fixture->owner);
        self::assertSame([], $unavailable['results']);
        self::assertSame(['status' => 'unavailable', 'error_code' => 'rag_query_embedding_partial_failure',
            'semantic_available' => false, 'lexical_used' => true, 'failed_embedding_profiles' => [$failedProfile]], $unavailable['diagnostics']);
        config()->set('ai-assistant.rag.min_similarity', 2.0);
        $healthy = new RagRetriever(new CompatibilityEmbeddingProvider('current', 'fixture-model', 256),
            app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
        $empty = $healthy->searchWithDiagnostics('absentunique', $fixture->organization->id, $fixture->owner);
        self::assertSame([], $empty['results']);
        self::assertSame(['status' => 'available', 'error_code' => null, 'semantic_available' => true, 'lexical_used' => true], $empty['diagnostics']);
        foreach ([new \App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled,
            new \App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded,
            new \App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded,
            new \Illuminate\Database\QueryException('pgsql', 'fixture', [], new RuntimeException('fixture_sql_timeout'))] as $failure) {
            $technical = new RagRetriever(new CompatibilityEmbeddingProvider('current', 'fixture-model', 256, $failure),
                app(UserProjectAccessService::class), app(AssistantDataAccessPolicy::class));
            $caught = null;
            try { $technical->searchWithDiagnostics('legacyunique', $fixture->organization->id, $fixture->owner); }
            catch (\Throwable $exception) { $caught = $exception; }
            self::assertSame($failure, $caught);
        }
    }

    private function scope(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]);
        self::assertContains('project', app(AssistantDataAccessPolicy::class)->allowedSourceTypes($fixture->owner, $fixture->organization->id));

        return [$fixture, $project];
    }

    private function chunk(Project $project, string $content = 'kept text'): RagChunkData
    {
        return new RagChunkData($project->organization_id, $project->id, 'project', 'project', $project->id, 'Compatibility fixture', $content);
    }

    private function indexer(CompatibilityEmbeddingProvider $provider): RagIndexer
    {
        return new RagIndexer($provider, new RagSourceRegistry([]));
    }

    private function legacyChecksum(RagChunkData $chunk): string
    {
        return hash('sha256', json_encode(['title' => $chunk->title, 'content' => $chunk->content, 'metadata' => $chunk->metadata,
            'updated_at' => null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function allowMixedVectorDimensions(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        self::assertGreaterThan(0, DB::connection()->transactionLevel());
        DB::statement('DROP INDEX IF EXISTS ai_rag_chunks_embedding_hnsw_idx');
        DB::statement('ALTER TABLE ai_rag_chunks ALTER COLUMN embedding TYPE vector USING embedding::vector');
    }
}

final class CompatibilityEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $providerName, private readonly string $modelName, private readonly int $vectorDimensions, private readonly bool|\Throwable $fail = false) {}

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        if ($this->fail instanceof \Throwable) throw $this->fail;
        if ($this->fail) throw new RuntimeException('synthetic_embedding_failure');
        $this->calls++;

        return array_pad([1.0], $this->vectorDimensions, 0.0);
    }

    public function provider(): string { return $this->providerName; }
    public function model(): string { return $this->modelName; }
    public function dimensions(): int { return $this->vectorDimensions; }
}
