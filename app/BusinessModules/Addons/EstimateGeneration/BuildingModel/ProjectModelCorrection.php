<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelCorrection
{
    public function __construct(
        public int $buildingModelId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $stableKey,
        public string $assertionStableKey,
        public string $correctionType,
        public array $payload,
        public string $reason,
        public int $actorId,
    ) {
        if ($buildingModelId < 1) {
            throw new InvalidArgumentException('Project model correction building model identifier must be positive.');
        }
        ProjectModelEntity::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelEntity::assertStableKey($stableKey, 'Correction');
        ProjectModelEntity::assertStableKey($assertionStableKey, 'Correction assertion');
        if (! in_array($correctionType, ['manual', 'source_reconciliation'], true)) {
            throw new InvalidArgumentException('Project model correction type is invalid.');
        }
        ProjectModelEntity::assertObject($payload, 'Correction payload');
        if (trim($reason) === '' || mb_strlen($reason) > 1000 || $actorId < 1) {
            throw new InvalidArgumentException('Project model correction audit data is invalid.');
        }
    }
}
