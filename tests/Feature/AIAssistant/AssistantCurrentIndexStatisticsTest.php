<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\RefreshRagCoverageJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class AssistantCurrentIndexStatisticsTest extends TestCase
{
    private bool $permissions = true;
    private array $deniedPermissions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
    }

    public function test_cold_counts_are_current_and_do_not_reuse_sensitive_cached_totals(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $service = $this->service();
        $this->source($organization->id, $project);
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $this->source($organization->id, $hidden);
        $foreign = Organization::factory()->create();
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $foreign->id, 'is_archived' => false]));
        $this->source($foreign->id, $foreignProject);
        Cache::put('ai-rag-status:'.$organization->id.':'.$actor->id.':lk', ['status' => ['source_count' => 987654]], 60);
        Cache::put($this->coverageKey($organization->id), ['source_count' => 987654, 'expected_source_count' => 1, 'coverage_complete' => true,
            'eligible_count_known' => true, 'stored_source_count' => 1, 'indexed_source_count' => 1,
            'snapshot_at' => now()->toAtomString(), 'source_catalog' => [['type' => 'project', 'expected_count' => 1,
                'indexed_count' => 1, 'stored_count' => 1]]], 300);

        $status = $service->status($organization->id, $actor);

        $this->assertTrue($status['status_available']);
        $this->assertSame(1, $status['source_count']);
        $this->assertSame(1, $status['chunk_count']);
        $this->assertSame('Проекты', $status['source_catalog'][0]['display_label']);
        $resource = (new RagIndexStatusResource($status))->toArray(request());
        $this->assertSame('Проекты', $resource['source_catalog'][0]['display_label']);
        $this->assertFalse($status['eligible_count_known']);
        $this->assertFalse($status['coverage_complete']);
        foreach (['expected_source_count', 'pending_source_count', 'stale_source_count'] as $field) {
            $this->assertNull($status[$field]);
        }
        $this->assertNull($status['source_catalog'][0]['expected_count']);
        $this->assertFalse($status['can_manage_document_settings']);
        $actor->organizations()->updateExistingPivot($organization->id, ['is_owner' => true]);
        $this->assertTrue($service->status($organization->id, $actor)['can_manage_document_settings']);
        $actor->organizations()->updateExistingPivot($organization->id, ['is_owner' => false]);
        $this->assertFalse($service->status($organization->id, $actor)['can_manage_document_settings']);
        Queue::assertPushed(RefreshRagCoverageJob::class, fn (RefreshRagCoverageJob $job): bool => $job->organizationId === $organization->id
            && $job->projectId === null && $job->sourceType === null && $job->cacheKey === $this->coverageKey($organization->id));
        Queue::assertPushed(RefreshRagCoverageJob::class, 1);
    }

    public function test_current_counts_apply_assignment_and_permission_revocation_without_background_refresh(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $service = $this->service();
        $this->source($organization->id, $project);
        $this->assertSame(1, $service->status($organization->id, $actor)['source_count']);

        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        $this->assertSame(0, $service->status($organization->id, $actor)['source_count']);
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $this->permissions = false;
        $status = $service->status($organization->id, $actor);
        $this->assertTrue($status['status_available']);
        $this->assertSame(0, $status['source_count']);
        $this->assertSame(0, $status['chunk_count']);
        $this->assertSame([], $status['source_catalog']);
    }

    public function test_actor_catalog_preserves_registry_labels_through_the_status_resource(): void
    {
        [$organization, $actor] = $this->scope();
        $types = ['budgeting', 'legal_business'];
        $collectors = [];
        foreach (array_merge($types, ['project']) as $type) {
            $collector = Mockery::mock(RagSourceCollectorInterface::class);
            $collector->shouldReceive('sourceType')->andReturn($type);
            $collector->shouldReceive('enabled')->andReturnTrue();
            $collectors[] = $collector;
        }
        $registry = new RagSourceRegistry($collectors);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnTrue();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'budgeting'], (object) ['slug' => 'file-management'], (object) ['slug' => 'payments']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $coverage = new RagCoverageService($registry, new RagIndexer(Mockery::mock(RagEmbeddingProviderInterface::class), $registry), $policy);
        $status = $coverage->coverageForActor($organization->id, $actor, countsOnly: true);
        $resource = (new RagIndexStatusResource($status))->toArray(request());

        $this->assertSame($types, array_column($status['source_catalog'], 'type'));
        $this->assertSame(['Бюджетирование', 'Юридические документы'], array_column($status['source_catalog'], 'display_label'));
        $this->assertSame(array_column($status['source_catalog'], 'display_label'), array_column($resource['source_catalog'], 'display_label'));
    }

    public function test_document_and_file_source_counts_require_current_parent_and_storage_mapping(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $service = $this->service(['project', 'file_document']);
        $path = 'org-'.$organization->id.'/current-statistics.pdf';
        $file = File::withoutEvents(fn () => File::query()->create(['organization_id' => $organization->id,
            'user_id' => $actor->id, 'fileable_type' => Project::class, 'fileable_id' => $project->id,
            'name' => 'statistics.pdf', 'original_name' => 'statistics.pdf', 'disk' => 's3', 'path' => $path,
            'mime_type' => 'application/pdf', 'size' => 100]));
        $document = AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $project->id,
            'storage_path' => $path, 'filename' => 'statistics.pdf', 'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', $path), 'size_bytes' => 100, 'status' => 'ready', 'extracted_text' => 'Prepared text',
        ]));
        RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => (string) $document->id,
            'title' => 'Document', 'checksum' => hash('sha256', $path)]);
        $status = $service->status($organization->id, $actor);
        $this->assertSame(1, $status['source_count']);
        $this->assertSame(1, $status['document_coverage']['ready']);

        $file->update(['path' => 'org-'.$organization->id.'/replacement.pdf']);
        $status = $service->status($organization->id, $actor);
        $this->assertSame(0, $status['source_count']);
        $this->assertSame(0, $status['document_coverage']['ready']);
        $this->assertSame(1, $status['document_coverage']['pending']);
    }

    public function test_expected_counts_use_current_generation_checksum_and_do_not_materialize_identity_proofs(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $service = $this->service();
        $source = $this->source($organization->id, $project);
        $generation = '59228f78-cbb6-4e2e-96b1-581e2f697cf7';
        $expected = RagExpectedSource::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'identity_project_id' => $source->identity_project_id, 'identity_part_key' => $source->identity_part_key,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'generation' => $generation, 'checksum' => $source->checksum, 'pending_since' => now()->subMinute()]);
        Cache::put($this->coverageKey($organization->id), ['projection_generation' => $generation, 'eligible_count_known' => true,
            'snapshot_at' => now()->toAtomString(), 'source_catalog' => [['type' => 'project']]], 300);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $status = $service->status($organization->id, $actor);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertTrue($status['status_available']);
        $this->assertSame(1, $status['expected_source_count']);
        $this->assertSame(1, $status['indexed_source_count']);
        $this->assertTrue($status['coverage_complete']);
        $this->assertSame([], array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'identity_proof')));
        $expected->update(['checksum' => hash('sha256', 'new generation content')]);
        $status = $service->status($organization->id, $actor);
        $this->assertSame(0, $status['indexed_source_count']);
        $this->assertSame(1, $status['pending_source_count']);
        $this->assertSame(1, $status['stale_source_count']);
        $this->assertFalse($status['coverage_complete']);
    }

    public function test_single_candidate_counts_preserve_file_parents_identity_parts_and_chunk_scope_without_overcount(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $service = $this->service(['project', 'file_document']);
        $sources = [$this->source($organization->id, $project), $this->source($organization->id, $project, 'second-part')];
        RagChunk::query()->create(['source_id' => $sources[0]->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'chunk_index' => 1, 'content' => 'Additional chunk', 'content_hash' => hash('sha256', 'additional')]);
        $hidden = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        RagChunk::query()->create(['source_id' => $sources[0]->id, 'organization_id' => $organization->id,
            'project_id' => $hidden->id, 'chunk_index' => 2, 'content' => 'Wrong project chunk', 'content_hash' => hash('sha256', 'wrong-project')]);
        $contractor = Contractor::withoutEvents(fn () => Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Statistics contractor']));
        $contract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'Statistics contract',
            'date' => '2026-09-30', 'status' => 'active', 'total_amount' => '1000.00']));
        foreach ([['project', Project::class, $project->id], ['contract', Contract::class, $contract->id]] as [$parentType, $modelType, $parentId]) {
            $path = 'org-'.$organization->id.'/statistics-'.$parentType.'.pdf';
            $file = File::withoutEvents(fn () => File::query()->create(['organization_id' => $organization->id,
                'user_id' => $actor->id, 'fileable_type' => $modelType, 'fileable_id' => $parentId,
                'name' => 'statistics.pdf', 'original_name' => 'statistics.pdf', 'disk' => 's3', 'path' => $path,
                'mime_type' => 'application/pdf', 'size' => 100]));
            $document = AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id, 'file_id' => $file->id,
                'parent_entity_type' => $parentType, 'parent_entity_id' => (string) $parentId, 'storage_path' => $path,
                'filename' => 'statistics.pdf', 'mime_type' => 'application/pdf', 'checksum' => hash('sha256', $path),
                'size_bytes' => 100, 'status' => 'ready', 'extracted_text' => 'Prepared text',
            ]));
            $source = RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => (string) $document->id,
                'identity_part_key' => '', 'title' => 'Document', 'checksum' => hash('sha256', $path)]);
            $chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $organization->id,
                'project_id' => $project->id, 'chunk_index' => 0, 'content' => 'Document data', 'content_hash' => $source->checksum]);
            DB::update('UPDATE ai_rag_chunks SET embedding = ?::vector WHERE id = ?', ['['.implode(',', \Tests\Support\RagTestEmbedding::fromLeadingValues([0.01])).']', $chunk->id]);
            $sources[] = $source;
        }
        $generation = '158c19f2-5d66-4655-a62b-174f1c6a2f96';
        foreach ($sources as $source) {
            RagExpectedSource::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'identity_project_id' => $source->identity_project_id, 'identity_part_key' => $source->identity_part_key,
                'source_type' => $source->source_type, 'entity_type' => $source->entity_type, 'entity_id' => $source->entity_id,
                'generation' => $generation, 'checksum' => $source->checksum, 'pending_since' => now()->subMinute()]);
        }
        Cache::put($this->coverageKey($organization->id), ['projection_generation' => $generation, 'eligible_count_known' => true,
            'snapshot_at' => now()->toAtomString(), 'source_catalog' => [['type' => 'project'], ['type' => 'file_document']]], 300);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $status = $service->status($organization->id, $actor);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertTrue($status['status_available']);
        $this->assertSame(4, $status['source_count']);
        $this->assertSame(5, $status['chunk_count']);
        $this->assertSame(4, $status['expected_source_count']);
        $this->assertSame(4, $status['indexed_source_count']);
        $this->assertTrue($status['coverage_complete']);
        $storedQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'COUNT(DISTINCT accessible_sources.id)'));
        $expectedQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'COUNT(*) AS expected_count'));
        $this->assertCount(1, $storedQueries);
        $this->assertCount(1, $expectedQueries);
        $this->assertStringNotContainsString('union all', strtolower(array_values($storedQueries)[0]['query']));
        $this->assertStringNotContainsString('union all', strtolower(array_values($expectedQueries)[0]['query']));
        foreach ([$storedQueries, $expectedQueries] as $aggregateQueries) {
            $sql = array_values($aggregateQueries)[0]['query'];
            $this->assertSame(1, substr_count($sql, '(WITH '));
            $this->assertSame(1, substr_count($sql, '"is_archived" ='), 'Entity guards must share the current project visibility scope.');
            $this->assertSame(4, preg_match_all('/"assistant_acl_\d+" AS MATERIALIZED/', $sql));
        }
        $this->assertSame(1, substr_count(array_values($storedQueries)[0]['query'], '"ai_rag_sources"."source_type" in ('));
        $this->assertSame(1, substr_count(array_values($expectedQueries)[0]['query'], '"ai_rag_expected_sources"."source_type" in ('));
        $storedDiscovery = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'select distinct "ai_rag_sources"."source_type", "ai_rag_sources"."entity_type"'));
        $expectedDiscovery = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'select distinct "ai_rag_expected_sources"."source_type", "ai_rag_expected_sources"."entity_type"'));
        $this->assertCount(1, $storedDiscovery);
        $this->assertCount(1, $expectedDiscovery);
    }

    public function test_public_untyped_scopes_keep_fresh_org_entity_permissions_and_expected_model_guards(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $this->service();
        $visible = $this->source($organization->id, $project);
        $foreign = Organization::factory()->create();
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $foreign->id, 'is_archived' => false]));
        $this->source($foreign->id, $foreignProject);
        RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $foreignProject->id,
            'identity_part_key' => 'foreign-entity', 'title' => 'Foreign entity reference', 'checksum' => hash('sha256', 'foreign-entity')]);
        $policy = app(AssistantDataAccessPolicy::class);
        $this->assertSame([$visible->id], $policy->applyToSources(RagSource::query(), $actor, $organization->id)->pluck('ai_rag_sources.id')->all());
        $this->permissions = false;
        $this->assertSame([], $policy->applyToSources(RagSource::query(), $actor, $organization->id)->pluck('ai_rag_sources.id')->all());
        $this->permissions = true;
        $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);
        $this->assertSame([], $policy->applyToSources(RagSource::query(), $actor, $organization->id)->pluck('ai_rag_sources.id')->all());
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            try {
                iterator_to_array($policy->sourceIdentityQueries(RagSource::query(), $actor, $organization->id, expectedProjection: true));
                $this->fail('Expected projection requires the expected source model');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('assistant_expected_projection_model_required', $exception->getMessage());
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_checked_query_templates_reuse_compilation_only_inside_the_current_actor_scope(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $this->service();
        $policy = app(AssistantDataAccessPolicy::class);
        $compilations = 0;
        $checkpoint = static function () use (&$compilations): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['function'] ?? null) === 'compileAcl') {
                    $compilations++;
                    break;
                }
            }
        };
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization, $project, &$compilations): void {
            $first = $policy->entityQuery($actor, $organization->id, 'project');
            $this->assertNotNull($first);
            $initialCompilations = $compilations;
            $this->assertGreaterThan(0, $initialCompilations);
            $first->whereRaw('1 = 0')->select('projects.id');
            $second = $policy->entityQuery($actor, $organization->id, 'project');
            $this->assertNotNull($second);
            $this->assertSame([$project->id], $second->pluck('projects.id')->all());
            $this->assertTrue($policy->canReadEntityContent($actor, $organization->id, 'project', $project->id));
            $this->assertSame($initialCompilations, $compilations);

            $actor->current_organization_id = $organization->id + 1;
            try {
                $this->assertNull($policy->entityQuery($actor, $organization->id, 'project'));
            } finally {
                $actor->current_organization_id = $organization->id;
            }

            $path = new \ReflectionProperty($policy, 'entityQueryPath');
            $path->setValue($policy, ['project']);
            try {
                $this->assertNull($policy->entityQuery($actor, $organization->id, 'project'));
            } finally {
                $path->setValue($policy, []);
            }
            $policy->setTrustedSurface(\App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface::MOBILE);
            $beforeSurfaceChange = $compilations;
            $this->assertNotNull($policy->entityQuery($actor, $organization->id, 'project'));
            $this->assertGreaterThan($beforeSurfaceChange, $compilations);
            $this->permissions = false;
            $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization): void {
                $this->assertNull($policy->entityQuery($actor, $organization->id, 'project'));
            }, fresh: true);
            $this->assertNull($policy->entityQuery($actor, $organization->id, 'project'));
        }, fresh: true, checkpoint: $checkpoint);
        $this->permissions = true;
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization, $project): void {
            $this->assertSame([$project->id], $policy->entityQuery($actor, $organization->id, 'project')->pluck('projects.id')->all());
        }, fresh: true);
        $this->permissions = false;
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization): void {
            $this->assertNull($policy->entityQuery($actor, $organization->id, 'project'));
        }, fresh: true);
    }

    public function test_shared_project_visibility_keeps_contract_content_permissions_and_assignment_guards(): void
    {
        [$organization, $actor, $project] = $this->scope();
        $this->service();
        $policy = app(AssistantDataAccessPolicy::class);
        $contractor = Contractor::withoutEvents(fn () => Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Visibility scope']));
        $contract = Contract::withoutEvents(fn () => Contract::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'contractor_id' => $contractor->id, 'number' => 'VISIBILITY-'.$organization->id,
            'date' => '2026-10-01', 'status' => 'active', 'total_amount' => '100.00']));
        $this->deniedPermissions = ['projects.view'];
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization, $contract): void {
            $this->assertNull($policy->entityQuery($actor, $organization->id, 'project'));
            $contracts = $policy->entityContentQuery($actor, $organization->id, 'contract');
            $this->assertNotNull($contracts);
            $this->assertSame([$contract->id], $contracts->pluck('contracts.id')->all());
            $this->assertSame(1, substr_count($contracts->toSql(), '"is_archived" ='));
        }, fresh: true);

        $actor->assignedProjects()->detach($project->id);
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization): void {
            $this->assertSame([], $policy->entityContentQuery($actor, $organization->id, 'contract')->pluck('contracts.id')->all());
        }, fresh: true);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $this->deniedPermissions[] = 'finance.view';
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization): void {
            $this->assertNull($policy->entityContentQuery($actor, $organization->id, 'contract'));
        }, fresh: true);
    }

    public function test_shared_project_visibility_still_denies_organization_aggregates_when_a_project_is_hidden(): void
    {
        [$organization, $actor] = $this->scope();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnTrue();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'budgeting']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization): void {
            $this->assertNotNull($policy->entityQuery($actor, $organization->id, 'budget_import_batch'));
        }, fresh: true);

        Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $policy->withCurrentChecks($actor, $organization->id, function () use ($policy, $actor, $organization): void {
            $this->assertNull($policy->entityQuery($actor, $organization->id, 'budget_import_batch'));
        }, fresh: true);
    }

    private function scope(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);

        return [$organization, $actor, $project];
    }

    private function source(int $organizationId, Project $project, string $part = ''): RagSource
    {
        $source = RagSource::query()->create(['organization_id' => $organizationId, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'identity_part_key' => $part, 'title' => 'Project', 'checksum' => hash('sha256', 'project-'.$project->id.'-'.$part)]);
        $chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $organizationId,
            'project_id' => $project->id, 'chunk_index' => 0, 'content' => 'Project data', 'content_hash' => $source->checksum]);
        DB::update('UPDATE ai_rag_chunks SET embedding = ?::vector WHERE id = ?', ['['.implode(',', \Tests\Support\RagTestEmbedding::fromLeadingValues([0.01])).']', $chunk->id]);

        return $source->refresh();
    }

    private function coverageKey(int $organizationId): string
    {
        return 'ai-rag-coverage:'.$organizationId.':0:*:'.(int) Cache::get('ai-rag-coverage-revision:'.$organizationId, 0);
    }

    private function service(array $types = ['project']): AssistantIndexStatusService
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->permissions
            && ! in_array($permission, $this->deniedPermissions, true) && in_array($permission, ['projects.view', 'contracts.view', 'finance.view'], true));
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'contract-management'], (object) ['slug' => 'payments']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $collectors = [];
        foreach ($types as $type) {
            $collector = Mockery::mock(RagSourceCollectorInterface::class);
            $collector->shouldReceive('sourceType')->andReturn($type);
            $collector->shouldReceive('enabled')->andReturn(true);
            $collector->shouldNotReceive('collectForOrganization');
            $collector->shouldNotReceive('collectEntity');
            $collectors[] = $collector;
        }
        $registry = new RagSourceRegistry($collectors);
        $embedding = Mockery::mock(RagEmbeddingProviderInterface::class);
        $embedding->shouldNotReceive('embed');
        $indexer = new RagIndexer($embedding, $registry);

        return new AssistantIndexStatusService(new RagCoverageService($registry, $indexer, $policy),
            app(AssistantDocumentCoverageService::class), new RagIndexingCoordinator($indexer), $policy, $authorization);
    }
}
