<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingUnavailableException;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AIAssistantRagBackfillCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-assistant.rag.scheduled_project_scoped_source_types' => []]);
    }

    public function test_sync_backfill_calls_indexer_with_requested_scope(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create(['id' => 10]));
        Project::withoutEvents(fn () => Project::factory()->create(['id' => 20, 'organization_id' => $organization->id]));
        $indexer = new BackfillCommandRecordingRagIndexer(7);
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 10,
            '--project_id' => 20,
            '--source_type' => 'project',
            '--sync' => true,
        ])
            ->expectsOutput('Indexed RAG chunks: 7')
            ->expectsOutputToContain('RAG index run:')
            ->assertExitCode(0);

        $this->assertSame([[10, 20, 'project']], $indexer->calls);
        $run = RagIndexRun::query()->where('organization_id', 10)->where('project_id', 20)->where('source_type', 'project')->sole();
        $this->assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(7, $run->indexed_chunks);
    }

    public function test_sync_backfill_accepts_expanded_source_type(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create(['id' => 12]));
        Project::withoutEvents(fn () => Project::factory()->create(['id' => 24, 'organization_id' => $organization->id]));
        $indexer = new BackfillCommandRecordingRagIndexer(5);
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 12,
            '--project_id' => 24,
            '--source_type' => 'estimate',
            '--sync' => true,
        ])
            ->expectsOutput('Indexed RAG chunks: 5')
            ->expectsOutputToContain('RAG index run:')
            ->assertExitCode(0);

        $this->assertSame([[12, 24, 'estimate']], $indexer->calls);
        $run = RagIndexRun::query()->where('organization_id', 12)->where('project_id', 24)->where('source_type', 'estimate')->sole();
        $this->assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(5, $run->indexed_chunks);
    }

    public function test_backfill_rejects_unknown_source_type(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create(['id' => 13]));

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
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create(['id' => 11]));
        Project::withoutEvents(fn () => Project::factory()->create(['id' => 22, 'organization_id' => $organization->id]));

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 11,
            '--project_id' => 22,
            '--source_type' => 'schedule',
        ])
            ->expectsOutput('Queued RAG indexing job.')
            ->expectsOutputToContain('RAG index run:')
            ->assertExitCode(0);

        $run = RagIndexRun::query()->where('organization_id', 11)->where('project_id', 22)->where('source_type', 'schedule')->whereNull('entity_type')->sole();

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === 11
                && $job->projectId === 22
                && $job->sourceType === 'schedule'
                && $job->runId === $run->id
                && $job->queue === 'ai-rag'
        );

        $this->assertDatabaseHas('ai_rag_index_runs', [
            'id' => $run->id,
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

    public function test_all_backfill_without_limit_queues_only_bounded_batch(): void
    {
        Queue::fake();
        $first = Organization::withoutEvents(fn () => Organization::factory()->create());
        $second = Organization::withoutEvents(fn () => Organization::factory()->create());
        Organization::withoutEvents(fn () => Organization::factory()->inactive()->create());
        $sourceTypes = $this->enabledSourceTypes();
        $expectedJobs = 2;

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
        ])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, $expectedJobs);
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => in_array($job->organizationId, [$first->id, $second->id], true)
                && in_array($job->sourceType, $sourceTypes, true)
                && $job->runId !== null
        );
        Queue::assertNotPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->sourceType === null
        );
        $this->assertDatabaseCount('ai_rag_index_runs', $expectedJobs);
    }

    public function test_all_backfill_limit_caps_total_jobs_across_source_types(): void
    {
        Queue::fake();
        Organization::withoutEvents(fn () => Organization::factory()->count(3)->create());

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--limit' => 2,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 2')
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, 2);
        $this->assertDatabaseCount('ai_rag_index_runs', 2);

        $this->travel(3)->minutes();
        $this->assertSame(0, app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator::class)->recoverExpiredRuns());
        Queue::assertPushed(IndexRagSourceJob::class, 2);
    }

    public function test_old_queued_run_is_recovered_once_when_rag_queues_are_empty(): void
    {
        Queue::fake();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $run = RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'project',
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_SCHEDULED,
            'queued_at' => now()->subMinutes(3),
        ]);

        $coordinator = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator::class);
        $this->assertSame(1, $coordinator->recoverExpiredRuns());
        Queue::assertPushed(IndexRagSourceJob::class, 1);
        $this->assertSame(0, $coordinator->recoverExpiredRuns());
        $this->assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
    }

    public function test_forced_explicit_limit_allows_controlled_larger_batch(): void
    {
        Queue::fake();
        Organization::withoutEvents(fn () => Organization::factory()->count(3)->create());

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--limit' => 3,
            '--force' => true,
        ])
            ->expectsOutput('Queued RAG indexing jobs: 3')
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, 3);
    }

    public function test_all_backfill_includes_organizations_without_projects_even_with_legacy_split_config(): void
    {
        Queue::fake();
        config(['ai-assistant.rag.scheduled_project_scoped_source_types' => ['estimate']]);
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $expectedJobs = 1;
        $this->artisan('ai-assistant:rag-backfill', ['--all' => true, '--source_type' => 'estimate'])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")->assertExitCode(0);
        Queue::assertPushed(IndexRagSourceJob::class, $expectedJobs);
        Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->organizationId === $organization->id && $job->projectId === null && $job->sourceType === 'estimate');
    }

    public function test_scheduled_organization_run_finishes_after_actual_indexing(): void
    {
        Queue::fake();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $indexer = new BackfillCommandRecordingRagIndexer(7);
        $this->app->instance(RagIndexer::class, $indexer);
        $run = RagIndexRun::query()->create(['organization_id' => $organization->id, 'source_type' => 'estimate', 'status' => RagIndexRun::STATUS_QUEUED, 'mode' => RagIndexRun::MODE_SCHEDULED, 'queued_at' => now()]);
        (new IndexRagSourceJob($organization->id, null, 'estimate', $run->id))->handle($indexer, app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator::class));
        $this->assertSame([[$organization->id, null, 'estimate']], $indexer->calls);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('ai_rag_index_runs', ['id' => $run->id, 'status' => RagIndexRun::STATUS_SUCCEEDED, 'indexed_chunks' => 7, 'expected_sources' => 7]);
    }

    public function test_exhausted_job_is_failed_without_marking_unprocessed_scope_successful(): void
    {
        Queue::fake();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $run = RagIndexRun::query()->create(['organization_id' => $organization->id, 'source_type' => 'estimate', 'status' => RagIndexRun::STATUS_QUEUED, 'mode' => RagIndexRun::MODE_SCHEDULED, 'queued_at' => now()]);
        (new IndexRagSourceJob($organization->id, null, 'estimate', $run->id))->failed(new MaxAttemptsExceededException('RAG job exceeded attempts.'));
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('ai_rag_index_runs', ['id' => $run->id, 'status' => RagIndexRun::STATUS_FAILED, 'scan_completed_at' => null]);
    }
    public function test_manual_org_wide_project_scoped_job_is_indexed_without_split(): void
    {
        Queue::fake();
        config(['ai-assistant.rag.scheduled_project_scoped_source_types' => ['estimate']]);

        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        Project::withoutEvents(fn () => Project::factory()->count(2)->create(['organization_id' => $organization->id]));
        $indexer = new BackfillCommandRecordingRagIndexer(7);
        $this->app->instance(RagIndexer::class, $indexer);

        $run = RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'project_id' => null,
            'source_type' => 'estimate',
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_MANUAL,
            'queued_at' => now()->subMinutes(5),
        ]);

        (new IndexRagSourceJob($organization->id, null, 'estimate', $run->id))->handle(
            $indexer,
            app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator::class)
        );

        Queue::assertNothingPushed();
        $this->assertSame([[$organization->id, null, 'estimate']], $indexer->calls);
        $this->assertDatabaseHas('ai_rag_index_runs', [
            'id' => $run->id,
            'status' => RagIndexRun::STATUS_SUCCEEDED,
            'indexed_chunks' => 7,
            'mode' => RagIndexRun::MODE_MANUAL,
        ]);
    }

    public function test_all_backfill_can_include_inactive_organizations(): void
    {
        Queue::fake();
        Organization::withoutEvents(fn () => Organization::factory()->create());
        $inactive = Organization::withoutEvents(fn () => Organization::factory()->inactive()->create());
        $sourceTypes = $this->enabledSourceTypes();
        $expectedJobs = 2;

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--include-inactive' => true,
        ])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, $expectedJobs);
    }

    public function test_all_backfill_limit_caps_queued_organizations(): void
    {
        Queue::fake();
        Organization::withoutEvents(fn () => Organization::factory()->count(3)->create());
        $expectedJobs = 2;

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--limit' => 2,
        ])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, $expectedJobs);
        $this->assertDatabaseCount('ai_rag_index_runs', $expectedJobs);
    }

    public function test_stale_all_backfill_queues_only_unindexed_and_expired_organizations(): void
    {
        Queue::fake();
        $unindexed = Organization::withoutEvents(fn () => Organization::factory()->create());
        $stale = Organization::withoutEvents(fn () => Organization::factory()->create());
        $fresh = Organization::withoutEvents(fn () => Organization::factory()->create());
        $active = Organization::withoutEvents(fn () => Organization::factory()->create());
        $inactive = Organization::withoutEvents(fn () => Organization::factory()->inactive()->create());
        $sourceTypes = $this->enabledSourceTypes();
        $expectedJobs = 2;

        foreach ($sourceTypes as $sourceType) {
            $this->createIndexRun($stale, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(30), $sourceType);
            $this->createIndexRun($fresh, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(2), $sourceType);
            $this->createIndexRun($inactive, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(30), $sourceType);
        }
        $this->createIndexRun($active, RagIndexRun::STATUS_RUNNING);

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--stale' => true,
            '--limit' => 10,
        ])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, $expectedJobs);
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $unindexed->id
                && in_array($job->sourceType, $sourceTypes, true)
        );
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $stale->id
                && in_array($job->sourceType, $sourceTypes, true)
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
        $recentlyAttempted = Organization::withoutEvents(fn () => Organization::factory()->create());
        $neverAttempted = Organization::withoutEvents(fn () => Organization::factory()->create());
        $sourceTypes = $this->enabledSourceTypes();
        $expectedJobs = 1;

        $this->createIndexRun($recentlyAttempted, RagIndexRun::STATUS_FAILED, now()->subHour());

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--stale' => true,
            '--limit' => 1,
        ])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")
            ->assertExitCode(0);

        Queue::assertPushed(IndexRagSourceJob::class, $expectedJobs);
        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $neverAttempted->id
                && in_array($job->sourceType, $sourceTypes, true)
        );
    }

    public function test_stale_all_backfill_skips_recently_failed_source_scope(): void
    {
        Queue::fake();
        config(['ai-assistant.rag.failed_retry_after_hours' => 12]);

        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $sourceTypes = $this->enabledSourceTypes();

        foreach (array_diff($sourceTypes, ['estimate']) as $sourceType) {
            $this->createIndexRun($organization, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(2), $sourceType);
        }

        $this->createIndexRun($organization, RagIndexRun::STATUS_FAILED, now()->subMinutes(30), 'estimate');

        $this->assertDatabaseHas('ai_rag_index_runs', [
            'organization_id' => $organization->id,
            'source_type' => 'estimate',
            'status' => RagIndexRun::STATUS_FAILED,
        ]);

        $result = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator::class)
            ->queueAllActiveOrganizations(
                sourceType: 'estimate',
                mode: RagIndexRun::MODE_SCHEDULED,
                staleOnly: true
            );

        $this->assertNotContains($organization->id, $result['organization_ids']);
        Queue::assertNotPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $organization->id
                && $job->sourceType === 'estimate'
        );
    }

    public function test_stale_all_backfill_uses_custom_freshness_window(): void
    {
        Queue::fake();
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $sourceTypes = $this->enabledSourceTypes();
        $expectedJobs = 2;

        foreach ($sourceTypes as $sourceType) {
            $this->createIndexRun($organization, RagIndexRun::STATUS_SUCCEEDED, now()->subHours(13), $sourceType);
        }

        $this->artisan('ai-assistant:rag-backfill', [
            '--all' => true,
            '--stale' => true,
            '--stale-after-hours' => 12,
        ])
            ->expectsOutput("Queued RAG indexing jobs: {$expectedJobs}")
            ->assertExitCode(0);

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $organization->id
                && in_array($job->sourceType, $sourceTypes, true)
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
        Organization::withoutEvents(fn () => Organization::factory()->count(2)->create());
        Organization::withoutEvents(fn () => Organization::factory()->inactive()->create());
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
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
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
            ->expectsOutputToContain('RAG index run:')
            ->assertExitCode(0);

        $run = RagIndexRun::query()->where('organization_id', $organization->id)->where('mode', RagIndexRun::MODE_SYNC)->sole();

        $this->assertDatabaseHas('ai_rag_index_runs', [
            'id' => $run->id,
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
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
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
        ?Carbon $finishedAt = null,
        ?string $sourceType = null
    ): RagIndexRun {
        $startedAt = $finishedAt instanceof Carbon
            ? $finishedAt->copy()->subMinute()
            : now()->subMinute();

        return RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'project_id' => null,
            'source_type' => $sourceType,
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

    /**
     * @return array<int, string>
     */
    private function enabledSourceTypes(): array
    {
        return app(RagSourceRegistry::class)->enabledSourceTypes();
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
    ) {}

    public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null, ?callable $progress = null): int
    {
        $this->calls[] = [$organizationId, $projectId, $sourceType];

        if (is_callable($this->afterIndex)) {
            ($this->afterIndex)();
        }

        return $this->indexedCount;
    }
}
