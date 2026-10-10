<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\RefreshAssistantIndexStatusJob;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotBuilder;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotDiagnostics;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotFootprint;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotInputs;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Logging\LoggingService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class AssistantStatusSnapshotTest extends TestCase
{
    private \Illuminate\Support\Testing\Fakes\QueueFake $queue;
    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
        config(['ai-assistant.status_snapshots' => true, 'ai-assistant.status_snapshot_release' => str_repeat('a', 40)]);
        $this->queue = Queue::fake();
        Cache::flush();
    }

    public function test_source_counts_stay_current_while_indexed_chunks_change(): void
    {
        [$organization, $actor, $project, $service] = $this->scope();
        $metrics = new ApiQueryMetrics(true);
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        $source = RagSource::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'title' => 'Current project', 'checksum' => hash('sha256', 'live-source'),
        ]);
        $chunkId = DB::table('ai_rag_chunks')->insertGetId([
            'source_id' => $source->id, 'organization_id' => $organization->id, 'project_id' => $project->id,
            'chunk_index' => 0, 'content' => 'Current source', 'content_hash' => hash('sha256', 'live-chunk'),
            'embedding' => '['.implode(',', \Tests\Support\RagTestEmbedding::fromLeadingValues([1.0])).']',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $status = $service->status($organization->id, $actor, 'sources');
        self::assertTrue($status['status_available']);
        self::assertSame(1, $status['source_count']);
        self::assertSame(1, $status['chunk_count']);
        self::assertSame(1, $status['indexed_source_count']);
        self::assertTrue($status['ready']);
        self::assertSame('fresh_read', $metrics->summary()['assistant_snapshot']['phase']);
        foreach (['rag_prepare', 'rag_schema_prefetch', 'rag_source_prepare', 'rag_source_acl', 'rag_acl_discovery', 'rag_acl_batch_compile', 'rag_source_counts', 'rag_finalize'] as $phase) {
            self::assertArrayHasKey($phase, $metrics->summary()['processing_phases']);
            self::assertGreaterThanOrEqual(0, $metrics->summary()['processing_phases'][$phase]['sql_count']);
        }
        self::assertNull($status['expected_source_count']);
        self::assertArrayNotHasKey('rag_expected_counts', $metrics->summary()['processing_phases']);

        DB::table('ai_rag_chunks')->where('id', $chunkId)->update(['embedding' => null]);
        $status = $service->status($organization->id, $actor, 'sources');
        self::assertTrue($status['status_available']);
        self::assertSame(1, $status['source_count']);
        self::assertSame(1, $status['chunk_count']);
        self::assertSame(0, $status['indexed_source_count']);
        self::assertFalse($status['ready']);

        DB::table('ai_rag_chunks')->where('id', $chunkId)->delete();
        self::assertSame(0, $service->status($organization->id, $actor, 'sources')['chunk_count']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_live_source_counts_keep_current_project_and_organization_scope(): void
    {
        [$organization, $actor, $project, $service] = $this->scope();
        $hiddenProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $foreignOrganization->id, 'is_archived' => false]));
        foreach ([[$organization, $project], [$organization, $hiddenProject], [$foreignOrganization, $foreignProject]] as [$sourceOrganization, $sourceProject]) {
            RagSource::query()->create([
                'organization_id' => $sourceOrganization->id, 'project_id' => $sourceProject->id,
                'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $sourceProject->id,
                'title' => 'Scoped project', 'checksum' => hash('sha256', 'project-'.$sourceProject->id),
            ]);
        }

        self::assertSame(1, $service->status($organization->id, $actor, 'sources')['source_count']);
        DB::table('projects')->where('id', $project->id)->update(['is_archived' => true]);
        self::assertSame(0, $service->status($organization->id, $actor, 'sources')['source_count']);
    }

    public function test_live_source_status_rechecks_assistant_permission(): void
    {
        [$organization, $actor, , $service, , $permissions] = $this->scope();
        self::assertTrue($service->status($organization->id, $actor, 'sources')['status_available']);
        $permissions->denied = ['ai_assistant.chat'];

        $this->expectException(AuthorizationException::class);
        $service->status($organization->id, $actor, 'sources');
    }

    public function test_live_source_status_reloads_actor_activity(): void
    {
        [$organization, $actor, , $service] = $this->scope();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);
        $service->status($organization->id, $actor, 'sources');
    }

    public function test_live_source_status_fails_closed_if_a_native_permission_expires_during_read(): void
    {
        [$organization, $actor, , $service] = $this->scope();
        \App\Domain\Authorization\Models\UserRoleAssignment::query()->create([
            'user_id' => $actor->id, 'role_slug' => 'organization_admin',
            'role_type' => \App\Domain\Authorization\Models\UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($organization->id)->id,
            'assigned_by' => $actor->id, 'is_active' => true, 'expires_at' => now()->addSeconds(10),
        ]);
        $clockAdvanced = false;
        DB::listen(static function ($query) use (&$clockAdvanced): void {
            if (! $clockAdvanced && str_contains(strtolower($query->sql), 'accessible_sources.source_type, count(*) as stored_count')) {
                $clockAdvanced = true;
                \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(20));
            }
        });

        try {
            $status = $service->status($organization->id, $actor, 'sources');
            self::assertTrue($clockAdvanced);
            self::assertFalse($status['status_available']);
            self::assertNull($status['source_count']);
        } finally { \Illuminate\Support\Carbon::setTestNow(); }
    }

    public function test_live_source_status_keeps_the_existing_budget_and_reports_unknown_on_expiry(): void
    {
        [$organization, $actor, , $service] = $this->scope();
        $metrics = new ApiQueryMetrics(true);
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        $delayed = false;
        DB::listen(static function ($query) use (&$delayed): void {
            if (! $delayed && str_contains(strtolower($query->sql), 'accessible_sources.source_type, count(*) as stored_count')) {
                $delayed = true;
                usleep(2_600_000);
            }
        });

        $status = $service->status($organization->id, $actor, 'sources');
        self::assertTrue($delayed);
        self::assertFalse($status['status_available']);
        self::assertNull($status['source_count']);
        self::assertArrayHasKey('rag_source_counts', $metrics->summary()['processing_phases']);
        self::assertGreaterThanOrEqual(2500, $metrics->summary()['processing_phases']['rag_source_counts']['total_ms']);
        self::assertSame('fresh_read_unavailable', $metrics->summary()['assistant_snapshot']['phase']);
        self::assertSame(str_repeat('a', 40), $metrics->summary()['assistant_snapshot']['release_sha']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_live_source_counts_stay_bounded_for_a_large_project_corpus(): void
    {
        [$organization, $actor, $project, $service] = $this->scope();
        $timestamp = now();
        for ($batch = 0; $batch < 100; $batch++) {
            $sources = [];
            for ($index = 0; $index < 1000; $index++) {
                $sources[] = [
                    'organization_id' => $organization->id, 'project_id' => $project->id,
                    'identity_project_id' => $project->id, 'identity_part_key' => hash('sha256', 'part-'.$batch.'-'.$index),
                    'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
                    'title' => 'Large project corpus', 'checksum' => hash('sha256', 'large-source'),
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ];
            }
            DB::table('ai_rag_sources')->insert($sources);
        }
        $queries = 0;
        DB::listen(static function () use (&$queries): void { $queries++; });
        $started = hrtime(true);
        $status = $service->status($organization->id, $actor, 'sources');
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertTrue($status['status_available'], 'Large corpus read took '.round($elapsedMs).'ms and '.$queries.' SQL statements.');
        self::assertSame(100000, $status['source_count']);
        self::assertSame(0, $status['chunk_count']);
        self::assertLessThanOrEqual(90, $queries, 'Large corpus read must not query per source.');
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
        fwrite(STDERR, 'Live source corpus: 100000 rows, '.round($elapsedMs, 2).'ms, '.$queries.' SQL statements.'.PHP_EOL);
    }

    public function test_background_counts_are_used_only_with_current_database_permissions_and_generation(): void
    {
        [$organization, $actor, $project, $service, $policy, $permissions] = $this->scope();
        $source = RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'identity_part_key' => '', 'title' => 'Project', 'checksum' => hash('sha256', 'snapshot')])->refresh();
        $otherOrganization = Organization::factory()->create();
        $otherSource = RagSource::query()->create(['organization_id' => $otherOrganization->id, 'project_id' => null,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => 'other-source',
            'identity_part_key' => '', 'title' => 'Other project', 'checksum' => hash('sha256', 'other-snapshot')]);
        $generation = '59c9320d-a413-4510-8d0c-858d28a40910';
        \App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'identity_project_id' => $source->identity_project_id,
            'identity_part_key' => $source->identity_part_key, 'source_type' => 'project', 'entity_type' => 'project',
            'entity_id' => (string) $project->id, 'generation' => $generation, 'checksum' => $source->checksum, 'pending_since' => now()]);
        $coverageKey = 'ai-rag-coverage:'.$organization->id.':0:*:'.(int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        $projection = ['projection_generation' => $generation, 'eligible_count_known' => true,
            'snapshot_at' => now()->toAtomString(), 'source_catalog' => [['type' => 'project']]];
        $stateStore = app(RagCoverageStateStore::class);
        $stateStore->publish((int) $organization->id, $stateStore->revision((int) $organization->id), $projection);
        Cache::put($coverageKey, $projection, 300);
        $metrics = new ApiQueryMetrics(true);
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        $live = $service->status($organization->id, $actor, 'sources');
        self::assertTrue($live['status_available']);
        self::assertSame(1, $live['expected_source_count']);
        self::assertArrayHasKey('rag_expected_counts', $metrics->summary()['processing_phases']);
        self::assertGreaterThanOrEqual(0, $metrics->summary()['processing_phases']['rag_expected_counts']['sql_count']);
        self::assertFalse($service->status($organization->id, $actor, 'all')['status_available']);
        self::assertSame('snapshot_missing', $metrics->summary()['assistant_snapshot']['phase']);
        self::assertTrue($metrics->summary()['assistant_snapshot']['refresh_queued']);
        self::assertFalse($service->status($organization->id, $actor, 'all')['status_available']);
        self::assertFalse($metrics->summary()['assistant_snapshot']['refresh_queued']);
        Queue::assertPushed(RefreshAssistantIndexStatusJob::class, 1);
        $job = $this->queue->pushed(RefreshAssistantIndexStatusJob::class)->last();
        $warnings = [];
        $refreshEvents = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('info')->with('assistant_status_snapshot_refresh', Mockery::on(static function (array $context) use (&$refreshEvents): bool {
            $refreshEvents[] = $context;

            return true;
        }));
        \Illuminate\Support\Facades\Log::partialMock()->shouldReceive('channel')->with('api_latency')->andReturn($logger);
        \Illuminate\Support\Facades\Log::partialMock()->shouldReceive('warning')->andReturnUsing(static function (string $message, array $context = []) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });
        $job->handle($service, $policy);
        self::assertNotNull(Cache::get($job->cacheKey), json_encode($warnings, JSON_THROW_ON_ERROR));
        self::assertSame(['started', 'written'], array_column($refreshEvents, 'phase'));
        $mismatched = clone $job;
        $mismatched->cacheKey .= ':mismatch';
        $mismatched->handle($service, $policy);
        self::assertSame('cache_key_mismatch', $refreshEvents[3]['phase']);
        self::assertNotSame($refreshEvents[3]['key_hash'], $refreshEvents[3]['expected_key_hash']);
        self::assertNull(Cache::get($mismatched->cacheKey));
        self::assertStringNotContainsString($job->cacheKey, json_encode($refreshEvents, JSON_THROW_ON_ERROR));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $status = $service->status($organization->id, $actor, 'all');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        self::assertTrue($status['status_available']);
        self::assertSame('ready', $metrics->summary()['assistant_snapshot']['phase']);
        self::assertSame(1, $status['source_count']);
        self::assertSame(1, $status['expected_source_count']);
        self::assertFalse((bool) preg_grep('/COUNT\(\*\) AS stored_count/i', $queries));
        $otherSource->forceFill(['checksum' => hash('sha256', 'other-updated'), 'metadata' => ['assistant_public_schema_revision' => 'changed']])->save();
        DB::table('ai_rag_status_sources')->where('id', $otherSource->id)->update(['chunk_count' => 3, 'indexed_chunk_count' => 3]);
        $stateStore->markIndexChanged((int) $otherOrganization->id);
        $unchanged = $service->status($organization->id, $actor, 'all');
        self::assertTrue($unchanged['status_available']);
        self::assertSame(1, $unchanged['source_count']);
        self::assertSame('valid', $metrics->summary()['assistant_snapshot_epoch']['phase']);
        $permissions->denied = ['projects.view'];
        self::assertFalse($service->status($organization->id, $actor, 'all')['status_available']);
        self::assertSame('snapshot_rejected', $metrics->summary()['assistant_snapshot']['phase']);
        $permissions->denied = [];
        DB::table('projects')->where('id', $project->id)->update(['is_archived' => true]);
        self::assertFalse($service->status($organization->id, $actor, 'all')['status_available']);
        $job->handle($service, $policy);
        self::assertSame(0, $service->status($organization->id, $actor, 'all')['source_count']);
        DB::table('projects')->where('id', $project->id)->update(['is_archived' => false]);
        $job->handle($service, $policy);
        self::assertSame(1, $service->status($organization->id, $actor, 'all')['source_count']);
        DB::table('ai_rag_sources')->where('id', $source->id)->delete();
        self::assertFalse($service->status($organization->id, $actor, 'all')['status_available']);
        $job->handle($service, $policy);
        self::assertSame(0, $service->status($organization->id, $actor, 'all')['source_count']);
        $changedGeneration = '6f207c85-81bb-4c2b-8991-9e1fe6e7eb94';
        $stateStore->publish((int) $organization->id, $stateStore->revision((int) $organization->id),
            array_replace($projection, ['projection_generation' => $changedGeneration]));
        Cache::put('ai-rag-coverage:'.$organization->id.':0:*:'.(int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0), ['projection_generation' => $changedGeneration], 60);
        self::assertFalse($service->status($organization->id, $actor, 'all')['status_available']);
        $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $service->status($organization->id, $actor, 'all');
    }

    public function test_postgres_plans_capture_ctes_nested_relations_and_preserve_listeners(): void
    {
        $events = 0;
        $dispatcher = DB::connection()->getEventDispatcher();
        DB::listen(static function () use (&$events): void { $events++; });
        $result = app(AssistantStatusSnapshotFootprint::class)->capture(fn () => DB::select('WITH first_rows AS MATERIALIZED (SELECT id FROM public.projects) SELECT * FROM first_rows WHERE EXISTS (SELECT 1 FROM public.organizations)'));
        self::assertSame(['public.organizations', 'public.projects'], $result['relations']);
        self::assertSame($dispatcher, DB::connection()->getEventDispatcher());
        self::assertGreaterThan(1, $events);
        try {
            app(AssistantStatusSnapshotFootprint::class)->capture(static fn () => throw new \RuntimeException('capture-test'));
            self::fail('Expected exception');
        } catch (\RuntimeException $exception) { self::assertSame('capture-test', $exception->getMessage()); }
        self::assertSame($dispatcher, DB::connection()->getEventDispatcher());
        DB::statement('CREATE FUNCTION public.assistant_snapshot_hidden_test() RETURNS bigint LANGUAGE sql AS $$ SELECT count(*) FROM public.projects $$');
        try {
            $result = app(AssistantStatusSnapshotFootprint::class)->capture(fn () => DB::select('SELECT public.assistant_snapshot_hidden_test()'));
            self::assertNull($result['relations']);
        } finally { DB::statement('DROP FUNCTION public.assistant_snapshot_hidden_test()'); }
        DB::statement('CREATE FUNCTION public."hidden mv reader"() RETURNS bigint LANGUAGE sql AS $$ SELECT count(*) FROM public.projects $$');
        try {
            $result = app(AssistantStatusSnapshotFootprint::class)->capture(fn () => DB::select('SELECT public."hidden mv reader"()'));
            self::assertNull($result['relations']);
        } finally { DB::statement('DROP FUNCTION public."hidden mv reader"()'); }
        $result = app(AssistantStatusSnapshotFootprint::class)->capture(static function (): void {
            DB::select('SELECT id FROM public.projects');
            DB::select("SELECT pg_catalog.query_to_xml(?, false, false, '')", ['SELECT id FROM public.organizations']);
        });
        self::assertNull($result['relations']);
        config(['database.connections.assistant_snapshot_foreign' => config('database.connections.pgsql')]);
        $foreign = DB::connection('assistant_snapshot_foreign');
        try {
            $result = app(AssistantStatusSnapshotFootprint::class)->capture(static function () use ($foreign): void {
                DB::select('SELECT id FROM public.projects');
                $foreign->select('SELECT id FROM public.organizations');
            });
            self::assertNull($result['relations']);
        } finally { DB::purge('assistant_snapshot_foreign'); }
    }

    public function test_postgres_footprint_accepts_native_schema_introspection_and_rejects_shadowed_functions(): void
    {
        $connection = DB::connection();
        $connection->getSchemaBuilder();
        $columnsSql = $connection->getSchemaGrammar()->compileColumns(null, 'ai_assistant_documents');
        self::assertStringContainsString('current_schema()', $columnsSql);
        $result = app(AssistantStatusSnapshotFootprint::class)->capture(static function () use ($columnsSql): array {
            DB::select('select current_schema()');
            $columns = DB::select($columnsSql);
            DB::table('public.projects')->select('id')->get();

            return array_column($columns, 'name');
        });
        self::assertContains('file_id', $result['value']);
        self::assertSame(['public.projects'], $result['relations'], $result['reason'] ?? '');
        $catalogOnly = app(AssistantStatusSnapshotFootprint::class)->capture(static fn () => DB::select('select current_schema()'));
        self::assertNull($catalogOnly['relations']);

        DB::statement('CREATE FUNCTION public."current_schema"() RETURNS name LANGUAGE sql AS $$ SELECT \'public\'::name $$');
        try {
            foreach (['select current_schema()', 'select public."current_schema"()'] as $sql) {
                $rejected = app(AssistantStatusSnapshotFootprint::class)->capture(static function () use ($sql): void {
                    DB::table('public.projects')->select('id')->get();
                    DB::select($sql);
                });
                self::assertNull($rejected['relations']);
                self::assertSame('unsupported_function:current_schema', $rejected['reason']);
            }
            $qualified = app(AssistantStatusSnapshotFootprint::class)->capture(static function (): void {
                DB::table('public.projects')->select('id')->get();
                DB::select('select pg_catalog.current_schema()');
            });
            self::assertSame(['public.projects'], $qualified['relations']);
        } finally {
            DB::statement('DROP FUNCTION public."current_schema"()');
        }
    }

    public function test_invalid_release_is_diagnosed_without_dispatching_or_returning_a_snapshot(): void
    {
        [$organization, $actor, , $service] = $this->scope();
        config(['ai-assistant.status_snapshot_release' => 'invalid-release']);
        $metrics = new ApiQueryMetrics;
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);

        self::assertFalse($service->status($organization->id, $actor, 'sources')['status_available']);
        self::assertSame('missing_release', $metrics->summary()['assistant_snapshot']['phase']);
        self::assertNull($metrics->summary()['assistant_snapshot']['release_sha']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_snapshot_refresh_diagnostics_only_emit_bounded_failure_reasons(): void
    {
        $events = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('info')->with('assistant_status_snapshot_refresh', Mockery::on(static function (array $context) use (&$events): bool {
            $events[] = $context;

            return true;
        }));
        \Illuminate\Support\Facades\Log::partialMock()->shouldReceive('channel')->with('api_latency')->andReturn($logger);
        foreach (['unsupported_function:private_function', 'plan_unavailable:private_exception', 'unproven_database_epoch', 'unexpected private detail'] as $reason) {
            AssistantStatusSnapshotDiagnostics::refresh('snapshot_unproven', 'documents', 'private-cache-key', reason: $reason);
        }
        AssistantStatusSnapshotDiagnostics::refresh('written', 'documents', 'private-cache-key', reason: 'unsupported_function:private_function');
        self::assertSame(['unsupported_function', 'plan_unavailable', 'unproven_database_epoch', 'unclassified', null], array_column($events, 'reason'));
        self::assertStringNotContainsString('private', json_encode($events, JSON_THROW_ON_ERROR));
        self::assertSame(str_repeat('a', 40), $events[0]['release_sha']);
    }

    public function test_missing_release_survives_the_final_budget_checkpoint(): void
    {
        [$organization, $actor, , $service, , $permissions] = $this->scope();
        config(['ai-assistant.status_snapshot_release' => 'invalid-release']);
        $permissions->assistantDelayMicroseconds = 2_600_000;
        $metrics = new ApiQueryMetrics;
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);

        self::assertFalse($service->status($organization->id, $actor, 'sources')['status_available']);
        self::assertSame('missing_release', $metrics->summary()['assistant_snapshot']['phase']);
        self::assertNull($metrics->summary()['assistant_snapshot']['release_sha']);
        Queue::assertNotPushed(RefreshAssistantIndexStatusJob::class);
    }

    public function test_snapshot_controller_keeps_its_service_gate_on_all_surfaces(): void
    {
        [$organization, $actor, , $service, $policy, $permissions] = $this->scope();
        $controller = new \App\BusinessModules\Features\AIAssistant\Http\Controllers\AIAssistantRagController($service);
        foreach (['api/v1/admin/ai-assistant', 'api/v1/mobile/ai-assistant', 'api/v1/ai-assistant'] as $prefix) {
            $request = \App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantRagStatusRequest::create('/'.$prefix.'/rag/status', 'GET', ['section' => 'sources']);
            $this->app->instance('request', $request);
            $request->setUserResolver(static fn (): User => $actor);
            $request->setValidator(\Illuminate\Support\Facades\Validator::make(['section' => 'sources'], $request->rules()));
            $permissions->denied = [];
            self::assertSame(200, $controller->status($request)->getStatusCode());
            $permissions->denied = ['ai_assistant.chat'];
            self::assertSame(403, $controller->status($request)->getStatusCode());
        }
        $permissions->denied = [];
        $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);
        self::assertSame(403, $controller->status($request)->getStatusCode());
    }

    public function test_deadline_uses_earliest_native_expiry_and_rejects_malformed_clock(): void
    {
        $inputs = app(AssistantStatusSnapshotInputs::class);
        $captured = now()->startOfSecond();
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        self::assertSame($captured->copy()->addSeconds(60)->toIso8601String(), $inputs->validUntil($captured->toIso8601String(), 60, $organization->id, $actor->id));
        \App\Domain\Authorization\Models\UserRoleAssignment::query()->create(['user_id' => $actor->id,
            'role_slug' => 'snapshot-clock', 'role_type' => 'system',
            'context_id' => AuthorizationContext::getOrganizationContext($organization->id)->id,
            'expires_at' => $captured->copy()->addSeconds(20), 'is_active' => true]);
        self::assertSame($captured->copy()->addSeconds(20)->toIso8601String(), $inputs->validUntil($captured->toIso8601String(), 60, $organization->id, $actor->id));
        $account = \App\Models\OrganizationCommercialAccount::query()->updateOrCreate(['organization_id' => $organization->id],
            ['status' => 'grace', 'offer_type' => 'packages', 'quote_version' => 1,
                'grace_ends_at' => $captured->copy()->addSeconds(15)]);
        self::assertSame($captured->copy()->addSeconds(15)->toIso8601String(), $inputs->validUntil($captured->toIso8601String(), 60, $organization->id, $actor->id));
        \App\Models\OrganizationPackageSubscription::query()->create(['organization_id' => $organization->id,
            'commercial_account_id' => $account->id, 'package_slug' => 'machinery', 'status' => 'trialing', 'access_source' => 'trial',
            'trial_ends_at' => $captured->copy()->addSeconds(10), 'current_period_end_at' => $captured->copy()->addSeconds(5)]);
        self::assertSame($captured->copy()->addSeconds(5)->toIso8601String(), $inputs->validUntil($captured->toIso8601String(), 60, $organization->id, $actor->id));
        $foreign = Organization::factory()->create();
        \App\Models\OrganizationCommercialAccount::query()->updateOrCreate(['organization_id' => $foreign->id],
            ['status' => 'grace', 'offer_type' => 'packages', 'quote_version' => 1, 'grace_ends_at' => $captured->copy()->addSecond()]);
        self::assertSame($captured->copy()->addSeconds(5)->toIso8601String(), $inputs->validUntil($captured->toIso8601String(), 60, $organization->id, $actor->id));
        self::assertFalse($inputs->isUnexpired('broken'));
        self::assertFalse($inputs->isUnexpired(null));
        self::assertFalse($inputs->serializableContext(['entity' => new \stdClass]));
    }

    private function scope(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]));
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $permissions = (object) ['denied' => [], 'assistantDelayMicroseconds' => 0];
        $authorization = new class(Mockery::mock(RoleScanner::class), Mockery::mock(PermissionResolver::class), Mockery::mock(LoggingService::class), $permissions) extends AuthorizationService {
            public function __construct(RoleScanner $roles, PermissionResolver $resolver, LoggingService $logging, private readonly object $permissions)
            { parent::__construct($roles, $resolver, $logging); }
            public function getUserRoles(User $user, ?AuthorizationContext $context = null): Collection { return collect(); }
            protected function checkPermission(User $user, string $permission, ?array $context = null): bool
            { if ($permission === 'ai_assistant.chat') { usleep($this->permissions->assistantDelayMicroseconds); }
                return in_array($permission, ['projects.view', 'contracts.view', 'finance.view', 'ai_assistant.chat', 'admin.ai_assistant.rag.manage'], true)
                && ! in_array($permission, $this->permissions->denied, true); }
            public function forCurrentChecks(bool $memoizeReads = false): AuthorizationService { return clone $this; }
        };
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'ai-assistant'],
            (object) ['slug' => 'payments'], (object) ['slug' => 'contract-management']]));
        $modules->shouldReceive('getEffectiveModuleSlugs')->andReturn(['project-management', 'ai-assistant', 'payments', 'contract-management']);
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $collector = Mockery::mock(RagSourceCollectorInterface::class);
        $collector->shouldReceive('sourceType')->andReturn('project');
        $collector->shouldReceive('enabled')->andReturnTrue();
        $registry = new RagSourceRegistry([$collector]);
        $indexer = new RagIndexer(Mockery::mock(RagEmbeddingProviderInterface::class), $registry);
        $coverage = new RagCoverageService($registry, $indexer, $policy);
        $inputs = new AssistantStatusSnapshotInputs($modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(RagCoverageService::class, $coverage);
        $this->app->instance(AssistantStatusSnapshotInputs::class, $inputs);
        $service = new AssistantIndexStatusService($coverage, app(AssistantDocumentCoverageService::class), new RagIndexingCoordinator($indexer), $policy, $authorization, snapshotInputs: $inputs);
        self::assertInstanceOf(AssistantStatusSnapshotBuilder::class, app(AssistantStatusSnapshotBuilder::class));

        return [$organization, $actor, $project, $service, $policy, $permissions];
    }
}
