<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshRagCoverageJob;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class RagActorCoverageTest extends TestCase
{
    private RagCoverageService $coverage;
    private RagIndexer $indexer;
    private ActorCoverageCollector $collector;
    private bool $permissions = true;
    private array $permissionChecks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(function (User $actor, string $permission): bool {
            $this->permissionChecks[] = $permission;

            return $this->permissions && in_array($permission, ['projects.view', 'finance.view'], true);
        });
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'payments']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService(), $modules);
        $this->collector = new ActorCoverageCollector();
        $registry = new RagSourceRegistry([$this->collector]);
        $this->indexer = new RagIndexer(new ActorCoverageEmbedding(), $registry);
        $this->coverage = new RagCoverageService($registry, $this->indexer, $policy);
    }

    public function test_snapshot_counts_use_the_projection_and_preserve_scope_and_stale_state(): void
    {
        [$organization, , $visible] = $this->scope();
        $hidden = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $foreign = Project::factory()->create();
        $this->index($organization->id, $visible);
        $visibleChunk = $this->collector->chunks[0];
        $this->index($organization->id, $hidden);
        $hiddenChunk = $this->collector->chunks[1];
        $this->index($foreign->organization_id, $foreign);
        $attributes = ['organization_id' => $organization->id, 'project_id' => $visible->id,
            'entity_id' => '999999999', 'title' => 'Stale source', 'checksum' => str_repeat('a', 64), 'metadata' => []];
        $stale = RagSource::query()->create($attributes + ['source_type' => 'project', 'entity_type' => 'project']);
        RagSource::query()->create($attributes + ['source_type' => 'warehouse', 'entity_type' => 'warehouse']);
        $this->collector->chunks = [$visibleChunk];
        DB::enableQueryLog();
        try {
            $snapshot = $this->coverage->refreshCoverage($organization->id, $visible->id, 'project');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertSame(2, $snapshot['stored_source_count']);
        self::assertSame(1, $snapshot['source_catalog'][0]['stale_count']);
        self::assertSame(2, $snapshot['source_catalog'][0]['stored_count']);
        self::assertTrue($snapshot['eligible_count_known']);
        self::assertFalse($snapshot['coverage_complete']);
        $storedCounts = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select count(*) as aggregate from ')
            && (str_contains($query['query'], 'from "ai_rag_sources"') || str_contains($query['query'], 'from "ai_rag_status_sources"')));
        self::assertCount(1, $storedCounts);
        self::assertStringContainsString('from "ai_rag_status_sources"', array_values($storedCounts)[0]['query']);
        self::assertSame([$organization->id, 'project', $visible->id], array_values($storedCounts)[0]['bindings']);
        $stale->delete();
        $snapshot = $this->coverage->refreshCoverage($organization->id, $visible->id, 'project');
        self::assertSame(1, $snapshot['stored_source_count']);
        self::assertSame(0, $snapshot['stale_source_count']);
        self::assertTrue($snapshot['coverage_complete']);
        $this->collector->chunks = [$visibleChunk, $hiddenChunk];
        $snapshot = $this->coverage->refreshCoverage($organization->id, sourceType: 'project');
        self::assertSame(2, $snapshot['stored_source_count']);
        self::assertSame(2, $snapshot['indexed_source_count']);
        self::assertTrue($snapshot['coverage_complete']);
    }

    public function test_actor_counts_exclude_private_projects_and_do_not_reuse_organization_expected_counts_or_errors(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $hidden = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $this->index($organization->id, $visible);
        $this->index($organization->id, $hidden);
        $this->coverage->refreshCoverage($organization->id);
        RagIndexRun::query()->create(['organization_id' => $organization->id, 'project_id' => $hidden->id, 'source_type' => 'project', 'status' => 'failed', 'mode' => 'async', 'last_error' => 'Private project secret']);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(1, $status['source_count']);
        $this->assertSame(1, $status['chunk_count']);
        $this->assertSame(1, $status['indexed_source_count']);
        $this->assertTrue($status['eligible_count_known']);
        $this->assertSame(1, $status['expected_source_count']);
        $this->assertSame(0, $status['pending_source_count']);
        $this->assertTrue($status['coverage_complete']);
        $this->assertNull($status['latest_run']);
        $this->assertNull($status['last_failed_run']);
        $this->assertSame(1, $status['source_catalog'][0]['indexed_count']);
        $this->assertSame(1, $status['source_catalog'][0]['expected_count']);
        $this->assertNull($status['source_catalog'][0]['error']);
        $this->assertSame(1, $this->collector->collections);
        Queue::assertNotPushed(RefreshRagCoverageJob::class);
    }

    public function test_disabled_collectors_do_not_trigger_unrelated_permission_checks(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $status = $this->coverage->coverageForActor($organization->id, $actor);

        $this->assertSame(1, $status['source_count']);
        $this->assertSame(['project'], array_column($status['source_catalog'], 'type'));
        $this->assertNotContains('payments.invoice.view', $this->permissionChecks);
    }

    public function test_projection_proof_and_counts_use_the_same_visible_rows_and_generation(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $hidden = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $this->index($organization->id, $visible);
        $this->index($organization->id, $hidden);
        $this->coverage->refreshCoverage($organization->id);
        $proof = null;
        $status = $this->coverage->coverageForActor($organization->id, $actor, projectionProof: $proof);
        $this->assertNotNull($proof);
        $this->assertCount($status['expected_source_count'], $proof['expected']);
        $this->assertCount(1, $proof['expected']);
        $row = RagExpectedSource::query()->where('organization_id', $organization->id)->where('generation', $proof['projection_generation'])
            ->where('entity_id', (string) $visible->id)->firstOrFail();
        $this->assertSame((string) $visible->id, $proof['expected'][$row->id][2]);
        $this->assertSame($row->checksum, $proof['expected'][$row->id][6]);
        $row->update(['checksum' => hash('sha256', 'changed projection row')]);
        $status = $this->coverage->coverageForActor($organization->id, $actor, projectionProof: $proof);
        $this->assertSame(1, $status['pending_source_count']);
        $this->assertSame(0, $status['indexed_source_count']);
        $this->assertSame($row->checksum, $proof['expected'][$row->id][6]);
    }

    public function test_revoked_assignment_and_permission_are_applied_before_current_counts(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->assertSame(1, $this->coverage->coverageForActor($organization->id, $actor)['source_count']);
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->assertSame(0, $this->coverage->coverageForActor($organization->id, $actor)['source_count']);
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => true]);
        $this->permissions = false;
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(0, $status['chunk_count']);
        $this->assertSame([], $status['source_catalog']);
        $this->assertFalse($status['ready']);
        $this->assertSame(0, $this->collector->collections);
    }

    public function test_counts_only_matches_projection_parts_and_current_embedding_and_assignment_revocation(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->coverage->refreshCoverage($organization->id);
        $row = RagExpectedSource::query()->where('organization_id', $organization->id)->firstOrFail();
        $row->replicate()->forceFill(['identity_part_key' => 'pending-part', 'pending_since' => now()->subMinutes(10)])->save();
        $fields = ['stored_source_count', 'indexed_source_count', 'expected_source_count', 'pending_source_count', 'stale_source_count', 'coverage_complete', 'eligible_count_known'];
        $compare = function () use ($organization, $actor, $fields): array {
            $full = $this->coverage->coverageForActor($organization->id, $actor);
            $counts = $this->coverage->coverageForActor($organization->id, $actor, countsOnly: true);
            foreach ($fields as $field) { $this->assertSame($full[$field], $counts[$field], $field); }
            $this->assertSame($full['source_catalog'], $counts['source_catalog']);

            return $counts;
        };
        $status = $compare();
        $this->assertSame(2, $status['expected_source_count']);
        $this->assertSame(1, $status['indexed_source_count']);
        $this->assertTrue($status['lag_exceeded']);
        DB::table('ai_rag_chunks')->where('organization_id', $organization->id)->update(['embedding' => null]);
        $this->assertSame(0, $compare()['indexed_source_count']);
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->assertSame(0, $compare()['expected_source_count']);
    }

    public function test_exact_complete_snapshot_requires_current_access_to_every_indexed_source(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertTrue($status['eligible_count_known']);
        $this->assertTrue($status['coverage_complete']);
        $this->assertSame(1, $status['expected_source_count']);
        $this->assertSame(0, $status['pending_source_count']);
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $restricted = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertTrue($restricted['eligible_count_known']);
        $this->assertSame(0, $restricted['expected_source_count']);
        $this->assertSame(0, $restricted['indexed_source_count']);
    }

    public function test_visible_unindexed_and_changed_entities_are_pending_without_private_counts_or_lag(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $hidden = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $changed = new RagChunkData($organization->id, $visible->id, 'project', 'project', $visible->id, 'Проект', 'Новый текст', [], now()->subSeconds(90));
        $this->collector->chunks = [$changed, new RagChunkData($organization->id, $hidden->id, 'project', 'project', $hidden->id, 'Скрытый', 'Скрытый', [], now()->subDays(20))];
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(1, $status['expected_source_count']);
        $this->assertSame(0, $status['indexed_source_count']);
        $this->assertSame(1, $status['pending_source_count']);
        $this->assertGreaterThanOrEqual(90, $status['lag_seconds']);
        $this->assertLessThan(100, $status['lag_seconds']);
        $this->assertFalse($status['lag_exceeded']);
        $this->assertSame(1, $this->collector->collections);
        $this->indexer->indexChunk($changed);
        $this->assertSame(0, $this->coverage->coverageForActor($organization->id, $actor)['pending_source_count']);
        $changed = new RagChunkData($organization->id, $visible->id, 'project', 'project', $visible->id, 'Проект', 'Изменённый текст', [], now());
        $this->collector->chunks[0] = $changed;
        RagCoverageService::invalidate($organization->id);
        $this->assertFalse($this->coverage->coverageForActor($organization->id, $actor)['eligible_count_known']);
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(0, $status['indexed_source_count']);
        $this->assertSame(1, $status['stale_source_count']);
        $this->assertSame(1, $status['pending_source_count']);
        $this->assertFalse($status['ready']);
    }

    public function test_entity_removal_and_permission_revocation_filter_projection_before_count(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->coverage->refreshCoverage($organization->id);
        $this->permissions = false;
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(0, $status['expected_source_count']);
        $this->assertSame(0, $status['pending_source_count']);
        $this->assertSame([], $status['source_catalog']);
        $this->permissions = true;
        $visible->delete();
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertFalse($status['eligible_count_known']);
        $this->assertNull($status['expected_source_count']);
        $this->assertNull($status['pending_source_count']);
        $this->assertSame(0, $status['stored_source_count']);
        $this->assertSame(1, $this->collector->collections);
        $this->collector->chunks = [];
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertTrue($status['eligible_count_known']);
        $this->assertSame(0, $status['expected_source_count']);
        $this->assertSame(0, $status['pending_source_count']);
        $this->assertSame(0, $status['indexed_source_count']);
    }

    public function test_failed_or_revision_changed_traversal_never_publishes_partial_projection(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->collector->failure = true;
        $this->coverage->refreshCoverage($organization->id);
        $this->assertFalse($this->coverage->coverageForActor($organization->id, $actor)['eligible_count_known']);
        $this->collector->failure = false;
        $this->collector->afterCollection = static fn () => RagCoverageService::invalidate($organization->id);
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertFalse($status['eligible_count_known']);
        $this->assertNull($status['expected_source_count']);
        $this->assertNull($status['lag_seconds']);
        $this->assertNull($status['source_catalog'][0]['error']);
        $this->collector->afterCollection = null;
        $this->coverage->refreshCoverage($organization->id);
        $this->assertTrue($this->coverage->coverageForActor($organization->id, $actor)['coverage_complete']);
    }

    #[DataProvider('unpublishedProjectionFailures')]
    public function test_failed_staged_generation_is_discarded_without_publishing_partial_counts(bool $revisionChanged): void
    {
        [$organization, $actor, $visible] = $this->scope();
        for ($part = 0; $part < 101; $part++) {
            $this->collector->chunks[] = new RagChunkData($organization->id, $visible->id, 'project', 'project', $visible->id,
                'Проект', 'Content '.$part, ['unit_id' => $part]);
        }
        $stagedCount = 0;
        $this->collector->afterCollection = static function () use ($organization, $revisionChanged, &$stagedCount): void {
            $stagedCount = RagExpectedSource::query()->where('organization_id', $organization->id)->count();
            if ($revisionChanged) {
                RagCoverageService::invalidate($organization->id);
            }
        };
        $this->collector->failure = ! $revisionChanged;

        $this->coverage->refreshCoverage($organization->id);

        $this->assertSame(100, $stagedCount);
        $this->assertSame(0, RagExpectedSource::query()->where('organization_id', $organization->id)->count());
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertFalse($status['eligible_count_known']);
        $this->assertNull($status['expected_source_count']);
    }

    public static function unpublishedProjectionFailures(): array
    {
        return ['collector failure' => [false], 'revision changed after staging' => [true]];
    }

    public function test_revision_change_stops_the_obsolete_collector_after_the_current_batch(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        for ($part = 0; $part < 300; $part++) {
            $this->collector->chunks[] = new RagChunkData($organization->id, $visible->id, 'project', 'project', $visible->id,
                'Проект', 'Content '.$part, ['unit_id' => $part]);
        }
        $this->collector->afterChunk = static function (int $yielded) use ($organization): void {
            if ($yielded === 100) {
                RagCoverageService::invalidate($organization->id);
            }
        };

        $this->coverage->refreshCoverage($organization->id);

        $this->assertSame(101, $this->collector->yielded);
        $this->assertSame(0, RagExpectedSource::query()->where('organization_id', $organization->id)->count());
        $this->assertFalse($this->coverage->coverageForActor($organization->id, $actor)['eligible_count_known']);
    }

    public function test_stale_projection_does_not_claim_expected_coverage(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->coverage->refreshCoverage($organization->id);
        $this->travel(301)->seconds();
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertFalse($status['eligible_count_known']);
        $this->assertNull($status['expected_source_count']);
        $this->assertSame(1, $this->collector->collections);
    }

    public function test_successful_full_refresh_reconciles_removed_expected_identities(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->coverage->refreshCoverage($organization->id);
        $this->collector->chunks = [];
        RagCoverageService::invalidate($organization->id);
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(0, $status['expected_source_count']);
        $this->assertSame(0, $status['indexed_source_count']);
        $this->assertSame(1, $status['stale_source_count']);
        $this->assertFalse($status['coverage_complete']);
    }

    public function test_projection_batches_preserve_part_identity_without_storing_content(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        for ($part = 0; $part < 101; $part++) {
            $this->collector->chunks[] = new RagChunkData($organization->id, $visible->id, 'project', 'project', $visible->id,
                'Проект', 'Секретный текст', ['unit_id' => $part], now()->subSeconds(10));
        }
        $this->collector->chunks[] = $this->collector->chunks[0];
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertSame(101, $status['expected_source_count']);
        $this->assertSame(101, $status['pending_source_count']);
        $row = RagExpectedSource::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertArrayNotHasKey('title', $row->getAttributes());
        $this->assertArrayNotHasKey('content', $row->getAttributes());
        $this->assertArrayNotHasKey('metadata', $row->getAttributes());
        $this->assertSame(1, $this->collector->collections);
    }

    #[DataProvider('embeddingRegistryModes')]
    public function test_current_source_coverage_queries_grow_by_batch_instead_of_source(bool $withRegistry): void
    {
        [$organization, $actor, $visible] = $this->scope();
        if ($withRegistry) {
            $registry = new RagSourceRegistry([$this->collector]);
            $provider = new ActorCoverageEmbedding;
            $this->indexer = new RagIndexer($provider, $registry, embeddingProviderRegistry: new RagEmbeddingProviderRegistry($provider));
            $this->coverage = new RagCoverageService($registry, $this->indexer);
        }
        $vector = json_encode(RagTestEmbedding::fromLeadingValues([0.1, 0.2]), JSON_THROW_ON_ERROR);
        for ($part = 0; $part < 101; $part++) {
            $chunk = new RagChunkData($organization->id, $visible->id, 'project', 'project', $visible->id,
                'Проект', 'Content '.$part, ['unit_id' => $part]);
            $this->collector->chunks[] = $chunk;
            $source = RagSource::query()->create($this->indexer->coverageIdentity($chunk) + ['title' => 'Проект', 'metadata' => $chunk->metadata]);
            DB::table('ai_rag_chunks')->insert([
                'source_id' => $source->id, 'organization_id' => $organization->id, 'project_id' => $visible->id,
                'chunk_index' => 0, 'content' => $chunk->content, 'content_hash' => hash('sha256', $chunk->content),
                'embedding_provider' => 'fake', 'embedding_model' => 'fake', 'embedding' => $vector,
            ]);
        }
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'ai_rag_chunks')) {
                $queries[] = $event->sql;
            }
        });
        $snapshot = $this->coverage->refreshCoverage($organization->id);
        $this->assertSame(101, $snapshot['indexed_source_count']);
        $this->assertTrue($snapshot['coverage_complete']);
        fwrite(STDERR, json_encode(['coverage_sources' => 101, 'provider_registry' => $withRegistry, 'chunk_read_queries' => count($queries)], JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertLessThanOrEqual(6, count($queries));
        $this->assertCount(0, array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'COUNT(*) AS total')));
    }

    public static function embeddingRegistryModes(): array
    {
        return [[false], [true]];
    }

    public function test_duplicate_source_checks_reuse_the_same_chunk_read(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'ai_rag_chunks')) {
                $queries[] = $event->sql;
            }
        });
        $state = $this->indexer->coverageBatch(array_fill(0, 100, $this->collector->chunks[0]));
        $this->assertCount(100, $state['matches']);
        $this->assertSame([true], array_values(array_unique($state['matches'])));
        $this->assertCount(2, $queries);
    }

    public function test_batch_coverage_rejects_corrupt_content_missing_vectors_and_incompatible_profiles(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $registry = new RagSourceRegistry([$this->collector]);
        $provider = new ActorCoverageEmbedding;
        $indexer = new RagIndexer($provider, $registry, embeddingProviderRegistry: new RagEmbeddingProviderRegistry($provider));
        $this->index($organization->id, $visible);
        $source = RagSource::query()->where('organization_id', $organization->id)->firstOrFail();
        $batch = [$this->collector->chunks[0]];
        $this->assertSame([true], $indexer->coverageBatch($batch)['matches']);
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update(['embedding' => null]);
        $this->assertSame([false], $indexer->coverageBatch($batch)['matches']);
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update([
            'embedding' => json_encode(RagTestEmbedding::fromLeadingValues([0.1, 0.2]), JSON_THROW_ON_ERROR), 'content' => 'corrupt',
        ]);
        $this->assertSame([false], $indexer->coverageBatch($batch)['matches']);
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update(['content' => $batch[0]->content, 'embedding_provider' => 'other']);
        $this->assertSame([false], $indexer->coverageBatch($batch)['matches']);
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update(['embedding_provider' => 'fake', 'embedding_model' => 'other']);
        $this->assertSame([false], $indexer->coverageBatch($batch)['matches']);
        DB::table('ai_rag_chunks')->where('source_id', $source->id)->update([
            'embedding_model' => 'fake', 'embedding' => json_encode(array_fill(0, 1024, 0.1), JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame([false], $indexer->coverageBatch($batch)['matches']);
    }

    public function test_scoped_refresh_does_not_collect_while_the_same_scope_is_locked(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $lease = Cache::lock('ai-rag-coverage-projection:'.$organization->id.':'.$visible->id.':project', 7500);
        $this->assertTrue($lease->get());
        try {
            $snapshot = $this->coverage->refreshCoverage($organization->id, $visible->id, 'project');
            $this->assertSame(0, $this->collector->collections);
            $this->assertFalse($snapshot['eligible_count_known']);
        } finally {
            $lease->release();
        }
        $this->coverage->refreshCoverage($organization->id, $visible->id, 'project');
        $this->assertSame(1, $this->collector->collections);
    }

    public function test_obsolete_and_already_completed_coverage_jobs_do_not_repeat_collection(): void
    {
        [$organization] = $this->scope();
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        $oldKey = 'ai-rag-coverage:'.$organization->id.':0:*:'.$revision;
        RagCoverageService::invalidate($organization->id);
        (new RefreshRagCoverageJob($organization->id, null, null, $oldKey))->handle($this->coverage);
        $this->assertSame(0, $this->collector->collections);
        $currentKey = 'ai-rag-coverage:'.$organization->id.':0:*:'.($revision + 1);
        Queue::assertPushed(RefreshRagCoverageJob::class, static fn ($job): bool => $job->cacheKey === $currentKey);
        $job = new RefreshRagCoverageJob($organization->id, null, null, $currentKey);
        $job->handle($this->coverage);
        $this->assertSame(1, $this->collector->collections);
        $job->handle($this->coverage);
        $this->assertSame(1, $this->collector->collections);
    }

    public function test_foreign_organization_collector_record_does_not_publish_coverage(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $foreign = Organization::factory()->create();
        $this->collector->chunks[] = new RagChunkData($foreign->id, $visible->id, 'project', 'project', $visible->id,
            'Другой проект', 'Другой проект');
        $this->coverage->refreshCoverage($organization->id);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertFalse($status['eligible_count_known']);
        $this->assertNull($status['expected_source_count']);
        $this->assertSame(0, RagExpectedSource::query()->where('organization_id', $organization->id)->count());
    }

    public function test_previous_complete_cache_without_projection_remains_compatible(): void
    {
        [$organization, $actor, $visible] = $this->scope();
        $this->index($organization->id, $visible);
        $this->coverage->refreshCoverage($organization->id);
        $key = 'ai-rag-coverage:'.$organization->id.':0:*:'.(int) Cache::get('ai-rag-coverage-revision:'.$organization->id, 0);
        $snapshot = Cache::get($key);
        unset($snapshot['projection_generation']);
        Cache::put($key, $snapshot, 300);
        $status = $this->coverage->coverageForActor($organization->id, $actor);
        $this->assertTrue($status['coverage_complete']);
        $this->assertSame(1, $status['expected_source_count']);
        $this->assertSame(0, $status['pending_source_count']);
    }
    private function scope(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        return [$organization, $actor, $project];
    }

    private function index(int $organizationId, Project $project): void
    {
        $chunk = new RagChunkData($organizationId, $project->id, 'project', 'project', $project->id, 'Проект', 'Проект '.$project->id, [], $project->updated_at);
        $this->collector->chunks[] = $chunk;
        $this->indexer->indexChunk($chunk);
    }
}

final class ActorCoverageCollector implements RagSourceCollectorInterface
{
    public array $chunks = [];
    public int $collections = 0;
    public bool $failure = false;
    public mixed $afterCollection = null;
    public mixed $afterChunk = null;
    public int $yielded = 0;
    public function sourceType(): string { return 'project'; }
    public function enabled(): bool { return true; }
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable { return []; }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $this->collections++;
        foreach ($this->chunks as $chunk) {
            $this->yielded++;
            yield $chunk;
            if (is_callable($this->afterChunk)) {
                ($this->afterChunk)($this->yielded);
            }
        }
        if (is_callable($this->afterCollection)) {
            ($this->afterCollection)();
        }
        if ($this->failure) {
            throw new \RuntimeException('collector_failure');
        }
    }
}

final class ActorCoverageEmbedding implements RagEmbeddingProviderInterface
{
    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array { return RagTestEmbedding::fromLeadingValues([0.1, 0.2]); }
    public function provider(): string { return 'fake'; }
    public function model(): string { return 'fake'; }
    public function dimensions(): int { return RagTestEmbedding::DIMENSIONS; }
}
