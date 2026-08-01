<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use DateTimeImmutable;

final readonly class ReportAuditIntent
{
    public function __construct(
        public string $id,
        public string $eventKey,
        public string $eventType,
        public int $organizationId,
        public int $actorId,
        public array $subject,
        public int $attemptCount,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $availableAt,
    ) {}
}
