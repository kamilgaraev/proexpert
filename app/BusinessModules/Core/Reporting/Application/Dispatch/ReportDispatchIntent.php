<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

use DateTimeImmutable;

final readonly class ReportDispatchIntent
{
    public function __construct(
        public string $id,
        public string $eventKey,
        public int $organizationId,
        public ReportDispatchAggregate $aggregate,
        public string $aggregateId,
        public ReportDispatchTopic $topic,
        public int $attemptCount,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $availableAt,
    ) {}
}
