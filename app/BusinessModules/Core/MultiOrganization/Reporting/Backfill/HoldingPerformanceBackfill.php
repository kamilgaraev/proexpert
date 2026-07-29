<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Backfill;

use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;

final readonly class HoldingPerformanceBackfill
{
    public function __construct(private HoldingAllocationFactProjector $projector) {}

    public function projectSlice(iterable $sourceRows): array
    {
        $ids = [];
        foreach ($sourceRows as $source) {
            if (! is_array($source)) {
                continue;
            }
            $missing = $this->projector->missingEvidence($source);
            if ($missing !== []) {
                $this->projector->recordGap($source, $missing);

                continue;
            }
            $fact = $this->projector->project($source);
            $record = $this->projector->persist($fact, $source);
            $ids[] = (int) $record->getKey();
        }

        return $ids;
    }
}
