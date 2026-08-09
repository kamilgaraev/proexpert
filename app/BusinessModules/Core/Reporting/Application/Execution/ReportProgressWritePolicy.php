<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use DateTimeImmutable;

final class ReportProgressWritePolicy
{
    public function shouldPersist(
        ReportProgress $persisted,
        ReportProgress $current,
        DateTimeImmutable $persistedAt,
        DateTimeImmutable $occurredAt,
    ): bool {
        return $current->percent() < 100
            && $current->percent() >= $persisted->percent() + 1
            && ($current->percent() >= $persisted->percent() + 5
                || $occurredAt >= $persistedAt->modify('+5 seconds'));
    }
}
