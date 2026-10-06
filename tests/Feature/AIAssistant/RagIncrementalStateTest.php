<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingCheckpointStore;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class RagIncrementalStateTest extends TestCase
{
    public function test_revision_survives_cache_loss_and_rejects_a_stale_publication(): void
    {
        $organization = Organization::factory()->create();
        $store = new RagCoverageStateStore;
        $revision = $store->revision($organization->id);
        $generation = (string) Str::uuid();
        $store->publish($organization->id, $revision, ['projection_generation' => $generation]);
        $this->assertSame($revision + 1, $store->invalidate($organization->id));
        Cache::flush();
        $this->assertSame($revision + 1, $store->revision($organization->id));
        $this->assertNull($store->snapshot($organization->id, $revision + 1));
        try {
            $store->publish($organization->id, $revision, ['projection_generation' => (string) Str::uuid()]);
            $this->fail('Stale publication must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rag_coverage_projection_expired', $exception->getMessage());
        }
        $this->assertSame($generation, $store->activeGeneration($organization->id));
    }

    public function test_publication_does_not_lose_changes_committed_after_collection_started(): void
    {
        $organization = Organization::factory()->create();
        $store = new RagCoverageStateStore;
        $revision = $store->revision($organization->id);
        $store->markIndexChanged($organization->id);
        $collectedVersion = $store->indexVersion($organization->id);
        $store->markIndexChanged($organization->id);
        $store->publish($organization->id, $revision, ['projection_generation' => (string) Str::uuid()], $collectedVersion);

        $this->assertTrue($store->hasPendingIndexChanges($organization->id));
        $this->assertSame($revision + 1, $store->invalidate($organization->id));
        $this->assertFalse($store->hasPendingIndexChanges($organization->id));
    }

    public function test_scheduled_no_op_keeps_the_projection_revision_and_processed_counter(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $store = new RagCoverageStateStore;
        $revision = $store->revision($organization->id);
        $generation = (string) Str::uuid();
        $store->publish($organization->id, $revision, ['projection_generation' => $generation]);
        $coordinator = app(RagIndexingCoordinator::class);
        $run = $coordinator->queueOrganization($organization->id, sourceType: 'project', mode: RagIndexRun::MODE_SCHEDULED);
        $this->assertSame($revision, $store->revision($organization->id));
        $running = $coordinator->markRunning($run->id);
        $this->assertNotNull($running);
        $finished = $coordinator->markSucceeded($run->id, 1, $running->lease_token, coverageChanged: false);

        $this->assertSame($revision, $store->revision($organization->id));
        $this->assertSame($generation, $store->activeGeneration($organization->id));
        $this->assertSame(1, $finished->processed_sources);
    }

    public function test_checkpoints_are_scoped_by_organization_source_and_profile(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $store = new RagEmbeddingCheckpointStore;
        $hash = hash('sha256', 'content');
        $store->save($first->id, 'source', 'profile', $hash, '[1]');
        $store->save($second->id, 'source', 'profile', $hash, '[2]');

        $this->assertSame('[1]', $store->load($first->id, 'source', 'profile', [$hash], 1)[$hash]['vector']);
        $this->assertSame('[2]', $store->load($second->id, 'source', 'profile', [$hash], 1)[$hash]['vector']);
        $this->assertSame([], $store->load($first->id, 'another-source', 'profile', [$hash], 1));
        $this->assertSame([], $store->load($first->id, 'source', 'another-profile', [$hash], 1));
        $this->assertSame([], $store->load($first->id, 'source', 'profile', [$hash], 2));
        $store->discard($first->id, 'source');
        $this->assertSame(1, DB::table('ai_rag_embedding_checkpoints')->count());
    }

    public function test_expired_checkpoints_are_pruned_with_a_row_budget_and_tenant_scope(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $store = new RagEmbeddingCheckpointStore;
        $hash = hash('sha256', 'content');
        $store->save($first->id, 'source', 'profile', $hash, '[1]');
        $store->save($second->id, 'source', 'profile', $hash, '[2]');
        $this->travel(25)->hours();

        $this->assertSame([], $store->load($first->id, 'source', 'profile', [$hash], 1));
        $this->assertSame(1, $store->prune($first->id, 1, microtime(true) + 5));
        $this->assertSame(1, DB::table('ai_rag_embedding_checkpoints')->where('organization_id', $second->id)->count());
        $this->assertSame(1, $store->prune(null, 1, microtime(true) + 5));
        $this->assertSame(0, DB::table('ai_rag_embedding_checkpoints')->count());
    }
}
