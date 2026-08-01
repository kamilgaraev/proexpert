<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityEvidenceItem;

final readonly class WorkforceCapacityEvidenceBulkPersistence
{
    public function row(WorkforceCapacityEvidenceItem $item, int $position): array
    {
        $row = $item->toPersistence($position);
        $row['lineage'] = json_encode($item->lineage, JSON_THROW_ON_ERROR);
        $row['evidence'] = json_encode($item->evidence, JSON_THROW_ON_ERROR);

        return $row;
    }
}
