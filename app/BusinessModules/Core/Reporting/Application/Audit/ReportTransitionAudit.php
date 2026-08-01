<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Audit;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use DateTimeImmutable;

interface ReportTransitionAudit
{
    public function append(
        string $eventId,
        string $eventType,
        ReportExecutionContext $context,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void;
}
