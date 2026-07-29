<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Backfill;

final readonly class IntercompanyContractFlowBackfill
{
    public function __construct(private HoldingPerformanceBackfill $facts)
    {
    }

    public function projectSlice(iterable $sourceRows): array
    {
        return $this->facts->projectSlice($sourceRows);
    }
}
