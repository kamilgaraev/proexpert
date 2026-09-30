<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\PrepareAssistantNativeAttachmentsJob;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;
use Tests\TestCase;

final class AssistantNativeAttachmentPreparationRecoveryPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::clearResolvedInstances();
        Queue::fake();
    }

    public function test_minute_recovery_dispatches_existing_queued_org_runs_to_default_only_once(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id]));
        $legal = $this->createRun($organization, 'legal_business');
        $operations = $this->createRun($organization, 'operations_quality');
        $this->createRun($organization, 'estimate');
        $this->createRun($organization, 'legal_business', RagIndexRun::STATUS_RUNNING);
        $this->createRun($organization, 'operations_quality', RagIndexRun::STATUS_QUEUED, $project->id);
        $this->createRun($organization, 'legal_business', RagIndexRun::STATUS_QUEUED, null, 'legal_document');

        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        self::assertSame(2, $queue->dispatchPendingQueuedRuns());
        self::assertSame(0, $queue->dispatchPendingQueuedRuns());

        Queue::assertPushedOn('default', PrepareAssistantNativeAttachmentsJob::class);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 2);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, static fn (PrepareAssistantNativeAttachmentsJob $job): bool =>
            $job->runId === $legal->id && $job->sourceType === 'legal_business');
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, static fn (PrepareAssistantNativeAttachmentsJob $job): bool =>
            $job->runId === $operations->id && $job->sourceType === 'operations_quality');

        $legalJob = $this->pushedPreparationJobs()
            ->first(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $legal->id);
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $legalJob);
        $queue->markComplete($legal->id, $legalJob->dispatchToken);
        self::assertTrue($queue->isComplete($legal->id));
        self::assertFalse($queue->dispatchQueuedRun($legal));
        self::assertSame(0, $queue->dispatchPendingQueuedRuns());
        Queue::assertPushedOn('default', PrepareAssistantNativeAttachmentsJob::class);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 2);
    }

    public function test_recovery_cursor_reaches_runs_after_first_hundred_active_markers(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $runs = [];
        for ($index = 0; $index < 102; $index++) {
            $runs[] = $this->createRun($organization, 'operations_quality');
        }

        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        foreach (array_slice($runs, 0, 100) as $run) {
            self::assertTrue($queue->dispatchQueuedRun($run));
        }
        self::assertSame(0, $queue->dispatchPendingQueuedRuns());
        self::assertSame(2, $queue->dispatchPendingQueuedRuns());

        Queue::assertPushedOn('default', PrepareAssistantNativeAttachmentsJob::class);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 102);
        $laterJobs = $this->pushedPreparationJobs(static fn (PrepareAssistantNativeAttachmentsJob $job): bool =>
            $job->runId === $runs[100]->id || $job->runId === $runs[101]->id);
        self::assertEqualsCanonicalizing([$runs[100]->id, $runs[101]->id], $laterJobs->pluck('runId')->all());
    }

    public function test_index_job_dispatches_before_heavy_indexing_and_uses_inline_only_when_marker_is_gone(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $run = $this->createRun($organization, 'legal_business');
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        $preparationState = (object) ['calls' => 0];
        $this->app->instance(AssistantLegalNativeFileIndexer::class, new class($preparationState)
        {
            public function __construct(private object $state) {}

            public function prepare(mixed ...$arguments): void
            {
                $this->state->calls++;
            }
        });

        $indexer = $this->createMock(RagIndexer::class);
        $indexer->expects(self::once())->method('indexOrganization')->willReturnCallback(
            function (int $organizationId, ?int $projectId, ?string $sourceType) use ($queue, $run): int {
                self::assertSame('legal_business', $sourceType);
                self::assertTrue($queue->isDispatchedOrComplete((int) $run->id));
                self::assertFalse($queue->shouldPrepareInline((int) $run->id));

                return 0;
            },
        );

        (new IndexRagSourceJob((int) $organization->id, null, 'legal_business', (int) $run->id))
            ->handle($indexer, app(RagIndexingCoordinator::class));

        self::assertSame(0, $preparationState->calls);
        self::assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
        Queue::assertPushedOn('default', PrepareAssistantNativeAttachmentsJob::class);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, static fn (PrepareAssistantNativeAttachmentsJob $job): bool =>
            $job->runId === $run->id && $job->sourceType === 'legal_business');

        $prepareJob = $this->pushedPreparationJobs()->first();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $prepareJob);
        $prepareJob->handle(
            (new \ReflectionClass(AssistantLegalNativeFileIndexer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AssistantOperationsNativeFileIndexer::class))->newInstanceWithoutConstructor(),
            $queue,
        );
        self::assertTrue($queue->isComplete((int) $run->id));

        $fallbackRun = $this->createRun($organization, 'legal_business');
        self::assertTrue($queue->dispatchQueuedRun($fallbackRun));

        $this->travel(11)->minutes();
        self::assertFalse($queue->shouldPrepareInline((int) $run->id));
        self::assertTrue($queue->shouldPrepareInline((int) $fallbackRun->id));
    }

    public function test_delayed_preparation_accepts_failed_index_run_and_stops_for_cancelled_run(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        $legal = (new \ReflectionClass(AssistantLegalNativeFileIndexer::class))->newInstanceWithoutConstructor();
        $operations = (new \ReflectionClass(AssistantOperationsNativeFileIndexer::class))->newInstanceWithoutConstructor();

        foreach ([RagIndexRun::STATUS_FAILED, 'cancelled'] as $status) {
            $run = $this->createRun($organization, 'legal_business');
            self::assertTrue($queue->dispatchQueuedRun($run));
            $run->update(['status' => $status]);
            $job = $this->pushedPreparationJobs()->first(
                static fn (PrepareAssistantNativeAttachmentsJob $candidate): bool => $candidate->runId === $run->id,
            );
            self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $job);
            $job->handle($legal, $operations, $queue);

            if ($status === RagIndexRun::STATUS_FAILED) {
                self::assertTrue($queue->isComplete((int) $run->id));
            } else {
                self::assertFalse($queue->isComplete((int) $run->id));
                self::assertFalse($queue->hasDispatchToken((int) $run->id, $job->dispatchToken));
            }
        }
    }

    public function test_final_preparation_failure_schedules_only_bounded_retry_cycles(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $run = $this->createRun($organization, 'legal_business');
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        self::assertTrue($queue->dispatchQueuedRun($run));
        $run->update(['status' => RagIndexRun::STATUS_FAILED]);

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $job = $this->pushedPreparationJobs()->last();
            self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $job);
            self::assertSame($cycle, $job->dispatchCycle);
            $job->failed(new \RuntimeException('temporary preparation failure'));
        }

        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 3);
        self::assertFalse($queue->dispatchRetryRun((int) $run->id, (int) $organization->id, 'legal_business'));
        self::assertFalse($queue->isDispatchedOrComplete((int) $run->id));
    }

    private function createRun(
        Organization $organization,
        string $sourceType,
        string $status = RagIndexRun::STATUS_QUEUED,
        ?int $projectId = null,
        ?string $entityType = null,
    ): RagIndexRun {
        return RagIndexRun::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $projectId,
            'source_type' => $sourceType,
            'entity_type' => $entityType,
            'status' => $status,
            'mode' => RagIndexRun::MODE_ASYNC,
            'queued_at' => now(),
        ]);
    }

    private function pushedPreparationJobs(?callable $callback = null): \Illuminate\Support\Collection
    {
        $queue = Queue::getFacadeRoot();
        if (! $queue instanceof QueueFake) {
            throw new RuntimeException('assistant_native_attachment_queue_fake_unavailable');
        }

        return $queue->pushed(PrepareAssistantNativeAttachmentsJob::class, $callback);
    }
}
