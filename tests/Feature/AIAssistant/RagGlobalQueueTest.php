<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob;
use App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\KnowledgeHubRagSource;
use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class RagGlobalQueueTest extends TestCase
{
    private GlobalRagQueue $global;
    private array $jobs = [];
    private array $warnings = [];
    private bool $failDispatch = false;
    private const ENTITY_ID = 9000001;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(function (IndexGlobalRagEntityJob $job): string {
            $this->jobs[] = $job;
            if ($this->failDispatch) {
                throw new \RuntimeException('Redis unavailable, private connection details');
            }
            return 'delivered';
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, array $context): void {
            $this->warnings[] = [$message, $context];
        });
        $this->global = new GlobalRagQueue(new RagSourceRegistry([new KnowledgeHubRagSource()]), $bus, $logger);
    }

    public function test_initial_redis_failure_keeps_event_and_recovery_retries_same_identity_within_two_minutes(): void
    {
        $this->failDispatch = true;
        DB::beginTransaction();
        $this->global->queueAfterCommit('knowledge', 'knowledge_article', self::ENTITY_ID);
        $this->assertSame(0, $this->eventQuery()->count());
        $this->assertSame([], $this->jobs);
        DB::commit();
        $event = $this->eventQuery()->firstOrFail();
        $this->assertSame(RagGlobalIndexEvent::STATUS_QUEUED, $event->status);
        $this->assertSame(\RuntimeException::class, $event->last_error);
        $this->assertSame(0, $event->after_organization_id);
        $this->assertSame(1, $event->revision);
        $this->assertArrayNotHasKey('content', $event->getAttributes());
        $this->assertSame(['event_id' => $event->id, 'exception_class' => \RuntimeException::class], $this->warnings[0][1]);
        $this->failDispatch = false;
        $this->travel(2)->minutes();
        $this->assertSame(1, $this->global->recoverPending());
        $this->assertSame(1, $this->eventQuery()->count());
        $this->assertCount(2, $this->jobs);
        $this->assertSame($event->id, $this->jobs[1]->eventId);
        $this->assertSame(1, $this->jobs[1]->eventRevision);
        $this->assertNotNull($this->global->claim($event->id, 1));
        $this->assertSame(0, $this->global->recoverPending());
    }

    public function test_queued_event_waiting_in_redis_is_not_dispatched_again_after_two_minutes(): void
    {
        $event = $this->global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $this->assertSame('ai-rag-live', $this->jobs[0]->queue);
        Queue::pushOn('ai-rag-live', new IndexGlobalRagEntityJob('knowledge', 'knowledge_article', self::ENTITY_ID));

        $this->travel(3)->minutes();

        $this->assertSame(0, $this->global->recoverPending());
        $this->assertCount(1, $this->jobs);
        $this->assertSame(RagGlobalIndexEvent::STATUS_QUEUED, $event->fresh()->status);
    }

    public function test_old_queued_event_is_recovered_when_rag_queues_are_empty(): void
    {
        $event = $this->global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $this->travel(3)->minutes();

        $this->assertSame(1, $this->global->recoverPending());
        $this->assertCount(2, $this->jobs);
        $this->assertSame($event->id, $this->jobs[1]->eventId);
    }

    public function test_continuation_failure_resumes_server_cursor_and_skips_unindexed_inactive_organizations(): void
    {
        Organization::factory()->count(51)->create(['is_active' => true]);
        $inactive = Organization::factory()->create(['is_active' => false]);
        $cleanup = Organization::factory()->create(['is_active' => false]);
        RagSource::query()->create(['organization_id' => $cleanup->id, 'source_type' => 'knowledge', 'entity_type' => 'knowledge_article',
            'entity_id' => (string) self::ENTITY_ID, 'title' => 'Удалённое знание', 'checksum' => str_repeat('a', 64), 'metadata' => []]);
        $eligibleIds = Organization::query()->where('is_active', true)->orWhere('id', $cleanup->id)->orderBy('id')->pluck('id')->all();
        $event = $this->global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $coordinator = new GlobalRecordingCoordinator();
        $this->failDispatch = true;
        $this->jobs[0]->handle($coordinator, $this->global);
        $event->refresh();
        $this->assertSame(RagGlobalIndexEvent::STATUS_QUEUED, $event->status);
        $this->assertSame($eligibleIds[49], $event->after_organization_id);
        $this->assertSame(\RuntimeException::class, $event->last_error);
        $this->assertCount(50, $coordinator->organizationIds);
        $this->failDispatch = false;
        Queue::fake();
        $this->travel(2)->minutes();
        $this->assertSame(1, $this->global->recoverPending());
        $resume = $this->jobs[array_key_last($this->jobs)];
        $this->assertSame($eligibleIds[49], $resume->afterOrganizationId);
        $resume->afterOrganizationId = 0;
        $resume->handle($coordinator, $this->global);
        $this->assertSame($eligibleIds, $coordinator->organizationIds);
        $this->assertNotContains($inactive->id, $coordinator->organizationIds);
        $this->assertContains($cleanup->id, $coordinator->organizationIds);
        $this->assertSame(RagGlobalIndexEvent::STATUS_SUCCEEDED, $event->fresh()->status);
        $this->assertNotNull($event->fresh()->completed_at);
    }

    public function test_change_during_batch_preserves_new_revision_and_old_worker_cannot_complete_or_advance_it(): void
    {
        Organization::factory()->count(2)->create(['is_active' => true]);
        $event = $this->global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $old = $this->global->claim($event->id, 1);
        $coordinator = new GlobalRecordingCoordinator();
        $coordinator->afterQueue = function () use ($coordinator): void {
            $coordinator->afterQueue = null;
            $this->global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        };
        $this->global->processBatch($old, $coordinator);
        $event->refresh();
        $this->assertSame(2, $event->revision);
        $this->assertSame(0, $event->after_organization_id);
        $this->assertSame(RagGlobalIndexEvent::STATUS_QUEUED, $event->status);
        $this->assertNull($event->completed_at);
        $this->assertNull($this->global->claim($event->id, 1));
        $newJob = $this->jobs[array_key_last($this->jobs)];
        $newJob->handle($coordinator, $this->global);
        $this->assertSame(2, $event->fresh()->revision);
        $this->assertSame(RagGlobalIndexEvent::STATUS_SUCCEEDED, $event->fresh()->status);
        $this->assertNotNull($event->fresh()->completed_at);
        $this->assertSame(1, $this->eventQuery()->count());
    }

    public function test_rollback_does_not_store_or_send_global_event(): void
    {
        DB::beginTransaction();
        $this->global->queueAfterCommit('knowledge', 'knowledge_article', self::ENTITY_ID);
        DB::rollBack();
        $this->assertSame(0, $this->eventQuery()->count());
        $this->assertSame([], $this->jobs);
    }

    public function test_expired_global_lease_recovers_existing_cursor_without_permitting_old_worker_progress(): void
    {
        Organization::factory()->create(['is_active' => true]);
        $event = $this->global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $old = $this->global->claim($event->id, 1);
        $old->update(['after_organization_id' => 5, 'lease_expires_at' => now()->subSecond()]);
        $this->assertSame(1, $this->global->recoverPending());
        $this->assertSame(5, $event->fresh()->after_organization_id);
        $coordinator = new GlobalRecordingCoordinator();
        $this->global->processBatch($old, $coordinator);
        $this->assertSame([], $coordinator->organizationIds);
        $this->assertSame(RagGlobalIndexEvent::STATUS_QUEUED, $event->fresh()->status);
        $this->assertSame(5, $this->jobs[array_key_last($this->jobs)]->afterOrganizationId);
    }

    private function eventQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return RagGlobalIndexEvent::query()->where('source_type', 'knowledge')->where('entity_type', 'knowledge_article')->where('entity_id', (string) self::ENTITY_ID);
    }
}

final class GlobalRecordingCoordinator extends RagIndexingCoordinator
{
    public array $organizationIds = [];
    public ?Closure $afterQueue = null;
    public function __construct() {}

    public function queueEntity(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId): RagIndexRun
    {
        $this->organizationIds[] = $organizationId;
        if ($this->afterQueue !== null) {
            ($this->afterQueue)();
        }
        return new RagIndexRun();
    }
}
