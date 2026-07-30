<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionCoordinator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionTrigger;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ReportSubscriptionCoordinatorTest extends TestCase
{
    public function test_manual_run_is_idempotent_and_dispatches_only_new_delivery(): void
    {
        $subscription = $this->subscription();
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $deliveries = new FakeReportSubscriptionDeliveryStore;
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            $deliveries,
            $dispatcher,
            static fn (callable $callback): mixed => $callback(),
        );

        $first = $coordinator->runManual(
            $subscription->organizationId,
            $subscription->ownerId,
            $subscription->id,
            new IdempotencyKey('manual-key-1'),
            new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );
        $second = $coordinator->runManual(
            $subscription->organizationId,
            $subscription->ownerId,
            $subscription->id,
            new IdempotencyKey('manual-key-1'),
            new DateTimeImmutable('2026-07-26T10:05:00+00:00'),
        );

        self::assertSame($first->id, $second->id);
        self::assertSame([[$first->id, 0]], $dispatcher->calls);
    }

    public function test_manual_run_rejects_reused_key_for_changed_subscription_snapshot(): void
    {
        $subscription = $this->subscription();
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $deliveries = new FakeReportSubscriptionDeliveryStore;
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            $deliveries,
            $dispatcher,
            static fn (callable $callback): mixed => $callback(),
        );
        $idempotencyKey = new IdempotencyKey('manual-key-2');

        $coordinator->runManual(
            $subscription->organizationId,
            $subscription->ownerId,
            $subscription->id,
            $idempotencyKey,
            new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );

        $subscriptions->subscription = $this->subscription(transitionVersion: 2);

        try {
            $coordinator->runManual(
                $subscription->organizationId,
                $subscription->ownerId,
                $subscription->id,
                $idempotencyKey,
                new DateTimeImmutable('2026-07-26T10:05:00+00:00'),
            );
            self::fail('Expected idempotency conflict.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }

        self::assertCount(1, $dispatcher->calls);
    }

    public function test_manual_run_rejects_inactive_subscription(): void
    {
        $subscription = $this->subscription(status: ReportSubscriptionStatus::PAUSED);
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $deliveries = new FakeReportSubscriptionDeliveryStore;
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            $deliveries,
            $dispatcher,
            static fn (callable $callback): mixed => $callback(),
        );

        try {
            $coordinator->runManual(
                $subscription->organizationId,
                $subscription->ownerId,
                $subscription->id,
                new IdempotencyKey('manual-key-3'),
                new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
            );
            self::fail('Expected inactive subscription rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }

        self::assertSame([], $dispatcher->calls);
    }

    private function subscription(
        int $transitionVersion = 1,
        ReportSubscriptionStatus $status = ReportSubscriptionStatus::ACTIVE,
    ): ReportSubscription {
        $bytes = '{"report":"budget"}';
        $now = new DateTimeImmutable('2026-07-26T09:00:00+00:00');

        return new ReportSubscription(
            '01J00000000000000000000001',
            10,
            20,
            '01J00000000000000000000002',
            'budget_plan_fact',
            ReportSubscriptionFrequency::DAILY,
            null,
            null,
            '10:00',
            new DateTimeZone('UTC'),
            ['mode' => 'previous_day'],
            'xlsx',
            $status,
            null,
            0,
            new DateTimeImmutable('2026-07-27T10:00:00+00:00'),
            $bytes,
            new Sha256Hash(hash('sha256', $bytes)),
            new Sha256Hash(str_repeat('a', 64)),
            '1.0.0',
            $transitionVersion,
            $now,
            $now,
        );
    }
}

final class FakeReportSubscriptionStore implements ReportSubscriptionStore
{
    public function __construct(public ReportSubscription $subscription) {}

    public function getForActor(int $organizationId, int $ownerId, string $id): ReportSubscription
    {
        return $this->subscription;
    }

    public function lock(string $id): ReportSubscription
    {
        return $this->subscription;
    }

    public function selectDueLocked(DateTimeImmutable $now, int $limit): array
    {
        return [];
    }

    public function advanceNextRunLocked(ReportSubscription $subscription, DateTimeImmutable $nextRun): void {}

    public function disableLocked(ReportSubscription $subscription, string $reason): void {}
}

final class FakeReportSubscriptionDeliveryStore implements ReportSubscriptionDeliveryStore
{
    /** @var array<string, ReportSubscriptionDelivery> */
    private array $manualDeliveries = [];

    public function lockWithSubscription(string $id): array
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createCalendarScheduledLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?ReportSubscriptionDelivery {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insertManualScheduledOnConflictLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        Sha256Hash $triggerKeyHash,
        Sha256Hash $manualRequestHash,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?string {
        $key = $subscription->id.':'.$triggerKeyHash->value;

        if (isset($this->manualDeliveries[$key])) {
            return null;
        }

        $id = '01J00000000000000000000003';
        $this->manualDeliveries[$key] = new ReportSubscriptionDelivery(
            $id,
            $subscription->organizationId,
            $subscription->ownerId,
            $subscription->id,
            ReportSubscriptionTrigger::MANUAL,
            $triggerKeyHash,
            $manualRequestHash,
            $scheduledFor,
            $bytes,
            $hash,
            $version,
            ReportSubscriptionDeliveryStatus::SCHEDULED,
            0,
            null,
            null,
            null,
            null,
            null,
            $scheduledFor->modify('+1 day'),
            $scheduledFor->modify('+2 days'),
        );

        return $id;
    }

    public function lockManualByScope(string $subscriptionId, Sha256Hash $triggerKeyHash): ?ReportSubscriptionDelivery
    {
        return $this->manualDeliveries[$subscriptionId.':'.$triggerKeyHash->value] ?? null;
    }

    public function beginAttemptLocked(ReportSubscriptionDelivery $delivery): void {}

    public function attachRunLocked(ReportSubscriptionDelivery $delivery, string $runId): void {}

    public function attachExportLocked(ReportSubscriptionDelivery $delivery, string $exportId): void {}

    public function markReadyLocked(ReportSubscriptionDelivery $delivery): void {}

    public function markNotifiedLocked(ReportSubscriptionDelivery $delivery, string $receiptId, Sha256Hash $key): void {}

    public function rescheduleRetryLocked(ReportSubscriptionDelivery $delivery, DateTimeImmutable $retryAt, string $code): void {}

    public function markFailedLocked(ReportSubscriptionDelivery $delivery, string $code): void {}

    public function markExpiredLocked(ReportSubscriptionDelivery $delivery): void {}

    public function expireExecutionsDueLocked(DateTimeImmutable $now, int $limit): array
    {
        return [];
    }

    public function pruneTerminalDueLocked(DateTimeImmutable $now, int $limit): int
    {
        return 0;
    }
}

final class FakeReportSubscriptionDeliveryDispatcher implements ReportSubscriptionDeliveryDispatcher
{
    /** @var list<array{0:string,1:int}> */
    public array $calls = [];

    public function dispatch(string $deliveryId, int $delaySeconds): void
    {
        $this->calls[] = [$deliveryId, $delaySeconds];
    }
}
