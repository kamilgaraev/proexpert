<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshRagCoverageJob;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
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
use Mockery;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class RagActorCoverageTest extends TestCase
{
    private RagCoverageService $coverage;
    private RagIndexer $indexer;
    private ActorCoverageCollector $collector;
    private bool $permissions = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $actor, string $permission): bool => $this->permissions && in_array($permission, ['projects.view', 'finance.view'], true));
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'payments']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService(), $modules);
        $this->collector = new ActorCoverageCollector();
        $registry = new RagSourceRegistry([$this->collector]);
        $this->indexer = new RagIndexer(new ActorCoverageEmbedding(), $registry);
        $this->coverage = new RagCoverageService($registry, $this->indexer, $policy);
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
    public function sourceType(): string { return 'project'; }
    public function enabled(): bool { return true; }
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable { return []; }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $this->collections++;
        yield from $this->chunks;
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
