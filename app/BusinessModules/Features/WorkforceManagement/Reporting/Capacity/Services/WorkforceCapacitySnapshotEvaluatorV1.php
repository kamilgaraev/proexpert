<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotEvaluator;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacitySnapshot;
use DateTimeImmutable;

final readonly class WorkforceCapacitySnapshotEvaluatorV1 implements WorkforceCapacitySnapshotEvaluator
{
    private WorkforceCapacitySnapshotBuilder $builder;

    public function __construct(WorkforceCapacityPolicyDefinition $policy)
    {
        $this->builder = new WorkforceCapacitySnapshotBuilder($policy);
    }

    public function evaluate(
        WorkforceCapacityCohortKey $key,
        string $captureKind,
        DateTimeImmutable $capturedAt,
        ?int $actorUserId,
        ?string $serviceActor,
        array $source,
    ): WorkforceCapacitySnapshot {
        return $this->builder->build(
            $key,
            $captureKind,
            $capturedAt,
            $actorUserId,
            $serviceActor,
            $source,
        );
    }
}
