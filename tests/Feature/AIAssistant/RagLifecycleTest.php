<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshRagCoverageJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagDispatchIntent;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateRagSource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class RagLifecycleTest extends TestCase
{
    public function test_redis_and_cache_outage_after_commit_preserves_pending_run_and_recovers_without_duplicating_active_lease(): void
    {
        Queue::fake();
        RagIndexRun::query()->whereIn('status', [RagIndexRun::STATUS_QUEUED, RagIndexRun::STATUS_RUNNING])->delete();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $bus = $this->createMock(Dispatcher::class);
        $logger = $this->createMock(LoggerInterface::class);
        $attempts = 0;
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('Redis unavailable');
            }
            return 'delivered';
        });
        $logger->expects($this->once())->method('warning');
        Cache::partialMock()->shouldReceive('add')->andThrow(new \RuntimeException('Cache unavailable'));
        $coordinator = new RagIndexingCoordinator(new RagIndexer(new LifecycleEmbedding(), new RagSourceRegistry([])), new RagJobDispatcher($bus, $logger));
        DB::beginTransaction();
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', 42);
        $this->assertSame(0, $attempts);
        DB::commit();
        $this->assertSame(1, $attempts);
        $this->assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertSame(\RuntimeException::class, RagDispatchIntent::publicError($run->fresh()->last_error));
        $this->assertTrue(RagDispatchIntent::isPending($run->fresh()->last_error));
        $this->travel(2)->minutes();
        $this->assertSame(1, $coordinator->recoverExpiredRuns());
        $this->assertSame(2, $attempts);
        $this->assertSame(1, RagIndexRun::query()->where('organization_id', $organization->id)->count());
        $this->assertNull($run->fresh()->last_error);
        $this->assertNotNull($coordinator->markRunning($run->id));
        $this->travel(4)->minutes();
        $this->assertSame(0, $coordinator->recoverExpiredRuns());
    }

    public function test_coverage_request_queues_bounded_background_reconciliation_without_collecting(): void
    {
        Queue::fake();
        Cache::flush();
        $organization = Organization::factory()->create();
        $collector = new LifecycleCollector();
        $collector->chunks = [$this->chunk($organization->id, 1)];
        $registry = new RagSourceRegistry([$collector]);
        $coverage = new RagCoverageService($registry, new RagIndexer(new LifecycleEmbedding(), $registry));
        $unknown = $coverage->coverage($organization->id);
        $coverage->coverage($organization->id);
        $this->assertSame(0, $collector->collections);
        $this->assertFalse($unknown['eligible_count_known']);
        $this->assertNull($unknown['expected_source_count']);
        $this->assertTrue($unknown['processing']);
        Queue::assertPushed(RefreshRagCoverageJob::class, 1);
        $coverage->refreshCoverage($organization->id);
        $known = $coverage->coverage($organization->id);
        $this->assertSame(1, $collector->collections);
        $this->assertSame(1, $known['expected_source_count']);
        RagCoverageService::invalidate($organization->id);
        $this->assertFalse($coverage->coverage($organization->id)['eligible_count_known']);
        $this->assertSame(1, $collector->collections);
    }

    public function test_root_estimate_without_parent_indexes_every_item_resource_and_section(): void
    {
        Queue::fake();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id]));
        $estimate = Estimate::withoutEvents(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'parent_estimate_id' => null, 'number' => 'ROOT-001', 'name' => 'Корневая смета', 'type' => 'local', 'status' => 'draft', 'estimate_date' => now()->toDateString()]));
        $this->assertNull($estimate->parent_estimate_id);
        $section = EstimateSection::withoutEvents(fn () => EstimateSection::query()->create(['estimate_id' => $estimate->id, 'section_number' => '1', 'name' => 'Раздел']));
        $lastItem = null;
        for ($position = 1; $position <= 61; $position++) {
            $lastItem = EstimateItem::withoutEvents(fn () => EstimateItem::query()->create(['estimate_id' => $estimate->id, 'estimate_section_id' => $section->id, 'position_number' => (string) $position, 'name' => 'Позиция '.$position, 'item_type' => 'work', 'quantity' => 1, 'total_amount' => $position]));
            EstimateItemResource::withoutEvents(fn () => EstimateItemResource::query()->create(['estimate_item_id' => $lastItem->id, 'name' => 'Ресурс '.$position, 'resource_type' => 'material', 'total_quantity' => 1]));
        }
        $registry = new RagSourceRegistry([new EstimateRagSource()]);
        $indexer = new RagIndexer(new LifecycleEmbedding(), $registry);
        $this->assertSame(124, $indexer->indexOrganization($organization->id, null, 'estimate'));
        $this->assertSame(1, RagSource::query()->where('entity_type', 'estimate')->count());
        $this->assertSame(61, RagSource::query()->where('entity_type', 'estimate_item')->count());
        $this->assertSame(61, RagSource::query()->where('entity_type', 'estimate_item_resource')->count());
        $this->assertSame(1, RagSource::query()->where('entity_type', 'estimate_section')->count());
        $this->assertSame(124, RagSource::query()->where('organization_id', $organization->id)->where('project_id', $project->id)->count());
        $this->assertSame(124, (new RagCoverageService($registry, $indexer))->refreshCoverage($organization->id)['expected_source_count']);
        $lastItem->updateQuietly(['name' => 'Новая позиция']);
        $indexer->indexEntity($organization->id, 'estimate', 'estimate_item', $lastItem->id);
        $this->assertStringContainsString('Новая позиция', RagSource::query()->where('entity_type', 'estimate_item')->where('entity_id', (string) $lastItem->id)->firstOrFail()->chunks()->firstOrFail()->content);
        $lastItem->deleteQuietly();
        $indexer->indexEntity($organization->id, 'estimate', 'estimate_item', $lastItem->id);
        $this->assertSame(60, RagSource::query()->where('entity_type', 'estimate_item')->count());
        $this->assertSame(60, RagSource::query()->where('entity_type', 'estimate_item_resource')->count());
    }

    public function test_failed_partial_scan_preserves_unseen_sources_and_successful_scan_prunes_them(): void
    {
        $organization = Organization::factory()->create();
        $collector = new LifecycleCollector();
        $registry = new RagSourceRegistry([$collector]);
        $indexer = new RagIndexer(new LifecycleEmbedding(), $registry);
        $first = $this->chunk($organization->id, 1);
        $second = $this->chunk($organization->id, 2);
        $collector->chunks = [$first, $second];
        $indexer->indexOrganization($organization->id);
        $this->travel(1)->seconds();
        $collector->chunks = [$first];
        $collector->failAfter = true;
        try {
            $indexer->indexOrganization($organization->id);
            $this->fail('Expected interrupted scan');
        } catch (\RuntimeException $exception) {
            $this->assertSame('interrupted', $exception->getMessage());
        }
        $this->assertSame(2, RagSource::query()->where('organization_id', $organization->id)->count());
        $collector->failAfter = false;
        $indexer->indexOrganization($organization->id);
        $this->assertSame(1, RagSource::query()->where('organization_id', $organization->id)->count());
        $this->assertNotNull(RagSource::query()->firstOrFail()->last_reconciled_at);
    }

    public function test_pruning_loads_ids_and_preserves_a_source_refreshed_after_candidate_selection(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $collector = new LifecycleCollector();
        $registry = new RagSourceRegistry([$collector]);
        $indexer = new RagIndexer(new LifecycleEmbedding(), $registry);
        $indexer->indexChunk($this->chunk($organization->id, 1));
        $indexer->indexChunk($this->chunk($organization->id, 2));
        $indexer->indexChunk($this->chunk($otherOrganization->id, 1));
        $refreshed = RagSource::query()->where('organization_id', $organization->id)->where('entity_id', '1')->firstOrFail();
        $selected = false;
        $candidateQueries = [];
        DB::listen(static function ($event) use (&$selected, &$candidateQueries): void {
            if (str_contains($event->sql, 'from "ai_rag_sources"') && str_contains($event->sql, 'limit 100')) {
                $candidateQueries[] = $event->sql;
                $selected = true;
            }
        });
        $indexer->indexOrganization($organization->id, null, 'estimate', static function () use (&$selected, $refreshed): void {
            if ($selected) {
                $selected = false;
                RagSource::query()->whereKey($refreshed->id)->update(['last_reconciled_at' => now()->addSecond()]);
            }
        });
        self::assertCount(1, $candidateQueries);
        self::assertStringStartsWith('select "id" from "ai_rag_sources"', $candidateQueries[0]);
        self::assertSame(1, RagSource::query()->where('organization_id', $organization->id)->count());
        self::assertSame(1, $refreshed->fresh()->chunks()->count());
        self::assertSame(1, RagSource::query()->where('organization_id', $otherOrganization->id)->count());
    }

    public function test_expired_lease_requeues_entity_and_old_worker_cannot_heartbeat_or_complete(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $coordinator = new RagIndexingCoordinator(new RagIndexer(new LifecycleEmbedding(), new RagSourceRegistry([])));
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', 42);
        $claimed = $coordinator->markRunning($run->id);
        $this->assertNotNull($claimed);
        $oldToken = $claimed->lease_token;
        $claimed->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertSame(1, $coordinator->recoverExpiredRuns());
        Queue::assertPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->runId === $run->id && $job->entityType === 'estimate_item' && (string) $job->entityId === '42');
        $newClaim = $coordinator->markRunning($run->id);
        $this->assertNotSame($oldToken, $newClaim->lease_token);
        $this->assertFalse($coordinator->heartbeat($run->id, $oldToken));
        $this->assertNull($coordinator->markSucceeded($run->id, 1, $oldToken));
        $this->assertSame(RagIndexRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_after_commit_indexing_is_not_dispatched_for_rolled_back_estimate(): void
    {
        Queue::fake();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id]));
        DB::beginTransaction();
        Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'ROLLBACK', 'name' => 'Rollback', 'estimate_date' => now()->toDateString()]);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertSame(0, RagIndexRun::query()->where('organization_id', $organization->id)->count());
        $estimate = DB::transaction(fn () => Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'number' => 'COMMIT', 'name' => 'Commit', 'estimate_date' => now()->toDateString()]));
        Queue::assertPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->entityType === 'estimate'
            && $job->organizationId === $organization->id && $job->projectId === $project->id && (string) $job->entityId === (string) $estimate->id);
    }

    public function test_legacy_runs_without_queue_timestamp_recover_once_and_preserve_fresh_activity(): void
    {
        Queue::fake();
        config(['ai-assistant.rag.lease_minutes' => 15, 'ai-assistant.rag.queued_retry_minutes' => 2]);
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $old = now()->subMinutes(16);
        $createRun = function (string $status, array $timestamps = []) use ($organization, $old): RagIndexRun {
            $id = DB::table('ai_rag_index_runs')->insertGetId([
                'organization_id' => $organization->id, 'source_type' => 'estimate',
                'status' => $status, 'mode' => RagIndexRun::MODE_ASYNC,
                'queued_at' => null, 'started_at' => null, 'heartbeat_at' => null,
                'lease_expires_at' => null, 'lease_token' => null,
                'created_at' => $old, 'updated_at' => null, ...$timestamps,
            ]);
            return RagIndexRun::query()->findOrFail($id);
        };
        $queued = $createRun(RagIndexRun::STATUS_QUEUED);
        $running = $createRun(RagIndexRun::STATUS_RUNNING, ['started_at' => $old, 'heartbeat_at' => $old, 'updated_at' => $old]);
        $freshQueued = $createRun(RagIndexRun::STATUS_QUEUED, ['updated_at' => now()]);
        $freshHeartbeat = $createRun(RagIndexRun::STATUS_RUNNING, ['heartbeat_at' => now(), 'updated_at' => $old]);
        $freshUpdate = $createRun(RagIndexRun::STATUS_RUNNING, ['queued_at' => $old, 'heartbeat_at' => $old, 'updated_at' => now()]);
        $freshStart = $createRun(RagIndexRun::STATUS_RUNNING, ['started_at' => now(), 'updated_at' => $old]);
        $oldToken = (string) \Illuminate\Support\Str::uuid();
        $expired = $createRun(RagIndexRun::STATUS_RUNNING, ['heartbeat_at' => now(), 'updated_at' => now(),
            'lease_expires_at' => now()->subSecond(), 'lease_token' => $oldToken]);
        $coordinator = new RagIndexingCoordinator(new RagIndexer(new LifecycleEmbedding(), new RagSourceRegistry([])));

        $this->assertSame(3, $coordinator->recoverExpiredRuns());
        $this->assertSame(0, $coordinator->recoverExpiredRuns());
        Queue::assertPushed(IndexRagSourceJob::class, 3);
        foreach ([$queued, $running, $expired] as $run) {
            $this->assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
            $this->assertNotNull($run->fresh()->queued_at);
            Queue::assertPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->runId === $run->id
                && $job->organizationId === $organization->id && $job->sourceType === 'estimate');
        }
        foreach ([$freshQueued, $freshHeartbeat, $freshUpdate, $freshStart] as $run) {
            $this->assertSame($run->status, $run->fresh()->status);
            Queue::assertNotPushed(IndexRagSourceJob::class, fn (IndexRagSourceJob $job): bool => $job->runId === $run->id);
        }
        $claimed = $coordinator->markRunning($expired->id);
        $this->assertNotNull($claimed);
        $this->assertNotSame($oldToken, $claimed->lease_token);
        $this->assertFalse($coordinator->heartbeat($expired->id, $oldToken));
        $this->assertNull($coordinator->markSucceeded($expired->id, 1, $oldToken));
    }

    public function test_missing_source_older_than_five_minutes_exposes_lag_and_expected_coverage(): void
    {
        $organization = Organization::factory()->create();
        $collector = new LifecycleCollector();
        $collector->chunks = [$this->chunk($organization->id, 1, now()->subMinutes(6))];
        $registry = new RagSourceRegistry([$collector]);
        $indexer = new RagIndexer(new LifecycleEmbedding(), $registry);
        $coverage = (new RagCoverageService($registry, $indexer))->refreshCoverage($organization->id);
        $this->assertSame(1, $coverage['expected_source_count']);
        $this->assertSame(0, $coverage['indexed_source_count']);
        $this->assertFalse($coverage['coverage_complete']);
        $this->assertTrue($coverage['lag_exceeded']);
        $indexer->indexOrganization($organization->id);
        $this->assertTrue((new RagCoverageService($registry, $indexer))->refreshCoverage($organization->id)['coverage_complete']);
    }

    public function test_document_units_have_independent_identity_for_same_document(): void
    {
        $organization = Organization::factory()->create();
        $indexer = new RagIndexer(new LifecycleEmbedding(), new RagSourceRegistry([]));
        foreach ([11, 12] as $unit) {
            $indexer->indexChunk(new RagChunkData($organization->id, null, 'file_document', 'assistant_document', 1, 'Документ', 'Страница '.$unit, ['unit_id' => $unit], now()));
        }
        $this->assertSame(2, RagSource::query()->where('entity_id', '1')->count());
        $this->assertSame(['11', '12'], RagSource::query()->orderBy('identity_part_key')->pluck('identity_part_key')->all());
    }

    private function chunk(int $organizationId, int $id, ?\DateTimeInterface $updated = null): RagChunkData
    {
        return new RagChunkData($organizationId, null, 'estimate', 'estimate', $id, 'Смета '.$id, 'Смета '.$id, [], $updated ?? now());
    }
}

final class LifecycleCollector implements RagSourceCollectorInterface
{
    public array $chunks = [];
    public bool $failAfter = false;
    public int $collections = 0;
    public function sourceType(): string { return 'estimate'; }
    public function enabled(): bool { return true; }
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable { return []; }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $this->collections++;
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
        if ($this->failAfter) {
            throw new \RuntimeException('interrupted');
        }
    }
}

final class LifecycleEmbedding implements RagEmbeddingProviderInterface
{
    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array { return RagTestEmbedding::fromLeadingValues([0.1, 0.2]); }
    public function provider(): string { return 'fake'; }
    public function model(): string { return 'fake'; }
    public function dimensions(): int { return RagTestEmbedding::DIMENSIONS; }
}
