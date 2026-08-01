<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;

interface ReportDispatchIntentStore
{
    public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void;

    public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void;

    public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array;

    public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void;

    public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void;

    public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int;
}
