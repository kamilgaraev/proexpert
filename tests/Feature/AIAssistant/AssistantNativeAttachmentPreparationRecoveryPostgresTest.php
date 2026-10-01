<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Jobs\PrepareAssistantNativeAttachmentsJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        self::assertSame(2, $queue->dispatchPendingNativeRuns());
        self::assertSame(0, $queue->dispatchPendingNativeRuns());

        Queue::assertPushedOn('default', PrepareAssistantNativeAttachmentsJob::class);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 2);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $legal->id && $job->sourceType === 'legal_business');
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $operations->id && $job->sourceType === 'operations_quality');

        $legalJob = $this->pushedPreparationJobs()
            ->first(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $legal->id);
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $legalJob);
        $queue->markComplete($legal->id, $legalJob->dispatchToken);
        self::assertTrue($queue->isComplete($legal->id));
        self::assertFalse($queue->dispatchQueuedRun($legal));
        self::assertSame(0, $queue->dispatchPendingNativeRuns());
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
        self::assertSame(0, $queue->dispatchPendingNativeRuns());
        self::assertSame(2, $queue->dispatchPendingNativeRuns());

        Queue::assertPushedOn('default', PrepareAssistantNativeAttachmentsJob::class);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 102);
        $laterJobs = $this->pushedPreparationJobs(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $runs[100]->id || $job->runId === $runs[101]->id);
        self::assertEqualsCanonicalizing([$runs[100]->id, $runs[101]->id], $laterJobs->pluck('runId')->all());
    }

    public function test_recovery_high_water_wraps_to_old_terminal_runs_when_new_ids_keep_arriving(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        $oldRun = $this->createRun($organization, 'legal_business', RagIndexRun::STATUS_SUCCEEDED);
        $oldRun->update(['started_at' => now()->subMinute(), 'finished_at' => now()]);
        self::assertTrue($queue->dispatchRetryRun((int) $oldRun->id, (int) $organization->id, 'legal_business'));
        $firstToken = $this->pushedPreparationJobs()->first()->dispatchToken;

        for ($index = 0; $index < 10; $index++) {
            $this->createRun($organization, 'operations_quality');
        }

        self::assertSame(10, $queue->dispatchPendingNativeRuns());
        $this->travel(11)->minutes();
        $this->createRun($organization, 'operations_quality');

        self::assertSame(10, $queue->dispatchPendingNativeRuns());
        $oldRunJobs = $this->pushedPreparationJobs(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $oldRun->id);
        self::assertCount(2, $oldRunJobs);
        self::assertNotSame($firstToken, $oldRunJobs->last()->dispatchToken);
    }

    public function test_duplicate_page_delivery_advances_once_and_checkpoint_recovers_a_lost_dispatch(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organization->id]));
        $actor = User::withoutEvents(fn () => User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]));
        $defect = QualityDefect::withoutEvents(fn () => QualityDefect::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'defect_number' => 'NATIVE-'.Str::uuid(),
            'title' => 'Дефект',
            'severity' => 'major',
            'status' => 'open',
        ]));
        $documents = collect();
        for ($index = 0; $index < 6; $index++) {
            $photo = QualityDefectPhoto::withoutEvents(fn () => QualityDefectPhoto::query()->create([
                'organization_id' => $organization->id,
                'quality_defect_id' => $defect->id,
                'uploaded_by' => $actor->id,
                'type' => 'before',
                'url' => 'https://example.test/private-'.$index.'.png',
                'storage_identity_verified' => false,
                'storage_etag' => null,
                'storage_sha256' => null,
                'size_bytes' => null,
                'mime_type' => null,
            ]));
            $document = AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'file_id' => null,
                'parent_entity_type' => 'quality_defect_photo',
                'parent_entity_id' => (string) $photo->id,
                'storage_path' => $photo->url,
                'filename' => basename($photo->url),
                'mime_type' => 'image/png',
                'checksum' => hash('sha256', (string) $photo->id),
                'size_bytes' => 1,
                'status' => AIAssistantDocument::STATUS_QUEUED,
                'coverage_status' => 'pending',
                'metadata' => [
                    'assistant_native_source' => 'operations_native',
                    'native_source_type' => 'quality_defect_photo',
                    'native_source_id' => (string) $photo->id,
                ],
            ]));
            $documents->push($document);
        }

        $run = $this->createRun($organization, 'operations_quality', RagIndexRun::STATUS_SUCCEEDED);
        $run->update(['started_at' => now()->subMinute(), 'finished_at' => now()]);
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        self::assertTrue($queue->dispatchRetryRun((int) $run->id, (int) $organization->id, 'operations_quality'));
        $initial = $this->pushedPreparationJobs()->first();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $initial);
        $legal = (new \ReflectionClass(AssistantLegalNativeFileIndexer::class))->newInstanceWithoutConstructor();
        $operations = app(AssistantOperationsNativeFileIndexer::class);

        DB::enableQueryLog();
        $initial->handle($legal, $operations, $queue);
        $updatesAfterFirstPage = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'update "ai_assistant_documents"')
        )->count();
        self::assertSame(5, $updatesAfterFirstPage);

        DB::flushQueryLog();
        $initial->handle($legal, $operations, $queue);
        $updatesAfterDuplicateParent = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'update "ai_assistant_documents"')
        )->count();
        self::assertSame(0, $updatesAfterDuplicateParent);
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 2);

        $continuation = $this->pushedPreparationJobs()->last();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $continuation);
        $continuation->handle($legal, $operations, $queue);
        $continuation->handle($legal, $operations, $queue);

        self::assertSame(6, $documents->filter(static fn (AIAssistantDocument $document): bool => $document->fresh()->coverage_status === 'needs_access_review'
        )->count());
        self::assertTrue($queue->isComplete((int) $run->id));
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, 2);
    }

    public function test_pending_page_checkpoint_is_dispatched_again_after_enqueue_failure(): void
    {
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        $dispatches = [];
        $failedEnqueue = static function (int $nextTypeIndex, ?string $afterSourceId): void {
            throw new RuntimeException('simulated_enqueue_failure');
        };

        try {
            $queue->withPageLock(701, 'legal_business', 0, null, function () use ($queue, $failedEnqueue): void {
                $queue->checkpointPageContinuation(701, 'legal_business', 0, null, 1, '15', 'token-a', $failedEnqueue);
            });
            self::fail('Expected the simulated queue failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated_enqueue_failure', $exception->getMessage());
        }

        self::assertSame('pending', $queue->pageProgress(701, 'legal_business', 0, null)['state']);
        $queue->withPageLock(701, 'legal_business', 0, null, function () use ($queue, &$dispatches): void {
            $queue->resumePageContinuation(701, 'legal_business', 0, null, 'token-a', function (int $nextTypeIndex, ?string $afterSourceId) use (&$dispatches): void {
                $dispatches[] = [$nextTypeIndex, $afterSourceId];
            });
        });
        self::assertSame([[1, '15']], $dispatches);
        self::assertSame('dispatched', $queue->pageProgress(701, 'legal_business', 0, null)['state']);

        $queue->withPageLock(701, 'legal_business', 0, null, function () use ($queue, &$dispatches): void {
            $queue->resumePageContinuation(701, 'legal_business', 0, null, 'token-a', function (int $nextTypeIndex, ?string $afterSourceId) use (&$dispatches): void {
                $dispatches[] = [$nextTypeIndex, $afterSourceId];
            });
        });
        self::assertSame([[1, '15']], $dispatches);
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
        Queue::assertPushed(PrepareAssistantNativeAttachmentsJob::class, static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $run->id && $job->sourceType === 'legal_business');

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

    public function test_stale_continuation_reclaims_expired_marker_for_terminal_run(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $run = $this->createRun($organization, 'legal_business');
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        self::assertTrue($queue->dispatchQueuedRun($run));
        $initial = $this->pushedPreparationJobs()->first();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $initial);
        $run->update(['status' => RagIndexRun::STATUS_SUCCEEDED, 'started_at' => now()->subMinutes(2), 'finished_at' => now()]);
        $this->travel(11)->minutes();

        $staleContinuation = new PrepareAssistantNativeAttachmentsJob(
            (int) $run->id,
            (int) $organization->id,
            'legal_business',
            $initial->dispatchToken,
            $initial->dispatchCycle,
            1,
            '123',
        );
        $staleContinuation->handle(
            (new \ReflectionClass(AssistantLegalNativeFileIndexer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(AssistantOperationsNativeFileIndexer::class))->newInstanceWithoutConstructor(),
            $queue,
        );

        self::assertTrue($queue->isComplete((int) $run->id));
        self::assertFalse($queue->hasDispatchToken((int) $run->id, $initial->dispatchToken));
    }

    public function test_recovery_redrives_recent_terminal_run_but_skips_old_history(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        $lostRun = $this->createRun($organization, 'legal_business');
        self::assertTrue($queue->dispatchQueuedRun($lostRun));
        $lostJob = $this->pushedPreparationJobs()->first();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $lostJob);
        $lostRun->update(['status' => RagIndexRun::STATUS_SUCCEEDED, 'started_at' => now()->subMinutes(2), 'finished_at' => now()]);

        $failedRun = $this->createRun($organization, 'operations_quality', RagIndexRun::STATUS_FAILED);
        $failedRun->update(['started_at' => now()->subMinutes(2), 'finished_at' => now()]);
        $runningRun = $this->createRun($organization, 'legal_business', RagIndexRun::STATUS_RUNNING);
        $runningRun->update(['started_at' => now()->subMinutes(2), 'heartbeat_at' => now()]);
        $historicalRun = $this->createRun($organization, 'operations_quality', RagIndexRun::STATUS_SUCCEEDED);
        $historicalRun->update(['started_at' => now()->subDays(8), 'finished_at' => now()->subDays(8)]);
        $this->travel(11)->minutes();

        self::assertSame(3, $queue->dispatchPendingNativeRuns());
        $jobs = $this->pushedPreparationJobs();
        $recoveredLostJob = $jobs->last(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $lostRun->id);
        $recoveredFailedJob = $jobs->first(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $failedRun->id);
        $recoveredRunningJob = $jobs->first(static fn (PrepareAssistantNativeAttachmentsJob $job): bool => $job->runId === $runningRun->id);
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $recoveredLostJob);
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $recoveredFailedJob);
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $recoveredRunningJob);
        self::assertNotSame($lostJob->dispatchToken, $recoveredLostJob->dispatchToken);
        $legal = (new \ReflectionClass(AssistantLegalNativeFileIndexer::class))->newInstanceWithoutConstructor();
        $operations = (new \ReflectionClass(AssistantOperationsNativeFileIndexer::class))->newInstanceWithoutConstructor();
        foreach ([$recoveredLostJob, $recoveredFailedJob, $recoveredRunningJob] as $job) {
            $job->handle($legal, $operations, $queue);
        }

        self::assertTrue($queue->isComplete((int) $lostRun->id));
        self::assertTrue($queue->isComplete((int) $failedRun->id));
        self::assertTrue($queue->isComplete((int) $runningRun->id));
        self::assertFalse($queue->isDispatchedOrComplete((int) $historicalRun->id));
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
