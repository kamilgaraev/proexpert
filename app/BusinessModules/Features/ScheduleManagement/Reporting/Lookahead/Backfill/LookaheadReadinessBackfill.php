<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Backfill;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\WorkConstraintEventBackfill;

final readonly class LookaheadReadinessBackfill
{
    public function __construct(private WorkConstraintEventBackfill $backfill)
    {
    }

    public function run(int $organizationId, array $projectIds): array
    {
        return $this->backfill->run($organizationId, $projectIds);
    }
}
