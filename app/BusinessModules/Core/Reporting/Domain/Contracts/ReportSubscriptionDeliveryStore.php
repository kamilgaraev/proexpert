<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;

interface ReportSubscriptionDeliveryStore
{
    /** @return array{0:ReportSubscriptionDelivery,1:ReportSubscription} */
    public function lockWithSubscription(string $id): array;

    public function createCalendarScheduledLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?ReportSubscriptionDelivery;

    public function insertManualScheduledOnConflictLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        Sha256Hash $triggerKeyHash,
        Sha256Hash $manualRequestHash,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?string;

    public function lockManualByScope(string $subscriptionId, Sha256Hash $triggerKeyHash): ?ReportSubscriptionDelivery;

    public function beginAttemptLocked(ReportSubscriptionDelivery $delivery): void;

    public function attachRunLocked(ReportSubscriptionDelivery $delivery, string $runId): void;

    public function attachExportLocked(ReportSubscriptionDelivery $delivery, string $exportId): void;

    public function markReadyLocked(ReportSubscriptionDelivery $delivery): void;

    public function markNotifiedLocked(ReportSubscriptionDelivery $delivery, string $receiptId, Sha256Hash $key): void;

    public function rescheduleRetryLocked(ReportSubscriptionDelivery $delivery, DateTimeImmutable $retryAt, string $code): void;

    public function markFailedLocked(ReportSubscriptionDelivery $delivery, string $code): void;

    public function markExpiredLocked(ReportSubscriptionDelivery $delivery): void;

    /** @return list<string> */
    public function expireExecutionsDueLocked(DateTimeImmutable $now, int $limit): array;

    public function pruneTerminalDueLocked(DateTimeImmutable $now, int $limit): int;
}
