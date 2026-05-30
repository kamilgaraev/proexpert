<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingUnavailableException;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AIAssistantRagBackfillCommandTest extends TestCase
{
    public function test_sync_backfill_calls_indexer_with_requested_scope(): void
    {
        $organization = Organization::factory()->create(['id' => 10]);
        Project::factory()->create(['id' => 20, 'organization_id' => $organization->id]);
        $indexer = new BackfillCommandRecordingRagIndexer(7);
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 10,
            '--project_id' => 20,
            '--source_type' => 'project',
            '--sync' => true,
        ])
            ->expectsOutput('Indexed RAG chunks: 7')
            ->expectsOutput('RAG index run: 1')
            ->assertExitCode(0);

        $this->assertSame([[10, 20, 'project']], $indexer->calls);
    }

    public function test_sync_backfill_accepts_expanded_source_type(): void
    {
        $organization = Organization::factory()->create(['id' => 12]);
        Project::factory()->create(['id' => 24, 'organization_id' => $organization->id]);
        $indexer = new BackfillCommandRecordingRagIndexer(5);
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 12,
            '--project_id' => 24,
            '--source_type' => 'estimate',
            '--sync' => true,
        ])
            ->expectsOutput('Indexed RAG chunks: 5')
            ->expectsOutput('RAG index run: 1')
            ->assertExitCode(0);

        $this->assertSame([[12, 24, 'estimate']], $indexer->calls);
    }

    public function test_backfill_rejects_unknown_source_type(): void
    {
        $organization = Organization::factory()->create(['id' => 13]);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => $organization->id,
            '--source_type' => 'missing_source',
            '--sync' => true,
        ])
            ->expectsOutput('Unknown or disabled RAG source type: missing_source.')
            ->assertExitCode(1);
    }

    public function test_async_backfill_dispatches_index_job_with_requested_scope(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create(['id' => 11]);
        Project::factory()->create(['id' => 22, 'organization_id' => $organization->id]);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 11,
            '--project_id' => 22,
            '--source_type' => 'schedule',
        ])
            ->expectsOutput('Queued RAG indexing job.')
            ->expectsOutput('RAG index run: 1')
            ->assertExitCode(0);

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === 11
                && $job->projectId === 22
                && $job->sourceType === 'schedule'
                && $job->runId === 1
                && $job->queue === 'ai-rag'
        );

        $this->assertDatabaseHas('ai_rag_index_runs', [
            'id' => 1,
            'organization_id' => 11,
            'project_id' => 22,
            'source_type' => 'schedule',
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_ASYNC,
        ]);
    }

    public function test_backfill_requires_single_organization_or_all_flag(): void
    {
        $this->artisan('ai-assistant:rag-backfill')
            ->expectsOutput('Provide organization_id or use --all.')
            ->assertExitCode(1);
    }

    public function test_all_backfill_queues_one_job_per_active_organization(): void
    {
        Queue::fake();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        Organization::factory()->inactive()->create();

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 2')
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, 2);
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => in_array($job->organizationId, [$first->id, $second->id], true)
                && $job->runId !== null
        );
        $this->assertDatabaseCount('ai_rag_index_runs', 2);
    }

    public function test_all_backfill_can_include_inactive_organizations(): void
    {
        Queue::fake();
        Organization::factory()->create();
        $inactive = Organization::factory()->inactive()->create();

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--include-inactive' => true,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 2')
            ->assertExitCode(0);

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $inactive->id
        );
    }

    public function test_all_backfill_limit_caps_queued_organizations(): void
    {
        Queue::fake();
        Organization::factory()->count(3)->create();

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--limit' => 2,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 2')
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, 2);
        $this->assertDatabaseCount('ai_rag_index_runs', 2);
    }

    public function test_stale_all_backfill_queues_only_unindexed_and_expired_organizations(): void
    {
        Queue::fake();
        $unindexed = Organization::factory()->create();
        $stale = Organization::factory()->create();
        $fresh = Organization::factory()->create();
        $active = Organization::factory()->create();
        $inactive = Organization::factory()->inactive()->create();

        $this->createIndexRun($stale, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(30));
        $this->createIndexRun($fresh, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(2));
        $this->createIndexRun($active, RagIndexRun::STATUS_RUNNING);
        $this->createIndexRun($inactive, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(30));

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--stale' => true,
            '--limit' => 10,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 2')
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, 2);
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $unindexed->id
        );
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $stale->id
        );
        Queue::assertNotPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => in_array(
                $job->organizationId,
                [$fresh->id, $active->id, $inactive->id],
                true
            )
        );
        $this->assertDatabaseHas('ai_rag_index_runs', [
            'organization_id' => $unindexed->id,
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_SCHEDULED,
        ]);
    }

    public function test_stale_all_backfill_prefers_organizations_without_recent_attempts_when_limited(): void
    {
        Queue::fake();
        $recentlyAttempted = Organization::factory()->create();
        $neverAttempted = Organization::factory()->create();

        $this->createIndexRun($recentlyAttempted, RagIndexRun::STATUS_FAILED, now()->subHour());

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--stale' => true,
            '--limit' => 1,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 1')
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, 1);
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $neverAttempted->id
        );
    }

    public function test_stale_all_backfill_uses_custom_freshness_window(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();

        $this->createIndexRun($organization, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(13));

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--stale' => true,
            '--stale-after-hours' => 12,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 1')
            ->assertExitCode(0);

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $organization->id
        );
    }

    public function test_scheduled_backfill_command_is_stale_only(): void
    {
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));

        $this->assertIsString($consoleRoutes);
        $this->assertStringContainsString("'ai-assistant:rag-backfill',", $consoleRoutes);
        $this->assertStringContainsString("'--all',", $consoleRoutes);
        $this->assertStringContainsString("'--stale',", $consoleRoutes);
    }

    public function test_sync_all_requires_force(): void
    {
        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--sync' => true,
        ])
            ->expectsOutput('Synchronous --all indexing requires --force.')
            ->assertExitCode(1);
    }

    public function test_sync_all_with_force_indexes_active_organizations_without_queueing_jobs(): void
    {
        Queue::fake();
        Organization::factory()->count(2)->create();
        Organization::factory()->inactive()->create();
        $indexer = new BackfillCommandRecordingRagIndexer(3);
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--sync' => true,
            '--force' => true,
        ])
            ->expectsOutput('Synchronously indexed organizations: 2')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertCount(2, $indexer->calls);
        $this->assertDatabaseCount('ai_rag_index_runs', 2);
        $this->assertDatabaseMissing('ai_rag_index_runs', [
            'status' => RagIndexRun::STATUS_QUEUED,
        ]);
    }

    public function test_sync_backfill_records_succeeded_run_with_scope_counts(): void
    {
        $organization = Organization::factory()->create();
        $indexer = new BackfillCommandRecordingRagIndexer(7, function () use ($organization): void {
            $source = RagSource::query()->create([
                'organization_id' => $organization->id,
                'project_id' => null,
                'source_type' => 'project',
                'entity_type' => 'project',
                'entity_id' => '100',
                'title' => 'Project source',
                'checksum' => str_repeat('a', 64),
                'metadata' => [],
                'indexed_at' => now(),
            ]);

            RagChunk::query()->create([
                'source_id' => $source->id,
                'organization_id' => $organization->id,
                'project_id' => null,
                'chunk_index' => 0,
                'content' => 'Indexed content',
                'content_hash' => str_repeat('b', 64),
                'metadata' => [],
                'embedding_provider' => 'test',
                'embedding_model' => 'test',
                'embedding_created_at' => now(),
            ]);
        });
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => $organization->id,
            '--sync' => true,
        ])
            ->expectsOutput('Indexed RAG chunks: 7')
            ->expectsOutput('RAG index run: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('ai_rag_index_runs', [
            'id' => 1,
            'organization_id' => $organization->id,
            'status' => RagIndexRun::STATUS_SUCCEEDED,
            'mode' => RagIndexRun::MODE_SYNC,
            'indexed_chunks' => 7,
            'source_count' => 1,
            'chunk_count' => 1,
        ]);
    }

    public function test_sync_backfill_handles_unavailable_embedding_provider_without_throwing(): void
    {
        $organization = Organization::factory()->create();
        $indexer = new BackfillCommandRecordingRagIndexer(
            0,
            static fn (): never => throw new RagEmbeddingUnavailableException('Сервис подготовки контекста временно недоступен.')
        );
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => $organization->id,
            '--sync' => true,
        ])
            ->expectsOutput('Сервис подготовки контекста временно недоступен.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('ai_rag_index_runs', [
            'organization_id' => $organization->id,
            'status' => RagIndexRun::STATUS_FAILED,
            'mode' => RagIndexRun::MODE_SYNC,
        ]);
    }

    private function createIndexRun(
        Organization $organization,
        string $status,
        ?Carbon $finishedAt = null
    ): RagIndexRun
    {
        $startedAt = $finishedAt instanceof Carbon
            ? $finishedAt->copy()->subMinute()
            : now()->subMinute();

        return RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'project_id' => null,
            'source_type' => null,
            'status' => $status,
            'mode' => RagIndexRun::MODE_SCHEDULED,
            'queued_at' => $startedAt,
            'started_at' => in_array($status, [
                RagIndexRun::STATUS_RUNNING,
                RagIndexRun::STATUS_SUCCEEDED,
                RagIndexRun::STATUS_FAILED,
            ], true) ? $startedAt : null,
            'finished_at' => $finishedAt,
            'indexed_chunks' => $status === RagIndexRun::STATUS_SUCCEEDED ? 1 : 0,
        ]);
    }
}

final class BackfillCommandRecordingRagIndexer extends RagIndexer
{
    /**
     * @var array<int, array{0: int, 1: int|null, 2: string|null}>
     */
    public array $calls = [];

    public function __construct(
        private readonly int $indexedCount,
        private readonly mixed $afterIndex = null
    ) {
    }

    public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null): int
    {
        $this->calls[] = [$organizationId, $projectId, $sourceType];

        if (is_callable($this->afterIndex)) {
            ($this->afterIndex)();
        }

        return $this->indexedCount;
    }
}
