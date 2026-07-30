<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use InvalidArgumentException;

final readonly class WorkConstraintTransitionGuard
{
    public function assertCanAppend(?string $expectedFromStatus, ?string $actualTailStatus): void
    {
        if ($expectedFromStatus !== $actualTailStatus) {
            throw new InvalidArgumentException('work_constraint_event_stale_transition');
        }
    }
}
