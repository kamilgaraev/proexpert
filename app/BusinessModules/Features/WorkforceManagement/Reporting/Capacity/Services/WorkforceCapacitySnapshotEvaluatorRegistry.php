<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacitySnapshotEvaluator;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityFrozenCapturePins;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use LogicException;

final readonly class WorkforceCapacitySnapshotEvaluatorRegistry
{
    public function resolve(
        string $sourceSchemaVersion,
        string $formulaVersion,
        WorkforceCapacityPolicyDefinition $policy,
    ): WorkforceCapacitySnapshotEvaluator {
        if ($sourceSchemaVersion === WorkforceCapacityFrozenCapturePins::SOURCE_SCHEMA_VERSION
            && $formulaVersion === WorkforceCapacityFrozenCapturePins::FORMULA_VERSION) {
            return new WorkforceCapacitySnapshotEvaluatorV1($policy);
        }

        throw new LogicException('workforce_capacity_evaluator_version_unknown');
    }
}
