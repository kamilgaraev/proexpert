<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacitySnapshot;
use DateTimeImmutable;

interface WorkforceCapacitySnapshotEvaluator
{
    public function evaluate(
        WorkforceCapacityCohortKey $key,
        string $captureKind,
        DateTimeImmutable $capturedAt,
        ?int $actorUserId,
        ?string $serviceActor,
        array $source,
    ): WorkforceCapacitySnapshot;
}
