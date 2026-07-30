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
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentReconciler;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchLease;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchTopic;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Infrastructure\Dispatch\LaravelReportDispatchIntentPublisher;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportRuntimeFixture;

final class ReportDispatchIntentReconcilerTest extends TestCase
{
    public function test_reclaims_before_publishing_and_preserves_publisher_summary(): void
    {
        $trace = [];
        $store = new ReconcilerStore($trace, true);
        $runs = new ReconcilerRunDispatcher;
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher(
                $runs,
                new ReconcilerExportDispatcher,
            ),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );

        $summary = (new ReportDispatchIntentReconciler($store, $publisher))
            ->reconcile(25, new DateTimeImmutable('2026-07-28T12:00:00Z'));

        self::assertSame(['reclaim:4', 'claim', 'published:expired', 'published:pending'], $trace);
        self::assertSame(['01J00000000000000000000001', '01J00000000000000000000002'], $runs->ids);
        self::assertSame(5, $store->attempts['expired']);
        self::assertSame('leased', $store->states['active']);
        self::assertSame([2, 2, 2, 0, 0, 0], [
            $summary->scanned,
            $summary->claimed,
            $summary->published,
            $summary->retryScheduled,
            $summary->deadLettered,
            $summary->skipped,
        ]);
    }

    public function test_invalid_limit_fails_before_store_access(): void
    {
        $trace = [];
        $store = new ReconcilerStore($trace);
        $publisher = new ReportDispatchIntentPublisher(
            $store,
            new LaravelReportDispatchIntentPublisher(
                new ReconcilerRunDispatcher,
                new ReconcilerExportDispatcher,
            ),
            new ReportDispatchBackoffPolicy(ReportRuntimeFixture::configuration()),
            ReportRuntimeFixture::telemetry(),
            ReportRuntimeFixture::configuration(),
        );
        $reconciler = new ReportDispatchIntentReconciler($store, $publisher);

        foreach ([0, 501] as $limit) {
            try {
                $reconciler->reconcile($limit, new DateTimeImmutable('2026-07-28T12:00:00Z'));
                self::fail('Invalid limit was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $trace);
            }
        }
    }
}

final class ReconcilerStore implements ReportDispatchIntentStore
{
    private const IDS = [
        'expired' => '01J00000000000000000000001',
        'pending' => '01J00000000000000000000002',
    ];

    public array $states = [];

    public array $attempts = [];

    public function __construct(private array &$trace, bool $seed = false)
    {
        if ($seed) {
            $this->states = ['expired' => 'leased-expired', 'pending' => 'pending', 'active' => 'leased'];
            $this->attempts = ['expired' => 4, 'pending' => 0, 'active' => 2];
        }
    }

    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}

    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array
    {
        $this->trace[] = 'claim';

        $leases = [];
        foreach (['expired', 'pending'] as $id) {
            if (($this->states[$id] ?? null) !== 'pending') {
                continue;
            }
            $this->attempts[$id]++;
            $this->states[$id] = 'leased';
            $leases[] = new ReportDispatchLease(
                new ReportDispatchIntent(
                    self::IDS[$id],
                    "event:{$id}",
                    1,
                    ReportDispatchAggregate::RUN,
                    self::IDS[$id],
                    ReportDispatchTopic::MATERIALIZE_RUN,
                    $this->attempts[$id],
                    $now,
                    $now,
                ),
                $leaseToken,
                $leasedUntil,
            );
        }

        return $leases;
    }

    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void
    {
        $id = array_search($intentId, self::IDS, true);
        if (! is_string($id)) {
            throw new LogicException('unexpected intent');
        }
        $this->states[$id] = 'published';
        $this->trace[] = "published:{$id}";
    }

    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void {}

    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
    {
        $this->trace[] = 'reclaim:'.($this->attempts['expired'] ?? 0);

        if (($this->states['expired'] ?? null) === 'leased-expired') {
            $this->states['expired'] = 'pending';

            return 1;
        }

        return 0;
    }
}

final class ReconcilerRunDispatcher implements ReportMaterializationDispatcher
{
    public array $ids = [];

    public function dispatch(string $runId): void
    {
        $this->ids[] = $runId;
    }
}

final class ReconcilerExportDispatcher implements ReportExportDispatcher
{
    public function dispatch(string $exportId): void {}
}
