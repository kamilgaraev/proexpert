<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntentLease;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use DateTimeImmutable;

interface ReportAuditIntentStore
{
    public function add(string $eventKey, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void;

    public function dueIds(int $limit, DateTimeImmutable $now): array;

    public function claim(string $intentId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leasedUntil): ?ReportAuditIntentLease;

    public function loadLeased(string $intentId, string $leaseToken): ReportAuditIntent;

    public function acknowledge(string $intentId, string $leaseToken, DateTimeImmutable $deliveredAt): void;

    public function failDelivery(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void;

    public function reclaimExpired(int $limit, DateTimeImmutable $occurredAt): int;
}
