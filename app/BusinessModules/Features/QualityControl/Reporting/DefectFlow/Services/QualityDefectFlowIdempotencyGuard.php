<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use LogicException;

final class QualityDefectFlowIdempotencyGuard
{
    public function exactReplay(string $eventId, string $existingSourceHash, string $expectedSourceHash): string
    {
        if (! hash_equals($existingSourceHash, $expectedSourceHash)) {
            throw new LogicException('quality_defect_flow_idempotency_conflict');
        }

        return $eventId;
    }
}
