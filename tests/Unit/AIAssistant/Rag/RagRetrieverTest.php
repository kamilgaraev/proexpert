<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateGenerationLearningRagSource;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Enums\UserProjectAccessMode;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

class RagRetrieverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-assistant.rag.max_chunks', 8);
        config()->set('ai-assistant.rag.min_similarity', 0.1);
    }

    public function test_search_filters_by_organization_and_assigned_projects_and_orders_by_similarity(): void
    {
        [$organization, $user, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ASSIGNED_PROJECTS->value
        );
        $user->assignedProjects()->attach($projectA->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->indexChunk($organization->id, $projectA->id, 'Allowed close', 'allowed close content', [1.0, 0.0, 0.0]);
        $this->indexChunk($organization->id, $projectA->id, 'Allowed second', 'allowed second content', [0.8, 0.2, 0.0]);
        $this->indexChunk($organization->id, $projectB->id, 'Blocked project', 'blocked content', [1.0, 0.0, 0.0]);
        $this->indexChunk($foreignOrganization->id, $foreignProject->id, 'Foreign org', 'foreign content', [1.0, 0.0, 0.0]);

        $provider = new RetrieverEmbeddingProvider([1.0, 0.0, 0.0]);
        $results = $this->retriever($provider)->search('risk on project', $organization->id, $user);

        $this->assertCount(2, $results);
        $this->assertSame('Allowed close', $results[0]->title);
        $this->assertSame('Allowed second', $results[1]->title);
        $this->assertGreaterThanOrEqual($results[1]->similarity, $results[0]->similarity);
        $this->assertSame(RagEmbeddingProviderInterface::PURPOSE_QUERY, $provider->lastPurpose);
    }

    public function test_search_excludes_chunks_below_similarity_threshold(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );
        config()->set('ai-assistant.rag.min_similarity', 0.9);

        $this->indexChunk($organization->id, $project->id, 'Close source', 'close content', [1.0, 0.0, 0.0]);
        $this->indexChunk($organization->id, $project->id, 'Distant source', 'distant content', [0.0, 1.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->search('close query', $organization->id, $user);

        $this->assertCount(1, $results);
        $this->assertSame('Close source', $results[0]->title);
    }

    public function test_search_applies_request_project_filter_after_access_scope(): void
    {
        [$organization, $user, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ASSIGNED_PROJECTS->value
        );
        $user->assignedProjects()->attach($projectA->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->indexChunk($organization->id, $projectA->id, 'Allowed project', 'allowed content', [1.0, 0.0, 0.0]);
        $this->indexChunk($organization->id, $projectB->id, 'Blocked project', 'blocked content', [1.0, 0.0, 0.0]);

        $allowed = $this->retriever([1.0, 0.0, 0.0])->search(
            'project query',
            $organization->id,
            $user,
            ['project_id' => $projectA->id]
        );
        $blocked = $this->retriever([1.0, 0.0, 0.0])->search(
            'project query',
            $organization->id,
            $user,
            ['project_id' => $projectB->id]
        );

        $this->assertCount(1, $allowed);
        $this->assertSame('Allowed project', $allowed[0]->title);
        $this->assertSame([], $blocked);
    }

    public function test_search_uses_reference_sources_for_estimate_reference_question_in_project_context(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );

        $this->indexChunk(
            $organization->id,
            $project->id,
            'Смета: Бетонные работы',
            'Смета проекта содержит бетонные работы и позиции по фундаменту.',
            [1.0, 0.0, 0.0],
            'estimate',
            'estimate'
        );

        $this->indexChunk(
            $organization->id,
            null,
            'Позиция каталога: Укладка бетонной смеси',
            'Позиция сметного каталога: укладка бетонной смеси в фундаменты. Код: ФЕР06-01-001. Цена: 1250.00.',
            [1.0, 0.0, 0.0],
            'estimate_reference',
            'estimate_catalog_item'
        );

        $results = $this->retriever([1.0, 0.0, 0.0])->search(
            'Какие позиции или расценки из справочников можно использовать для бетонных работ?',
            $organization->id,
            $user,
            ['project_id' => $project->id]
        );

        $this->assertNotEmpty($results);
        $this->assertSame('estimate_reference', $results[0]->sourceType);
        $this->assertNotContains('estimate', array_map(static fn ($result): string => $result->sourceType, $results));
    }

    public function test_search_keeps_project_estimates_for_mixed_estimate_and_reference_query(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );

        $this->indexChunk(
            $organization->id,
            $project->id,
            'Смета: Фундамент',
            'Смета проекта содержит раздел Фундамент и позиции по бетонированию.',
            [1.0, 0.0, 0.0],
            'estimate',
            'estimate_section'
        );

        $results = $this->retriever([1.0, 0.0, 0.0])->search(
            'На основе существующих смет и нормативов что похоже на устройство фундамента?',
            $organization->id,
            $user,
            ['project_id' => $project->id]
        );

        $this->assertCount(1, $results);
        $this->assertSame('estimate', $results[0]->sourceType);
        $this->assertSame('estimate_section', $results[0]->entityType);
    }

    public function test_search_uses_lexical_fallback_when_vector_matches_are_below_threshold(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );
        config()->set('ai-assistant.rag.min_similarity', 0.95);

        $this->indexChunk(
            $organization->id,
            $project->id,
            'Договор: Тестовый-1',
            'Договор: Тестовый-1 Сумма: 10 500 000.00 Проект: Строительство склада Литер А',
            [0.0, 1.0, 0.0],
            'contract',
            'contract'
        );

        $results = $this->retriever([1.0, 0.0, 0.0])->search(
            'Какие договоры и суммы есть в контексте?',
            $organization->id,
            $user,
            ['project_id' => $project->id]
        );

        $this->assertCount(1, $results);
        $this->assertSame('Договор: Тестовый-1', $results[0]->title);
        $this->assertSame('contract', $results[0]->sourceType);
    }

    public function test_search_uses_lexical_fallback_when_query_embedding_fails(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );

        $this->indexChunk(
            $organization->id,
            $project->id,
            'Заявка: Срочная поставка бетона',
            'Заявка требует внимания: нужна срочная поставка бетона на площадку.',
            [1.0, 0.0, 0.0],
            'site_request',
            'site_request'
        );

        $retriever = new RagRetriever(
            new FailingRetrieverEmbeddingProvider,
            app(UserProjectAccessService::class)
        );

        $results = $retriever->search(
            'Какие заявки требуют внимания по данным из базы знаний?',
            $organization->id,
            $user,
            ['project_id' => $project->id]
        );

        $this->assertCount(1, $results);
        $this->assertSame('Заявка: Срочная поставка бетона', $results[0]->title);
        $this->assertSame('site_request', $results[0]->sourceType);
    }

    public function test_finance_query_expands_lexical_terms_to_payment_sources(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );

        $this->indexChunk(
            $organization->id,
            $project->id,
            'Платеж: INV-77',
            'Платежный документ INV-77. Сумма: 150 000.00. Оплачено: 50 000.00. Остаток: 100 000.00.',
            [1.0, 0.0, 0.0],
            'payment',
            'payment_document'
        );

        $retriever = new RagRetriever(
            new FailingRetrieverEmbeddingProvider,
            app(UserProjectAccessService::class)
        );

        $results = $retriever->search(
            'Собери короткую сводку по финансам',
            $organization->id,
            $user,
            ['project_id' => $project->id]
        );

        $this->assertCount(1, $results);
        $this->assertSame('Платеж: INV-77', $results[0]->title);
        $this->assertSame('payment', $results[0]->sourceType);
    }

    public function test_search_returns_compact_evidence_safe_excerpt(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );
        $content = str_repeat("Long line with useful evidence.\n", 30);

        $this->indexChunk($organization->id, $project->id, 'Long source', $content, [1.0, 0.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->search('long query', $organization->id, $user);

        $this->assertCount(1, $results);
        $this->assertLessThanOrEqual(360, mb_strlen($results[0]->excerpt));
        $this->assertStringNotContainsString("\n", $results[0]->excerpt);
        $this->assertStringContainsString('Long line with useful evidence.', $results[0]->excerpt);
    }

    public function test_learning_search_rechecks_current_rows_and_index_without_pruning(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(UserProjectAccessMode::ALL_PROJECTS->value);
        $foreignOrganization = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $examples = [];
        foreach (['valid_negative', 'deleted', 'do_not_index', 'untrusted', 'moved_org', 'moved_project', 'edited', 'tampered_content', 'tampered_metadata'] as $case) {
            $examples[$case] = $this->learningExample($organization->id, $project->id, $case);
            $this->indexLearning($examples[$case]);
        }
        $examples['valid_negative']->update(['is_positive' => false, 'decision_status' => 'rejected', 'source_type' => 'user_rejection']);
        $this->indexLearning($examples['valid_negative']);
        $examples['deleted']->delete();
        $examples['do_not_index']->update(['quality_flags' => ['do_not_index']]);
        $examples['untrusted']->update(['source_type' => 'ai_generated_estimate']);
        $examples['moved_org']->update(['organization_id' => $foreignOrganization->id]);
        $examples['moved_project']->update(['project_id' => $otherProject->id]);
        $examples['edited']->update(['work_name' => 'Changed authoritative example']);
        $this->learningSource($examples['tampered_content'])->chunks()->update(['content' => 'forged learning evidence']);
        $source = $this->learningSource($examples['tampered_metadata']);
        $chunk = $source->chunks()->firstOrFail();
        $chunk->update(['metadata' => [...$chunk->metadata, 'is_positive' => false]]);

        $results = $this->retriever([1.0, 0.0, 0.0])->search('learning evidence', $organization->id, $user,
            ['project_id' => $project->id, 'source_types' => ['estimate_generation_learning']]);

        self::assertSame([(string) $examples['valid_negative']->id], array_column($results, 'entityId'));
        self::assertFalse($results[0]->metadata['is_positive']);
        self::assertStringContainsString('evidence=negative', $results[0]->excerpt);
        self::assertSame(9, RagSource::query()->where('organization_id', $organization->id)->count());
    }

    #[DataProvider('learningCandidatePools')]
    public function test_learning_refill_finds_current_example_after_stale_pool_with_bounded_reads(string $mode, int $staleCount): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(UserProjectAccessMode::ALL_PROJECTS->value);
        foreach (range(1, $staleCount) as $index) {
            $example = $this->learningExample($organization->id, $project->id, 'learning evidence stale '.$index);
            $this->indexLearning($example);
            $example->update(['quality_flags' => ['do_not_index']]);
        }
        $valid = $this->learningExample($organization->id, $project->id, 'learning current');
        $valid->update(['normative_name' => 'learning']);
        $this->indexLearning($valid, [0.8, 0.2, 0.0]);
        $provider = new RetrieverEmbeddingProvider([1.0, 0.0, 0.0]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $results = $this->retriever($provider)->search('learning evidence', $organization->id, $user,
                ['project_id' => $project->id, 'source_types' => ['estimate_generation_learning'], 'retrieval_mode' => $mode]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        self::assertSame([(string) $valid->id], array_column($results, 'entityId'));
        $candidateQueries = $this->candidateQueries($queries);
        self::assertCount(2, $candidateQueries);
        if ($mode === 'lexical') {
            self::assertStringContainsString('limit 96', $candidateQueries[0]['query']);
            self::assertStringContainsString('limit 192', $candidateQueries[1]['query']);
        } else {
            self::assertSame(32, $candidateQueries[0]['bindings'][array_key_last($candidateQueries[0]['bindings'])]);
            self::assertSame(64, $candidateQueries[1]['bindings'][array_key_last($candidateQueries[1]['bindings'])]);
        }
        self::assertSame($mode === 'lexical' ? 0 : 1, $provider->queryCalls);
        $currentExampleReads = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "estimate_generation_learning_examples"') && str_starts_with($query['query'], 'select *'));
        self::assertCount(2, $currentExampleReads);
    }

    public static function learningCandidatePools(): iterable
    {
        yield 'semantic' => ['semantic', 32];
        yield 'lexical' => ['lexical', 96];
    }

    public function test_learning_long_split_is_current_and_healthy_search_does_not_refill(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(UserProjectAccessMode::ALL_PROJECTS->value);
        $example = $this->learningExample($organization->id, $project->id, str_repeat('learning evidence surface ', 180));
        $this->indexLearning($example);
        self::assertGreaterThan(1, $this->learningSource($example)->chunks()->count());
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $results = $this->retriever([1.0, 0.0, 0.0])->search('learning evidence', $organization->id, $user,
                ['project_id' => $project->id, 'source_types' => ['estimate_generation_learning']]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        self::assertNotEmpty($results);
        self::assertSame([(string) $example->id], array_values(array_unique(array_column($results, 'entityId'))));
        self::assertCount(1, $this->candidateQueries($queries));
        self::assertCount(1, array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "estimate_generation_learning_examples"') && str_starts_with($query['query'], 'select *')));
    }

    public function test_each_existing_embedding_profile_gets_one_refill_without_extra_embeddings(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(UserProjectAccessMode::ALL_PROJECTS->value);
        $first = new RetrieverEmbeddingProvider([1.0, 0.0, 0.0]);
        $second = new RetrieverEmbeddingProvider([1.0, 0.0, 0.0], 'openai', 'text-embedding-3-small');
        foreach ([$first, $second] as $provider) {
            foreach (range(1, 32) as $index) {
                $example = $this->learningExample($organization->id, $project->id, 'learning stale '.$provider->provider().' '.$index);
                $this->indexLearning($example, $provider);
                $example->update(['quality_flags' => ['do_not_index']]);
            }
        }
        $valid = $this->learningExample($organization->id, $project->id, 'learning current second profile');
        $this->indexLearning($valid, new RetrieverEmbeddingProvider([0.8, 0.2, 0.0], 'openai', 'text-embedding-3-small'));
        $registry = new RagEmbeddingProviderRegistry($first);
        $property = new \ReflectionProperty($registry, 'providers');
        $original = $property->getValue($registry);
        $property->setValue($registry, ['openai|text-embedding-3-small|'.RagTestEmbedding::DIMENSIONS => $second]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $results = (new RagRetriever($first, app(UserProjectAccessService::class), embeddingProviderRegistry: $registry))
                ->search('learning evidence two profiles', $organization->id, $user,
                    ['project_id' => $project->id, 'source_types' => ['estimate_generation_learning']]);
            $queries = $this->candidateQueries(DB::getQueryLog());
        } finally {
            $property->setValue($registry, $original);
            DB::disableQueryLog();
        }
        self::assertSame([(string) $valid->id], array_column($results, 'entityId'));
        self::assertCount(4, $queries);
        self::assertSame([32, 64, 32, 64], array_map(static fn (array $query): int => (int) $query['bindings'][array_key_last($query['bindings'])], $queries));
        self::assertSame(1, $first->queryCalls);
        self::assertSame(1, $second->queryCalls);
    }

    public function test_reference_only_forbidden_source_does_not_fall_back_to_estimates_but_explicit_types_keep_priority(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(UserProjectAccessMode::ALL_PROJECTS->value);
        $this->indexChunk($organization->id, $project->id, 'Current estimate', 'estimate evidence', [1.0, 0.0, 0.0], 'estimate', 'estimate');
        $this->indexChunk($organization->id, null, 'Reference', 'reference evidence', [1.0, 0.0, 0.0], 'estimate_reference', 'estimate_catalog_item');
        EstimatePositionCatalog::query()->where('organization_id', $organization->id)
            ->update(['organization_id' => Organization::factory()->create()->id]);
        $retriever = $this->retriever([1.0, 0.0, 0.0]);
        foreach (['semantic', 'lexical'] as $mode) {
            self::assertSame([], $retriever->search('Расценки из справочника', $organization->id, $user,
                ['project_id' => $project->id, 'retrieval_mode' => $mode]));
            $explicit = $retriever->search('Расценки из справочника', $organization->id, $user,
                ['project_id' => $project->id, 'retrieval_mode' => $mode, 'source_types' => ['estimate']]);
            if ($mode === 'semantic') {
                self::assertSame(['estimate'], array_column($explicit, 'sourceType'));
            }
        }
    }

    public function test_stale_learning_pool_stops_after_one_refill_in_each_search_branch(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(UserProjectAccessMode::ALL_PROJECTS->value);
        foreach (range(1, 192) as $index) {
            $example = $this->learningExample($organization->id, $project->id, 'learning evidence stale '.$index);
            $this->indexLearning($example);
            $example->update(['quality_flags' => ['do_not_index']]);
        }
        $provider = new RetrieverEmbeddingProvider([1.0, 0.0, 0.0]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $results = $this->retriever($provider)->search('learning evidence', $organization->id, $user,
                ['project_id' => $project->id, 'source_types' => ['estimate_generation_learning']]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        self::assertSame([], $results);
        $candidates = $this->candidateQueries($queries);
        self::assertCount(4, $candidates);
        self::assertSame([32, 64], array_map(static fn (array $query): int => (int) $query['bindings'][array_key_last($query['bindings'])], array_slice($candidates, 0, 2)));
        self::assertStringContainsString('limit 96', $candidates[2]['query']);
        self::assertStringContainsString('limit 192', $candidates[3]['query']);
        self::assertSame(1, $provider->queryCalls);
        $hydrations = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "estimate_generation_learning_examples"') && str_starts_with($query['query'], 'select *'));
        self::assertLessThanOrEqual(4, count($hydrations));
    }

    private function candidateQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'WITH compatible_chunks AS MATERIALIZED')
            || (str_contains($query['query'], 'from "ai_rag_chunks" as "c"') && str_contains($query['query'], 'limit '))));
    }

    private function learningExample(int $organizationId, int $projectId, string $name): EstimateGenerationLearningExample
    {
        return EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organizationId, 'project_id' => $projectId,
            'source_type' => 'user_selection', 'source_entity_type' => 'estimate_item', 'source_entity_id' => random_int(1, 2000000000),
            'work_name' => $name, 'work_unit' => 'count', 'work_quantity' => 3,
            'work_intent' => ['scope' => 'equipment', 'action' => 'installation'],
            'norm_code' => '20-99-999-01', 'normative_name' => 'learning evidence', 'normative_unit' => 'count',
            'decision_status' => 'selected_by_user', 'is_positive' => true,
            'source_quality_score' => 1, 'quality_flags' => ['unit_compatible'],
        ]);
    }

    private function learningSource(EstimateGenerationLearningExample $example): RagSource
    {
        return RagSource::query()->where('source_type', 'estimate_generation_learning')->where('entity_id', (string) $example->id)->firstOrFail();
    }

    private function indexLearning(EstimateGenerationLearningExample $example, array|RetrieverEmbeddingProvider $embedding = [1.0, 0.0, 0.0]): void
    {
        $chunks = app(EstimateGenerationLearningRagSource::class)->collectEntity($example->organization_id, 'estimate_generation_learning_example', $example->id);
        foreach ($chunks as $chunk) {
            (new RagIndexer(is_array($embedding) ? new RetrieverEmbeddingProvider($embedding) : $embedding, new RagSourceRegistry([])))->indexChunk($chunk);
        }
    }

    private function retriever(array|RetrieverEmbeddingProvider $queryEmbedding): RagRetriever
    {
        $provider = $queryEmbedding instanceof RetrieverEmbeddingProvider
            ? $queryEmbedding
            : new RetrieverEmbeddingProvider($queryEmbedding);

        return new RagRetriever(
            $provider,
            app(UserProjectAccessService::class)
        );
    }

    private function indexChunk(
        int $organizationId,
        ?int $projectId,
        string $title,
        string $content,
        array $embedding,
        string $sourceType = 'project',
        string $entityType = 'project'
    ): void {
        $actorId = Organization::query()->findOrFail($organizationId)->users()->wherePivot('is_active', true)->value('users.id');
        $entity = match ($entityType) {
            'project' => Project::query()->findOrFail($projectId),
            'estimate' => $this->createEstimate($organizationId, $projectId, $title),
            'estimate_section' => EstimateSection::withoutEvents(fn () => EstimateSection::query()->create([
                'estimate_id' => $this->createEstimate($organizationId, $projectId, $title)->id,
                'section_number' => '1', 'name' => $title,
            ])),
            'estimate_catalog_item' => EstimatePositionCatalog::withoutEvents(fn () => EstimatePositionCatalog::query()->create([
                'organization_id' => $organizationId, 'name' => $title, 'code' => 'RAG-'.hash('crc32b', $title),
                'item_type' => 'work', 'unit_price' => 1250, 'created_by_user_id' => $actorId,
                'measurement_unit_id' => MeasurementUnit::query()->where('organization_id', $organizationId)
                    ->where('short_name', 'м')->firstOrFail()->id,
            ])),
            'contract' => Contract::withoutEvents(fn () => Contract::query()->create([
                'organization_id' => $organizationId, 'project_id' => $projectId,
                'contractor_id' => Contractor::withoutEvents(fn () => Contractor::query()->create([
                    'organization_id' => $organizationId, 'name' => 'Подрядчик',
                ]))->id,
                'number' => 'RAG-'.hash('crc32b', $title), 'date' => today(), 'total_amount' => 10500000, 'status' => 'active',
            ])),
            'site_request' => SiteRequest::withoutEvents(fn () => SiteRequest::query()->create([
                'organization_id' => $organizationId, 'project_id' => $projectId, 'user_id' => $actorId,
                'title' => $title, 'status' => 'approved', 'request_type' => 'material_request',
            ])),
            'payment_document' => PaymentDocument::withoutEvents(fn () => PaymentDocument::query()->create([
                'organization_id' => $organizationId, 'project_id' => $projectId, 'document_type' => 'invoice',
                'document_number' => 'RAG-'.hash('crc32b', $title), 'document_date' => today(),
                'amount' => 150000, 'currency' => 'RUB',
            ])),
            default => throw new RuntimeException('Unsupported retriever fixture entity: '.$entityType),
        };
        $indexer = new RagIndexer(
            new RetrieverEmbeddingProvider($embedding),
            new RagSourceRegistry([])
        );

        $indexer->indexChunk(new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: $sourceType,
            entityType: $entityType,
            entityId: $entity->getKey(),
            title: $title,
            content: $content,
            metadata: ['title' => $title, 'unit_id' => hash('sha256', $title)],
            updatedAt: $entity->updated_at
        ));
    }

    private function createEstimate(int $organizationId, ?int $projectId, string $title): Estimate
    {
        return Estimate::withoutEvents(fn () => Estimate::query()->create([
            'organization_id' => $organizationId, 'project_id' => $projectId, 'name' => $title,
            'number' => 'RAG-'.hash('crc32b', $title), 'estimate_date' => today(),
        ]));
    }

    /**
     * @return array{0: Organization, 1: User, 2: Project}
     */
    private function createOrganizationUserWithOneProject(string $mode): array
    {
        [$organization, $user, $projectA] = $this->createOrganizationUserWithProjects($mode);

        return [$organization, $user, $projectA];
    }

    /**
     * @return array{0: Organization, 1: User, 2: Project, 3: Project}
     */
    private function createOrganizationUserWithProjects(string $mode): array
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organization = $fixture->organization;
        $user = $fixture->owner;
        $organization->users()->updateExistingPivot($user->id, ['project_access_mode' => $mode]);
        $policy = app(AssistantDataAccessPolicy::class);
        $allowedSourceTypes = $policy->allowedSourceTypes($user, $organization->id);
        foreach (['project', 'contract', 'estimate', 'estimate_reference', 'site_request', 'payment'] as $sourceType) {
            self::assertContains($sourceType, $allowedSourceTypes);
        }

        $projectA = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);
        $projectB = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);

        return [$organization, $user, $projectA, $projectB];
    }
}

final class RetrieverEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public ?string $lastPurpose = null;
    public int $queryCalls = 0;

    /**
     * @param  array<int, float>  $embedding
     */
    private readonly array $embedding;

    public function __construct(array $embedding, private readonly string $providerName = 'fake', private readonly string $modelName = 'fake-model')
    {
        $this->embedding = RagTestEmbedding::fromLeadingValues($embedding);
    }

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->lastPurpose = $purpose;
        if ($purpose === self::PURPOSE_QUERY) { $this->queryCalls++; }

        return $this->embedding;
    }

    public function provider(): string
    {
        return $this->providerName;
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

final class FailingRetrieverEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        throw new RuntimeException('Embedding service unavailable');
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
        return RagTestEmbedding::DIMENSIONS;
    }
}
