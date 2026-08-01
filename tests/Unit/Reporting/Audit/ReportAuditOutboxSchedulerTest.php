<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Audit;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportAuditOutboxScheduler;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntentLease;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportAuditOutboxSchedulerTest extends TestCase
{
    public function test_dispatches_only_due_audit_intent_ids_in_store_order(): void
    {
        $store = new SchedulerAuditIntentStore([
            '01J00000000000000000000001',
            '01J00000000000000000000002',
        ]);
        $dispatcher = new RecordingAuditDispatcher;
        $now = new DateTimeImmutable('2026-07-30T10:00:00.123456Z');

        $count = (new ReportAuditOutboxScheduler($store, $dispatcher))->dispatchDue(20, $now);

        self::assertSame(2, $count);
        self::assertSame($store->ids, $dispatcher->ids);
        self::assertSame([['limit' => 20, 'now' => $now]], $store->dueCalls);
        self::assertSame([], $store->reclaimCalls);
        self::assertSame(0, $store->mutationCalls);
    }

    public function test_rejects_invalid_batches_and_malformed_ids_before_dispatch(): void
    {
        $dispatcher = new RecordingAuditDispatcher;
        $now = new DateTimeImmutable('2026-07-30T10:00:00Z');

        foreach ([0, 501] as $limit) {
            $store = new SchedulerAuditIntentStore([]);
            try {
                (new ReportAuditOutboxScheduler($store, $dispatcher))->dispatchDue($limit, $now);
                self::fail('Invalid audit batch was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_audit_batch_size_invalid', $exception->getMessage());
                self::assertSame([], $store->dueCalls);
            }
        }

        $store = new SchedulerAuditIntentStore(['not-an-ulid']);
        try {
            (new ReportAuditOutboxScheduler($store, $dispatcher))->dispatchDue(1, $now);
            self::fail('Malformed audit intent ID was dispatched.');
        } catch (\LogicException $exception) {
            self::assertSame('report_audit_due_id_invalid', $exception->getMessage());
            self::assertSame([], $dispatcher->ids);
        }
    }
}

final class RecordingAuditDispatcher implements ReportAuditDispatcher
{
    public array $ids = [];

    public function dispatch(string $intentId): void
    {
        $this->ids[] = $intentId;
    }
}

final class SchedulerAuditIntentStore implements ReportAuditIntentStore
{
    public array $dueCalls = [];

    public int $mutationCalls = 0;

    public array $reclaimCalls = [];

    public function __construct(public readonly array $ids) {}

    public function add(string $eventKey, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void
    {
        $this->mutationCalls++;
    }

    public function dueIds(int $limit, DateTimeImmutable $now): array
    {
        $this->dueCalls[] = compact('limit', 'now');

        return $this->ids;
    }

    public function claim(string $intentId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leasedUntil): ?ReportAuditIntentLease
    {
        $this->mutationCalls++;

        return null;
    }

    public function loadLeased(string $intentId, string $leaseToken): ReportAuditIntent
    {
        $this->mutationCalls++;
        throw new \LogicException('not used');
    }

    public function acknowledge(string $intentId, string $leaseToken, DateTimeImmutable $deliveredAt): void
    {
        $this->mutationCalls++;
    }

    public function failDelivery(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void
    {
        $this->mutationCalls++;
    }

    public function reclaimExpired(int $limit, DateTimeImmutable $occurredAt): int
    {
        $this->mutationCalls++;
        $this->reclaimCalls[] = compact('limit', 'occurredAt');

        return 0;
    }
}
