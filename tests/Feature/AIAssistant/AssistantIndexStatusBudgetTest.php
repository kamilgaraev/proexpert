<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshAssistantIndexStatusJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\PostgresBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class AssistantIndexStatusBudgetTest extends TestCase
{
    private int $moduleReads = 0;

    public function test_schema_metadata_reference_applies_table_prefix_after_schema_resolution(): void
    {
        $policy = (new \ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(AssistantDataAccessPolicy::class))->getMethod('schemaMetadataTableReference');

        foreach ([
            ['reports.monthly', ['reports', 'monthly'], ['reports', 'tenant_monthly']],
            ['projects', ['tenant_schema', 'projects'], ['tenant_schema', 'tenant_projects']],
        ] as [$table, $resolved, $expected]) {
            $schemaBuilder = Mockery::mock(PostgresBuilder::class);
            $schemaBuilder->shouldReceive('parseSchemaAndTable')->once()->with($table)->andReturn($resolved);
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getTablePrefix')->once()->andReturn('tenant_');

            $this->assertSame($expected, $method->invoke($policy, $schemaBuilder, $connection, $table));
        }
    }

    public function test_status_reads_current_modules_once_and_returns_real_empty_counts(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $service = $this->service();
        $this->warmSnapshot($service, $organization->id, $actor->id);
        $start = hrtime(true);
        $status = $service->status($organization->id, $actor);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertTrue($status['status_available']);
        $this->assertSame(0, $status['source_count']);
        $this->assertSame(0, $status['document_coverage']['total']);
        $this->assertLessThanOrEqual(2, $this->moduleReads, 'Unexpected current module reads; status elapsed '.round($elapsed).'ms');
    }

    public function test_current_role_status_keeps_visible_counts_with_one_entitlement_read(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]));
        $source = RagSource::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'title' => 'Проект', 'checksum' => hash('sha256', 'fixture'),
        ]);
        $otherProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]));
        foreach ([[$project->id, true], [$project->id, false], [$otherProject->id, true]] as $index => [$chunkProject, $indexed]) {
            DB::table('ai_rag_chunks')->insert([
                'source_id' => $source->id, 'organization_id' => $fixture->organization->id, 'project_id' => $chunkProject,
                'chunk_index' => $index, 'content' => 'Фрагмент', 'content_hash' => hash('sha256', 'chunk-'.$index),
                'embedding' => $indexed ? '['.implode(',', RagTestEmbedding::fromLeadingValues([1.0])).']' : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $service = app(AssistantIndexStatusService::class);
        $this->warmSnapshot($service, $fixture->organization->id, $fixture->owner->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        try {
            $status = $service->status($fixture->organization->id, $fixture->owner);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $metrics = [
            'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
            'sql_count' => count($queries),
            'sql_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'timeout_set_count' => count(array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'SET LOCAL statement_timeout'))),
            'schema_query_count' => count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'pg_catalog') || str_contains($query['query'], 'information_schema'))),
        ];
        if (getenv('MOST_RAG_STATUS_PROFILE') === '1') { fwrite(STDERR, 'RAG status fixture '.json_encode($metrics).PHP_EOL); }
        $this->assertTrue($status['status_available'], json_encode($metrics));
        $this->assertSame(1, $status['source_count']);
        $this->assertSame(2, $status['chunk_count']);
        $this->assertSame(1, $status['indexed_source_count']);
        $subscriptions = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'organization_package_subscriptions'));
        $this->assertLessThan(4, count($subscriptions));
    }

    public function test_cold_status_returns_current_empty_counts_without_actor_refresh(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        Cache::put('ai-rag-coverage:'.$organization->id.':0:*:'.$revision, [
            'source_count' => 987, 'chunk_count' => 654, 'ready' => true,
            'source_catalog' => [['type' => 'private-source', 'error' => 'private-error']],
        ], 300);
        $service = $this->service();
        $result = (new RagIndexStatusResource($service->status($organization->id, $actor)))->toArray(new Request);
        $service->status($organization->id, $actor);

        $this->assertTrue($result['status_available']);
        $this->assertSame(0, $result['source_count']);
        $this->assertSame(0, $result['chunk_count']);
        $this->assertSame(0, $result['document_coverage']['total']);
        $this->assertIsArray($result['archive_scan']);
        $this->assertFalse($result['ready']);
        $this->assertSame([], $result['source_catalog']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_current_status_ignores_actor_snapshots_and_legacy_jobs_restore_trusted_surface(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $service = $this->service();
        $policy = app(AssistantDataAccessPolicy::class);
        foreach (['admin', 'lk'] as $surface) {
            Cache::put('ai-rag-status:'.$organization->id.':'.$actor->id.':'.$surface, ['status' => ['source_count' => 987654]], 60);
        }

        $policy->setTrustedSurface(KnowledgeSurface::ADMIN);
        $admin = $service->status($organization->id, $actor);
        $policy->setTrustedSurface(KnowledgeSurface::LK);
        $lk = $service->status($organization->id, $actor);

        $this->assertTrue($admin['status_available']);
        $this->assertTrue($lk['status_available']);
        $this->assertSame(0, $admin['source_count']);
        $this->assertSame(0, $lk['source_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);

        $policy->setTrustedSurface(KnowledgeSurface::MOBILE);
        (new RefreshAssistantIndexStatusJob($organization->id, PHP_INT_MAX, KnowledgeSurface::ADMIN, 'test:surface-context'))
            ->handle($service, $policy);
        $this->assertSame(KnowledgeSurface::MOBILE, $policy->trustedSurface());
    }

    public function test_current_status_finishes_after_one_and_a_half_seconds_within_its_bounded_deadline(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $service = $this->service();
        Cache::put('ai-rag-status:'.$organization->id.':'.$actor->id.':lk', ['status' => ['source_count' => 987654]], 60);
        $delayed = false;
        DB::listen(static function ($query) use (&$delayed): void {
            if (! $delayed && str_contains(strtolower($query->sql), 'accessible_sources.source_type, count(*) as stored_count')) {
                $delayed = true;
                usleep(1_600_000);
            }
        });

        $status = $service->status($organization->id, $actor);

        $this->assertTrue($delayed);
        $this->assertTrue($status['status_available']);
        $this->assertSame(0, $status['source_count']);
        $this->assertSame(0, $status['chunk_count']);
        $this->assertSame(0, $status['document_coverage']['total']);
        $this->assertNull($status['expected_source_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_budget_expiry_returns_unknown_without_reusing_or_refreshing_an_actor_snapshot(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $service = $this->service();
        Cache::put('ai-rag-status:'.$organization->id.':'.$actor->id.':lk', ['status' => ['source_count' => 987654]], 60);
        $budgetContext = null;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, static function ($event) use (&$budgetContext): void {
            if ($event->message === 'assistant.rag_status_budget_exceeded') { $budgetContext = $event->context; }
        });
        $expired = false;
        DB::listen(static function ($query) use (&$expired): void {
            if (! $expired && str_contains(strtolower($query->sql), 'accessible_sources.source_type, count(*) as stored_count')) {
                $expired = true;
                usleep(2_600_000);
            }
        });

        $status = (new RagIndexStatusResource($service->status($organization->id, $actor)))->toArray(new Request);

        $this->assertTrue($expired);
        $this->assertSame('source_counts', $budgetContext['phase'] ?? null);
        $this->assertSame($organization->id, $budgetContext['organization_id'] ?? null);
        $this->assertSame($actor->id, $budgetContext['actor_id'] ?? null);
        $this->assertGreaterThanOrEqual(2500, $budgetContext['elapsed_ms'] ?? 0);
        $this->assertFalse($status['status_available']);
        $this->assertNull($status['source_count']);
        $this->assertNull($status['chunk_count']);
        $this->assertNull($status['expected_source_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
        $this->assertTrue($service->status($organization->id, $actor)['status_available']);
    }

    public function test_legacy_complete_coverage_without_expected_proof_keeps_actor_scoped_status_available(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organization = $fixture->organization;
        $actor = $fixture->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]));
        $source = RagSource::withoutEvents(fn () => RagSource::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'source_type' => 'project',
            'entity_type' => 'project',
            'entity_id' => (string) $project->id,
            'title' => 'Legacy proof fixture',
            'checksum' => hash('sha256', 'legacy-proof-fixture'),
        ]));
        DB::table('ai_rag_chunks')->insert([
            'source_id' => $source->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'Legacy proof fixture',
            'content_hash' => hash('sha256', 'legacy-proof-fixture'),
            'embedding' => '['.implode(',', RagTestEmbedding::fromLeadingValues([1.0])).']',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collector = Mockery::mock(RagSourceCollectorInterface::class);
        $collector->shouldReceive('sourceType')->andReturn('project');
        $collector->shouldReceive('enabled')->andReturnTrue();
        $registry = new RagSourceRegistry([$collector]);
        $service = $this->service(true, $registry, ['project-management', 'payments']);
        $policy = app(AssistantDataAccessPolicy::class);

        $first = $service->status($organization->id, $actor);
        $this->assertTrue($first['status_available']);
        $this->assertSame(1, $first['source_count']);

        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        Cache::put('ai-rag-coverage:'.$organization->id.':0:*:'.$revision, [
            'eligible_count_known' => true,
            'coverage_complete' => true,
            'stored_source_count' => 1,
            'indexed_source_count' => 1,
            'expected_source_count' => 1,
            'snapshot_at' => now()->toIso8601String(),
            'source_catalog' => [[
                'type' => 'project',
                'stored_count' => 1,
                'indexed_count' => 1,
                'expected_count' => 1,
            ]],
        ], 300);

        $coverage = new RagCoverageService($registry, new RagIndexer(Mockery::mock(RagEmbeddingProviderInterface::class), $registry), $policy);
        $projectionProof = null;
        $legacyCoverage = $coverage->coverageForActor($organization->id, $actor, null, null, $projectionProof);
        $this->assertTrue($legacyCoverage['eligible_count_known']);
        $this->assertSame(1, $legacyCoverage['source_count']);
        $this->assertSame(1, $legacyCoverage['indexed_source_count']);
        $this->assertNull($projectionProof);

        $this->warmSnapshot($service, $organization->id, $actor->id);

        $snapshotKey = 'ai-rag-status:'.$organization->id.':'.$actor->id.':lk';
        $this->assertTrue(Cache::has($snapshotKey));
        $second = $service->status($organization->id, $actor);

        $this->assertTrue($second['status_available']);
        $this->assertSame(1, $second['source_count']);
        $this->assertSame(1, $second['indexed_source_count']);
        $this->assertNull($second['expected_source_count']);
        $this->assertNull($second['pending_source_count']);
        $this->assertNull($second['stale_source_count']);
        $this->assertFalse($second['eligible_count_known']);
        $this->assertFalse($second['coverage_complete']);
        $sourceStatus = $second['source_catalog'][0];
        $this->assertSame(1, $sourceStatus['stored_count']);
        $this->assertSame(1, $sourceStatus['indexed_count']);
        $this->assertNull($sourceStatus['expected_count']);
        $this->assertNull($sourceStatus['pending_count']);
        $this->assertNull($sourceStatus['stale_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_status_snapshot_is_invalidated_when_source_project_access_is_revoked(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $member = $fixture->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $fixture->organization->id,
            'is_archived' => false,
        ]));
        DB::table('organization_user')->where('user_id', $member->id)
            ->where('organization_id', $fixture->organization->id)->update(['project_access_mode' => 'assigned_projects']);
        $member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        RagSource::query()->create([
            'organization_id' => $fixture->organization->id,
            'project_id' => $project->id,
            'source_type' => 'project',
            'entity_type' => 'project',
            'entity_id' => (string) $project->id,
            'title' => 'Проект',
            'checksum' => hash('sha256', 'status-proof'),
        ]);
        $service = app(AssistantIndexStatusService::class);
        $this->warmSnapshot($service, $fixture->organization->id, $member->id);

        $visible = $service->status($fixture->organization->id, $member);
        $this->assertTrue($visible['status_available']);
        $this->assertSame(1, $visible['source_count']);

        $member->assignedProjects()->detach($project->id);
        $revoked = (new RagIndexStatusResource($service->status($fixture->organization->id, $member)))->toArray(new Request);
        $this->assertTrue($revoked['status_available']);
        $this->assertSame(0, $revoked['source_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_status_validation_cost_stays_bounded_for_multiple_source_identities_and_document_parents(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $organization = $fixture->organization;
        $actor = $fixture->owner;
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]));
        DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $actor->id)
            ->update(['project_access_mode' => 'assigned_projects']);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'owner']);
        $contractor = Contractor::withoutEvents(fn () => Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'RAG status proof test',
        ]));
        $contract = Contract::withoutEvents(fn () => Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'RAG-STATUS-'.$organization->id,
            'date' => '2026-09-30',
            'status' => 'active',
            'total_amount' => '1000.00',
        ]));
        RagSource::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'title' => 'Status project source', 'checksum' => hash('sha256', 'status-project-source'),
        ]);
        RagSource::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'source_type' => 'contract', 'entity_type' => 'contract', 'entity_id' => (string) $contract->id,
            'title' => 'Status contract source', 'checksum' => hash('sha256', 'status-contract-source'),
        ]);
        foreach ([['project', Project::class, $project->id], ['contract', Contract::class, $contract->id]] as [$parentType, $modelType, $parentId]) {
            $path = 'org-'.$organization->id.'/rag-status-'.$parentType.'.pdf';
            $file = File::withoutEvents(fn () => File::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $actor->id,
                'fileable_type' => $modelType,
                'fileable_id' => $parentId,
                'name' => 'rag-status-'.$parentType.'.pdf',
                'original_name' => 'rag-status-'.$parentType.'.pdf',
                'path' => $path,
                'disk' => 's3',
                'mime_type' => 'application/pdf',
                'size' => 128,
            ]));
            $document = AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'file_id' => $file->id,
                'parent_entity_type' => $parentType,
                'parent_entity_id' => (string) $parentId,
                'storage_path' => $path,
                'filename' => 'rag-status-'.$parentType.'.pdf',
                'mime_type' => 'application/pdf',
                'checksum' => hash('sha256', $path),
                'size_bytes' => 128,
                'status' => 'ready',
            ]));
            RagSource::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id,
                'source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => (string) $document->id,
                'title' => 'Status document source '.$parentType, 'checksum' => hash('sha256', 'document-'.$path),
            ]);
        }

        $bulkSources = [];
        for ($index = 0; $index < 260; $index++) {
            $isContract = $index % 2 === 1;
            $entityType = $isContract ? 'contract' : 'project';
            $entityId = $isContract ? $contract->id : $project->id;
            $bulkSources[] = [
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'identity_project_id' => $project->id,
                'identity_part_key' => hash('sha256', 'status-proof-batch-'.$index),
                'source_type' => $entityType,
                'entity_type' => $entityType,
                'entity_id' => (string) $entityId,
                'title' => 'Status proof batch '.$index,
                'checksum' => hash('sha256', 'status-proof-batch-checksum-'.$index),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($bulkSources, 100) as $batch) {
            DB::table('ai_rag_sources')->insert($batch);
        }

        $service = app(AssistantIndexStatusService::class);
        $this->warmSnapshot($service, $organization->id, $actor->id);
        $queries = 0;
        $identityScopeQueries = 0;
        $proofSelectQueries = 0;
        $schemaMetadataQueries = 0;
        $queryCategories = [];
        $documentDiscoverySql = [];
        DB::listen(static function ($query) use (&$queries, &$identityScopeQueries, &$proofSelectQueries, &$schemaMetadataQueries, &$queryCategories, &$documentDiscoverySql): void {
            $queries++;
            $sql = strtolower($query->sql);
            $category = match (true) {
                str_starts_with($sql, 'set local statement_timeout'), str_contains($sql, "set_config('statement_timeout'") => 'timeout_setting',
                str_contains($sql, 'pg_catalog'), str_contains($sql, 'information_schema') => 'schema',
                str_contains($sql, 'accessible_sources.source_type, count(*) as stored_count') => 'stored_counts',
                str_contains($sql, 'select distinct "ai_rag_sources"."source_type", "ai_rag_sources"."entity_type"') => 'source_discovery',
                str_contains($sql, 'organization_package_subscriptions') => 'entitlements',
                str_contains($sql, '"roles"'), str_contains($sql, '"permissions"') => 'authorization',
                str_contains($sql, 'count('), str_contains($sql, 'sum(') => 'coverage_counts',
                str_contains($sql, '"ai_assistant_documents"'), str_contains($sql, '"files"') => 'document_discovery',
                str_contains($sql, '"organization_user"'), str_contains($sql, '"project_user"') => 'membership_or_project_scope',
                default => 'other',
            };
            $queryCategories[$category] = ($queryCategories[$category] ?? 0) + 1;
            if (str_contains($sql, 'from pg_attribute a') && str_contains($sql, 'join pg_type t')) {
                $schemaMetadataQueries++;
            }
            if (str_contains($sql, 'select distinct "ai_rag_sources"."source_type", "ai_rag_sources"."entity_type"')) {
                $identityScopeQueries++;
            }
            if (str_contains($sql, 'from "ai_rag_sources"') && str_contains($sql, '"ai_rag_sources"."id" in')) {
                $proofSelectQueries++;
            }
            if (str_contains($sql, 'select distinct "parent_entity_type"')) {
                $documentDiscoverySql[] = $query->sql;
            }
        });
        $status = $service->status($organization->id, $actor);

        $this->assertTrue($status['status_available']);
        $this->assertSame(264, $status['source_count']);
        $this->assertSame(1, $identityScopeQueries, 'Status proof validation must compile the complete source identity ACL once.');
        $this->assertSame(0, $proofSelectQueries, 'Current counts must not select cached identity proofs.');
        $this->assertSame(1, $schemaMetadataQueries, 'Status proof validation must prefetch policy schema metadata in one query.');
        $this->assertNotEmpty($documentDiscoverySql);
        foreach ($documentDiscoverySql as $sql) {
            $this->assertStringNotContainsString('from "contracts"', $sql);
            $this->assertLessThan(6000, strlen($sql));
        }
        $timeoutQueries = $queryCategories['timeout_setting'] ?? 0;
        $this->assertLessThanOrEqual(45, $queries - $timeoutQueries, 'Current status read queries: '.json_encode($queryCategories));
        $this->assertLessThanOrEqual(20, $timeoutQueries, 'Deadline checkpoints must stay bounded for repeated identity parts.');
        $policyColumns = (new \ReflectionClass(AssistantDataAccessPolicy::class))->getProperty('columns')->getValue(app(AssistantDataAccessPolicy::class));
        foreach (['projects', 'contracts', 'estimates'] as $table) {
            $this->assertSame(Schema::getColumnListing($table), $policyColumns[$table] ?? null);
        }

        $generation = (string) \Illuminate\Support\Str::uuid();
        $expectedSource = RagExpectedSource::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'generation' => $generation,
            'identity_project_id' => $project->id,
            'identity_part_key' => '',
            'source_type' => 'project',
            'entity_type' => 'project',
            'entity_id' => (string) $project->id,
            'checksum' => hash('sha256', 'expected-proof-checksum'),
            'pending_since' => now(),
        ]);
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        Cache::put('ai-rag-coverage:'.$organization->id.':0:*:'.$revision, ['projection_generation' => $generation,
            'eligible_count_known' => true, 'snapshot_at' => now()->toAtomString(),
            'source_catalog' => app(RagSourceRegistry::class)->sourceCatalog()], 300);
        $snapshotKey = 'ai-rag-status:'.$organization->id.':'.$actor->id.':lk';
        $snapshot = Cache::get($snapshotKey);
        $snapshot['proof']['rag']['generation'] = $generation;
        $snapshot['proof']['rag']['expected'] = [(string) $expectedSource->id => [
            'project', 'project', (string) $project->id, (string) $project->id, (string) $project->id, '', $expectedSource->checksum,
        ]];
        Cache::put($snapshotKey, $snapshot, 60);
        $expectedProofSelectQueries = 0;
        DB::listen(static function ($query) use (&$expectedProofSelectQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "ai_rag_expected_sources"') && str_contains($sql, '"ai_rag_expected_sources"."id" in')) {
                $expectedProofSelectQueries++;
            }
        });
        $expectedProofStatus = $service->status($organization->id, $actor);
        $this->assertTrue($expectedProofStatus['status_available']);
        $this->assertSame(0, $expectedProofSelectQueries, 'Current expected counts must not select cached identity proofs.');
        $this->assertSame(1, $expectedProofStatus['expected_source_count']);
        $this->assertSame(0, $expectedProofStatus['indexed_source_count']);

        RagSource::query()->where('organization_id', $organization->id)->where('source_type', 'contract')
            ->update(['checksum' => hash('sha256', 'changed-contract-source')]);
        $changed = $service->status($organization->id, $actor);
        $this->assertTrue($changed['status_available']);
        $this->assertSame(264, $changed['source_count']);
        $this->assertSame(1, $changed['expected_source_count']);
        $this->assertSame(0, $changed['indexed_source_count']);

        $actor->assignedProjects()->detach($project->id);
        $revoked = $service->status($organization->id, $actor);
        $this->assertTrue($revoked['status_available']);
        $this->assertSame(0, $revoked['source_count']);
        $this->assertSame(0, $revoked['expected_source_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_membership_denial_is_not_replaced_by_unavailable_status(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);

        $this->expectException(AuthorizationException::class);
        $this->service()->status($organization->id, $actor);
    }

    public function test_budget_is_checked_during_source_permission_iteration_and_scope_is_restored(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $this->service();
        $policy = app(AssistantDataAccessPolicy::class);
        $checks = 0;

        try {
            $policy->withCurrentChecks($actor, $organization->id, fn (): array => $policy->allowedSourceTypes($actor, $organization->id),
                checkpoint: function () use (&$checks): void {
                    if (++$checks === 4) { throw new RagStatusBudgetExceeded; }
                });
            $this->fail('Permission iteration must check its overall deadline');
        } catch (RagStatusBudgetExceeded) {
            $this->assertSame(4, $checks);
        }

        $this->assertTrue($policy->belongsToOrganization($actor, $organization->id));
        $this->assertSame(4, $checks);
    }

    public function test_document_owner_check_propagates_overall_budget_expiry(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $this->service();
        $policy = app(AssistantDataAccessPolicy::class);

        $this->expectException(RagStatusBudgetExceeded::class);
        $policy->withCurrentChecks($actor, $organization->id,
            fn (): bool => app(AssistantDocumentCoverageService::class)->canManageSettings($organization->id, $actor),
            checkpoint: static function (): void { throw new RagStatusBudgetExceeded; });
    }

    private function service(bool $canCurrent = false, ?RagSourceRegistry $registry = null, array $moduleSlugs = []): AssistantIndexStatusService
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn($canCurrent);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(function () use ($moduleSlugs) {
            $this->moduleReads++;

            return collect(array_map(static fn (string $slug): object => (object) ['slug' => $slug], $moduleSlugs));
        });
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $registry ??= new RagSourceRegistry([]);
        $indexer = new RagIndexer(Mockery::mock(RagEmbeddingProviderInterface::class), $registry);

        return new AssistantIndexStatusService(
            new RagCoverageService($registry, $indexer, $policy),
            app(AssistantDocumentCoverageService::class),
            new RagIndexingCoordinator($indexer),
            $policy,
            $authorization,
        );
    }

    private function warmSnapshot(AssistantIndexStatusService $service, int $organizationId, int $actorId): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $previousSurface = $policy->trustedSurface();
        $policy->setTrustedSurface(KnowledgeSurface::LK);
        try {
            $service->refreshSnapshot($organizationId, $actorId, KnowledgeSurface::LK, 'ai-rag-status:'.$organizationId.':'.$actorId.':lk');
        } finally {
            $policy->setTrustedSurface($previousSurface);
        }
    }
}
