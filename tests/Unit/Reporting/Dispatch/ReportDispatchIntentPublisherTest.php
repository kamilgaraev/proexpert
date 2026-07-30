<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchBackoffPolicy;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentPublisher;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchLease;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchTopic;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Dispatch\LaravelReportDispatchIntentPublisher;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\ReportRuntimeFixture;

final class ReportDispatchIntentPublisherTest extends TestCase
{
    public function test_publishes_only_aggregate_ids_and_acknowledges_transport_intents(): void
    {
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');
        $store = new InMemoryDispatchIntentStore([
            $this->lease('01J00000000000000000000001', ReportDispatchAggregate::RUN, ReportDispatchTopic::MATERIALIZE_RUN, 1),
            $this->lease('01J00000000000000000000002', ReportDispatchAggregate::EXPORT, ReportDispatchTopic::GENERATE_EXPORT, 2),
        ]);
        $runs = new RecordingMaterializationDispatcher;
        $exports = new RecordingExportDispatcher;
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher($runs, $exports),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );

        $summary = $publisher->publishBatch(20, $now);

        self::assertSame(['01J00000000000000000000001'], $runs->ids);
        self::assertSame(['01J00000000000000000000002'], $exports->ids);
        self::assertSame(2, $summary->scanned);
        self::assertSame(2, $summary->claimed);
        self::assertSame(2, $summary->published);
        self::assertSame(0, $summary->retryScheduled);
        self::assertSame(0, $summary->deadLettered);
        self::assertSame(0, $summary->skipped);
        self::assertCount(2, $store->published);
        self::assertSame($store->claimedToken, $store->published[0]['lease_token']);
        self::assertSame('2026-07-28T10:00:00.123456+00:00', $store->published[0]['occurred_at']->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_transport_failure_is_fenced_and_scheduled_with_exact_backoff(): void
    {
        $now = new DateTimeImmutable('2026-07-28T10:00:00.999999Z');
        $store = new InMemoryDispatchIntentStore([
            $this->lease('01J00000000000000000000001', ReportDispatchAggregate::RUN, ReportDispatchTopic::MATERIALIZE_RUN, 3),
        ]);
        $runs = new RecordingMaterializationDispatcher(true);
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher($runs, new RecordingExportDispatcher),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );

        $summary = $publisher->publishBatch(1, $now);

        self::assertSame(0, $summary->published);
        self::assertSame(1, $summary->retryScheduled);
        self::assertSame(0, $summary->deadLettered);
        self::assertCount(1, $store->failed);
        self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $store->failed[0]['error_code']);
        self::assertSame($store->claimedToken, $store->failed[0]['lease_token']);
        self::assertSame('2026-07-28T10:01:00.000000Z', $store->failed[0]['next_attempt_at']->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function test_attempt_twelve_is_counted_as_dead_lettered(): void
    {
        $store = new InMemoryDispatchIntentStore([
            $this->lease('01J00000000000000000000001', ReportDispatchAggregate::RUN, ReportDispatchTopic::MATERIALIZE_RUN, 12),
        ]);
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher(
                new RecordingMaterializationDispatcher(true),
                new RecordingExportDispatcher,
            ),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );

        $summary = $publisher->publishBatch(1, new DateTimeImmutable('2026-07-28T10:00:00Z'));

        self::assertSame(0, $summary->retryScheduled);
        self::assertSame(1, $summary->deadLettered);
        self::assertCount(1, $store->failed);
    }

    public function test_attempt_one_and_eleven_use_exact_retry_boundaries(): void
    {
        foreach ([1 => '2026-07-28T10:00:15.000000Z', 11 => '2026-07-28T11:00:00.000000Z'] as $attempt => $expected) {
            $store = new InMemoryDispatchIntentStore([
                $this->lease('01J00000000000000000000001', ReportDispatchAggregate::RUN, ReportDispatchTopic::MATERIALIZE_RUN, $attempt),
            ]);
            $publisher = new ReportDispatchIntentPublisher(
                $store,
                new LaravelReportDispatchIntentPublisher(new RecordingMaterializationDispatcher(true), new RecordingExportDispatcher),
                new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
                ReportRuntimeFixture::telemetry(),
                ReportRuntimeFixture::configuration(),
            );

            $summary = $publisher->publishBatch(1, new DateTimeImmutable('2026-07-28T10:00:00Z'));

            self::assertSame(1, $summary->retryScheduled);
            self::assertSame(0, $summary->deadLettered);
            self::assertSame($expected, $store->failed[0]['next_attempt_at']->format('Y-m-d\TH:i:s.u\Z'));
        }
    }

