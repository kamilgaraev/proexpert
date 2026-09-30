<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagDispatchIntent;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagQueueBacklog;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\KnowledgeHubRagSource;
use App\Models\Organization;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class RagDispatchIntentRecoveryTest extends TestCase
{
    private const ENTITY_ID = 9100017;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['cache.default' => 'array', 'ai-assistant.rag.queued_retry_minutes' => 2]);
    }

    public static function busyQueues(): array
    {
        return [['bulk'], ['live'], ['unavailable']];
    }

    #[DataProvider('busyQueues')]
    public function test_unsent_run_and_global_event_recover_while_other_queues_are_busy(string $busy): void
    {
        $attempts = 0;
        $fail = true;
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function () use (&$attempts, &$fail): string {
            $attempts++;
            if ($fail) {
                throw new \RuntimeException('Redis SQL private://connection/password');
            }
            return 'accepted';
        });
        [$coordinator, $global, $organization] = $this->services($bus);
        $this->busy($busy);
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        self::assertSame(2, $attempts);
        self::assertTrue(RagDispatchIntent::isPending($run->fresh()->last_error));
        self::assertSame(\RuntimeException::class, RagDispatchIntent::publicError($run->fresh()->last_error));
        self::assertTrue(RagDispatchIntent::isPending($event->fresh()->last_error));
        self::assertSame(\RuntimeException::class, RagDispatchIntent::publicError($event->fresh()->last_error));
        self::assertStringNotContainsString('password', $event->fresh()->last_error);
        $this->travel(1)->minutes();
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
        $fail = false;
        $this->travel(1)->minutes();
        self::assertSame(1, $coordinator->recoverExpiredRuns());
        self::assertSame(1, $global->recoverPending());
        self::assertSame(4, $attempts);
        self::assertNull($run->fresh()->last_error);
        self::assertNull($event->fresh()->last_error);
        self::assertSame(1, RagIndexRun::query()->where('organization_id', $organization->id)->count());
        self::assertSame(1, RagGlobalIndexEvent::query()->where('entity_id', (string) self::ENTITY_ID)->count());
        $this->travel(3)->minutes();
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
        self::assertSame(4, $attempts);
    }

    #[DataProvider('busyQueues')]
    public function test_accepted_jobs_and_worker_retries_do_not_bypass_busy_queue_guard(string $busy): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturn('accepted');
        [$coordinator, $global, $organization] = $this->services($bus);
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        self::assertNull($run->fresh()->last_error);
        self::assertNull($event->fresh()->last_error);
        $this->busy($busy);
        $this->travel(3)->minutes();
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
        $claimed = $coordinator->markRunning($run->id);
        self::assertNotNull($claimed);
        $coordinator->releaseForRetry($run->id, $claimed->lease_token, new \RuntimeException('Worker retry'));
        $claimedEvent = $global->claim($event->id, $event->revision);
        self::assertNotNull($claimedEvent);
        $global->release($claimedEvent, new \RuntimeException('Worker retry'));
        self::assertSame(\RuntimeException::class, $run->fresh()->last_error);
        self::assertSame(\RuntimeException::class, $event->fresh()->last_error);
        $this->travel(3)->minutes();
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
    }

    public function test_dispatch_marker_is_saved_before_commit_and_rolls_back_with_intent(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->never())->method('dispatch');
        [$coordinator, $global, $organization] = $this->services($bus);
        DB::beginTransaction();
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        self::assertTrue(RagDispatchIntent::isPending($run->fresh()->last_error));
        self::assertNull(RagDispatchIntent::publicError($run->fresh()->last_error));
        self::assertTrue(RagDispatchIntent::isPending($event->fresh()->last_error));
        self::assertNull(RagDispatchIntent::publicError($event->fresh()->last_error));
        DB::rollBack();
        $this->assertDatabaseMissing('ai_rag_index_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('ai_rag_global_index_events', ['id' => $event->id]);
    }

    public function test_durable_marker_recovers_intent_when_after_commit_callback_was_not_invoked(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturn('accepted');
        [$coordinator, $global, $organization] = $this->services($bus);
        $run = RagIndexRun::query()->create(['organization_id' => $organization->id, 'source_type' => 'estimate',
            'entity_type' => 'estimate_item', 'entity_id' => (string) self::ENTITY_ID, 'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_ASYNC, 'queued_at' => now(), 'last_error' => RagDispatchIntent::pending()]);
        $event = RagGlobalIndexEvent::query()->create(['source_type' => 'knowledge', 'entity_type' => 'knowledge_article',
            'entity_id' => (string) self::ENTITY_ID, 'revision' => 1, 'after_organization_id' => 7,
            'status' => RagGlobalIndexEvent::STATUS_QUEUED, 'queued_at' => now(), 'last_error' => RagDispatchIntent::pending()]);
        $this->busy('bulk');
        $this->travel(2)->minutes();
        self::assertSame(1, $coordinator->recoverExpiredRuns());
        self::assertSame(1, $global->recoverPending());
        self::assertNull($run->fresh()->last_error);
        self::assertNull($event->fresh()->last_error);
        self::assertSame(7, $event->fresh()->after_organization_id);
    }

    public function test_failed_resend_preserves_marker_and_retry_cutoff(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(4))->method('dispatch')->willThrowException(new \RuntimeException('Redis unavailable'));
        [$coordinator, $global, $organization] = $this->services($bus);
        $this->busy('live');
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $oldRunMarker = $run->fresh()->last_error;
        $oldEventMarker = $event->fresh()->last_error;
        $this->travel(2)->minutes();
        self::assertSame(1, $coordinator->recoverExpiredRuns());
        self::assertSame(1, $global->recoverPending());
        self::assertTrue(RagDispatchIntent::isPending($run->fresh()->last_error));
        self::assertTrue(RagDispatchIntent::isPending($event->fresh()->last_error));
        self::assertNotSame($oldRunMarker, $run->fresh()->last_error);
        self::assertNotSame($oldEventMarker, $event->fresh()->last_error);
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
    }

    public function test_old_success_acknowledgment_cannot_clear_new_global_revision_marker(): void
    {
        $global = null;
        $failNew = true;
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (IndexGlobalRagEntityJob $job) use (&$global, &$failNew): string {
            if ($job->eventRevision === 1) {
                $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
            } elseif ($failNew) {
                throw new \RuntimeException('New revision dispatch failed');
            }
            return 'accepted';
        });
        [, $global] = $this->services($bus);
        $this->busy('live');
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID)->fresh();
        self::assertSame(2, $event->revision);
        self::assertTrue(RagDispatchIntent::isPending($event->last_error));
        self::assertNull($global->claim($event->id, 1));
        $failNew = false;
        $this->travel(2)->minutes();
        self::assertSame(1, $global->recoverPending());
        self::assertSame(2, $event->fresh()->revision);
        self::assertNull($event->fresh()->last_error);
    }

    public function test_enqueue_acknowledgment_does_not_erase_worker_retry_error(): void
    {
        $coordinator = null;
        $global = null;
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function ($job) use (&$coordinator, &$global): string {
            if ($job instanceof IndexRagSourceJob) {
                $run = $coordinator->markRunning($job->runId);
                $coordinator->releaseForRetry($run->id, $run->lease_token, new \RuntimeException('Worker retry'));
            } else {
                $event = $global->claim($job->eventId, $job->eventRevision);
                $global->release($event, new \RuntimeException('Worker retry'));
            }
            return 'accepted';
        });
        [$coordinator, $global, $organization] = $this->services($bus);
        $this->busy('live');
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        self::assertSame(\RuntimeException::class, $run->fresh()->last_error);
        self::assertSame(\RuntimeException::class, $event->fresh()->last_error);
        $this->travel(3)->minutes();
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
    }

    public function test_old_run_acknowledgment_cannot_clear_new_dispatch_attempt(): void
    {
        $replacement = RagDispatchIntent::pending();
        $attempts = 0;
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (IndexRagSourceJob $job) use ($replacement, &$attempts): string {
            if (++$attempts === 1) {
                RagIndexRun::query()->whereKey($job->runId)->update(['last_error' => $replacement]);
            }
            return 'accepted';
        });
        [$coordinator, , $organization] = $this->services($bus);
        $this->busy('live');
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        self::assertSame($replacement, $run->fresh()->last_error);
        $this->travel(2)->minutes();
        self::assertSame(1, $coordinator->recoverExpiredRuns());
        self::assertSame(2, $attempts);
        self::assertNull($run->fresh()->last_error);
    }

    public function test_late_dispatch_failure_cannot_replace_worker_retry_error(): void
    {
        $coordinator = null;
        $global = null;
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function ($job) use (&$coordinator, &$global): never {
            if ($job instanceof IndexRagSourceJob) {
                $run = $coordinator->markRunning($job->runId);
                $coordinator->releaseForRetry($run->id, $run->lease_token, new \RuntimeException('Worker retry'));
            } else {
                $event = $global->claim($job->eventId, $job->eventRevision);
                $global->release($event, new \RuntimeException('Worker retry'));
            }
            throw new \LogicException('Late transport failure');
        });
        [$coordinator, $global, $organization] = $this->services($bus);
        $this->busy('live');
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        self::assertSame(\RuntimeException::class, $run->fresh()->last_error);
        self::assertSame(\RuntimeException::class, $event->fresh()->last_error);
        $this->travel(3)->minutes();
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $global->recoverPending());
    }

    public function test_expired_leases_recover_while_busy_and_keep_cursor_and_generation_protection(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(4))->method('dispatch')->willReturn('accepted');
        [$coordinator, $global, $organization] = $this->services($bus);
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', self::ENTITY_ID);
        $event = $global->record('knowledge', 'knowledge_article', self::ENTITY_ID);
        $oldRun = $coordinator->markRunning($run->id);
        $oldEvent = $global->claim($event->id, 1);
        $oldRun->update(['lease_expires_at' => now()->subSecond()]);
        $oldEvent->update(['after_organization_id' => 7, 'lease_expires_at' => now()->subSecond()]);
        $this->busy('bulk');
        self::assertSame(1, $coordinator->recoverExpiredRuns());
        self::assertSame(1, $global->recoverPending());
        $newRun = $coordinator->markRunning($run->id);
        $newEvent = $global->claim($event->id, 1);
        self::assertNotSame($oldRun->lease_token, $newRun->lease_token);
        self::assertNotSame($oldEvent->lease_token, $newEvent->lease_token);
        self::assertFalse($coordinator->heartbeat($run->id, $oldRun->lease_token));
        self::assertNull($coordinator->markSucceeded($run->id, 0, $oldRun->lease_token));
        $global->release($oldEvent, new \RuntimeException('Stale worker'));
        $global->processBatch($oldEvent, $coordinator);
        self::assertSame(RagGlobalIndexEvent::STATUS_RUNNING, $event->fresh()->status);
        self::assertSame($newEvent->lease_token, $event->fresh()->lease_token);
        self::assertSame(7, $event->fresh()->after_organization_id);
    }

    public function test_internal_pending_marker_does_not_appear_as_api_error(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $run = RagIndexRun::query()->create(['organization_id' => $organization->id, 'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_ASYNC, 'queued_at' => now(), 'last_error' => RagDispatchIntent::pending()]);
        $payload = (new RagIndexStatusResource(['latest_run' => $run]))->toArray(Request::create('/', 'GET'));
        self::assertSame(RagIndexRun::STATUS_QUEUED, $payload['latest_run']['status']);
        self::assertNull($payload['latest_run']['last_error']);
        $exception = new class('SQL and private://password must stay private') extends \RuntimeException {};
        $run->update(['last_error' => RagDispatchIntent::pending($exception)]);
        $payload = (new RagIndexStatusResource(['latest_run' => $run]))->toArray(Request::create('/', 'GET'));
        self::assertSame(\RuntimeException::class, $payload['latest_run']['last_error']);
        self::assertLessThanOrEqual(255, strlen($run->last_error));
        self::assertStringNotContainsString('private', $run->last_error);
        self::assertStringNotContainsString(__FILE__, $run->last_error);
    }

    private function services(Dispatcher $bus): array
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $logger = $this->createMock(LoggerInterface::class);
        return [new RagIndexingCoordinator(app(RagIndexer::class), new RagJobDispatcher($bus, $logger)),
            new GlobalRagQueue(new RagSourceRegistry([new KnowledgeHubRagSource()]), $bus, $logger), $organization];
    }

    private function busy(string $kind): void
    {
        if ($kind === 'unavailable') {
            Queue::partialMock()->shouldReceive('connection')->andThrow(new \RuntimeException('Queue probe unavailable'));
            self::assertFalse(RagQueueBacklog::isEmpty());
            return;
        }
        $queue = (string) config($kind === 'bulk' ? 'ai-assistant.rag.queue' : 'ai-assistant.rag.live_queue');
        Queue::pushOn($queue, new IndexRagSourceJob(self::ENTITY_ID + 1));
        self::assertFalse(RagQueueBacklog::isEmpty());
    }
}