    public function test_stale_typed_lease_is_skipped_without_transport_or_store_mutation(): void
    {
        $store = new InMemoryDispatchIntentStore([
            $this->lease('01J00000000000000000000001', ReportDispatchAggregate::RUN, ReportDispatchTopic::MATERIALIZE_RUN, 1),
        ], false);
        $runs = new RecordingMaterializationDispatcher;
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher($runs, new RecordingExportDispatcher),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );

        $summary = $publisher->publishBatch(1, new DateTimeImmutable('2026-07-28T10:00:00Z'));

        self::assertSame([1, 0, 0, 0, 0, 1], [
            $summary->scanned, $summary->claimed, $summary->published,
            $summary->retryScheduled, $summary->deadLettered, $summary->skipped,
        ]);
        self::assertSame([], $runs->ids);
        self::assertSame([], $store->published);
        self::assertSame([], $store->failed);
    }

    public function test_success_before_ack_crash_is_reclaimed_and_redelivered_with_fencing(): void
    {
        $store = new CrashRecoveryDispatchIntentStore($this->lease(
            '01J00000000000000000000001',
            ReportDispatchAggregate::RUN,
            ReportDispatchTopic::MATERIALIZE_RUN,
            1,
        )->intent);
        $runs = new RecordingMaterializationDispatcher;
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher($runs, new RecordingExportDispatcher),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );
        $now = new DateTimeImmutable('2026-07-28T10:00:00Z');

        $first = $publisher->publishBatch(1, $now);
        self::assertSame(1, $first->published);
        self::assertSame('leased', $store->status);
        self::assertSame(1, $store->attemptCount);
        self::assertSame(1, $store->reclaimExpiredLeases(1, $now->modify('+61 seconds')));
        $second = $publisher->publishBatch(1, $now->modify('+61 seconds'));

        self::assertSame(1, $second->published);
        self::assertSame('published', $store->status);
        self::assertSame(2, $store->attemptCount);
        self::assertSame(['01J00000000000000000000001', '01J00000000000000000000001'], $runs->ids);
    }

    public function test_empty_batch_does_not_call_transport(): void
    {
        $store = new InMemoryDispatchIntentStore([]);
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher(
                new RecordingMaterializationDispatcher,
                new RecordingExportDispatcher,
            ),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );

        $summary = $publisher->publishBatch(500, new DateTimeImmutable('2026-07-28T10:00:00Z'));

        self::assertSame([0, 0, 0, 0, 0, 0], [
            $summary->scanned,
            $summary->claimed,
            $summary->published,
            $summary->retryScheduled,
            $summary->deadLettered,
            $summary->skipped,
        ]);
    }

    public function test_malformed_claim_fails_hard_before_transport_or_store_mutation(): void
    {
        foreach ([42, new \stdClass, $this->lease(
            '01J00000000000000000000001',
            ReportDispatchAggregate::RUN,
            ReportDispatchTopic::MATERIALIZE_RUN,
            1,
        )->intent] as $malformed) {
            $store = new InMemoryDispatchIntentStore([$malformed]);
            $runs = new RecordingMaterializationDispatcher;
            $publisher = new ReportDispatchIntentPublisher(
                $store,
                new LaravelReportDispatchIntentPublisher($runs, new RecordingExportDispatcher),
                new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
                ReportRuntimeFixture::telemetry(),
                ReportRuntimeFixture::configuration(),
            );

            try {
                $publisher->publishBatch(1, new DateTimeImmutable('2026-07-28T10:00:00Z'));
                self::fail('Malformed claimed item was accepted.');
            } catch (LogicException) {
                self::assertSame([], $runs->ids);
                self::assertSame([], $store->published);
                self::assertSame([], $store->failed);
            }
        }
    }

    public function test_transport_rejects_cross_topic_before_any_dispatch(): void
    {
        $runs = new RecordingMaterializationDispatcher;
        $exports = new RecordingExportDispatcher;
        $transport = new LaravelReportDispatchIntentPublisher($runs, $exports);
        $intent = new ReportDispatchIntent(
            '01J00000000000000000000001',
            'event:mismatch',
            10,
            ReportDispatchAggregate::RUN,
            '01J00000000000000000000001',
            ReportDispatchTopic::GENERATE_EXPORT,
            1,
            new DateTimeImmutable('2026-07-28T09:00:00Z'),
            new DateTimeImmutable('2026-07-28T09:00:00Z'),
        );

        try {
            $transport->publish($intent);
            self::fail('Cross-topic dispatch was accepted.');
        } catch (LogicException) {
            self::assertSame([], $runs->ids);
            self::assertSame([], $exports->ids);
        }
    }

    public function test_rejects_invalid_batch_and_lease_configuration_before_claim(): void
    {
        $store = new InMemoryDispatchIntentStore([]);
        $transport = new LaravelReportDispatchIntentPublisher(
            new RecordingMaterializationDispatcher,
            new RecordingExportDispatcher,
        );

        foreach ([14, 301] as $leaseSeconds) {
            try {
                new \App\BusinessModules\Core\Reporting\Application\Execution\ReportExecutionRuntimeConfiguration(
                    100,
                    $leaseSeconds,
                    12,
                    100,
                    300,
                    12,
                    960,
                    100,
                );
                self::fail('Invalid lease was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $store->claimCalls);
            }
        }

        $publisher = new ReportDispatchIntentPublisher(
            $store,
            $transport,
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );
        foreach ([0, 501] as $limit) {
            try {
                $publisher->publishBatch($limit, new DateTimeImmutable('2026-07-28T10:00:00Z'));
                self::fail('Invalid limit was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $store->claimCalls);
            }
        }
    }

    private function lease(
        string $id,
        ReportDispatchAggregate $aggregate,
        ReportDispatchTopic $topic,
        int $attempt,
    ): ReportDispatchLease {
        return new ReportDispatchLease(
            new ReportDispatchIntent(
                $id,
                "event:{$id}",
                10,
                $aggregate,
                $id,
                $topic,
                $attempt,
                new DateTimeImmutable('2026-07-28T09:00:00Z'),
                new DateTimeImmutable('2026-07-28T09:00:00Z'),
            ),
            '00000000-0000-4000-8000-000000000000',
            new DateTimeImmutable('2026-07-28T10:00:30Z'),
        );
    }
}

final class InMemoryDispatchIntentStore implements ReportDispatchIntentStore
{
    public int $claimCalls = 0;

    public ?string $claimedToken = null;

    public array $published = [];

    public array $failed = [];

    public function __construct(
        private readonly array $leases,
        private readonly bool $rewriteLeaseToken = true,
    ) {}

    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array
    {
        $this->claimCalls++;
        $this->claimedToken = $leaseToken;

        return array_map(
            fn (mixed $lease): mixed => $lease instanceof ReportDispatchLease && $this->rewriteLeaseToken
                ? new ReportDispatchLease($lease->intent, $leaseToken, $leasedUntil)
                : $lease,
            $this->leases,
        );
    }

    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void
    {
        $this->published[] = compact('intentId', 'leaseToken', 'occurredAt') + [
            'lease_token' => $leaseToken,
            'occurred_at' => $occurredAt,
        ];
    }

    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void
    {
        $this->failed[] = [
            'intent_id' => $intentId,
            'lease_token' => $leaseToken,
            'error_code' => $errorCode,
            'occurred_at' => $occurredAt,
            'next_attempt_at' => $nextAttemptAt,
        ];
    }

    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
    {
        return 0;
    }
}

final class RecordingMaterializationDispatcher implements ReportMaterializationDispatcher
{
    public array $ids = [];

    public function __construct(private readonly bool $fail = false) {}

    public function dispatch(string $runId): void
    {
        $this->ids[] = $runId;
        if ($this->fail) {
            throw new RuntimeException('transport unavailable');
        }
    }
}

final class CrashRecoveryDispatchIntentStore implements ReportDispatchIntentStore
{
    public string $status = 'pending';

    public int $attemptCount = 0;

    private ?string $leaseToken = null;

    private ?DateTimeImmutable $leaseExpiresAt = null;

    private bool $simulateAckCrash = true;

    public function __construct(private readonly ReportDispatchIntent $intent) {}

    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array
    {
        if ($this->status !== 'pending') {
            return [];
        }
        $this->status = 'leased';
        $this->attemptCount++;
        $this->leaseToken = $leaseToken;
        $this->leaseExpiresAt = $leasedUntil;

        return [new ReportDispatchLease(
            new ReportDispatchIntent(
                $this->intent->id,
                $this->intent->eventKey,
                $this->intent->organizationId,
                $this->intent->aggregate,
                $this->intent->aggregateId,
                $this->intent->topic,
                $this->attemptCount,
                $this->intent->occurredAt,
                $this->intent->availableAt,
            ),
            $leaseToken,
            $leasedUntil,
        )];
    }

    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void
    {
        if ($this->status !== 'leased' || $this->leaseToken !== $leaseToken) {
            return;
        }
        if ($this->simulateAckCrash) {
            $this->simulateAckCrash = false;

            return;
        }
        $this->status = 'published';
        $this->leaseToken = null;
        $this->leaseExpiresAt = null;
    }

    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void {}

    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
    {
        if ($this->status !== 'leased' || ! $this->leaseExpiresAt instanceof DateTimeImmutable || $this->leaseExpiresAt > $occurredAt) {
            return 0;
        }
        $this->status = 'pending';
        $this->leaseToken = null;
        $this->leaseExpiresAt = null;

        return 1;
    }
}

final class RecordingExportDispatcher implements ReportExportDispatcher
{
    public array $ids = [];

    public function dispatch(string $exportId): void
    {
        $this->ids[] = $exportId;
    }
}
