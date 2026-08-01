<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelEvidenceBinding
{
    public function __construct(
        public int $buildingModelId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $entityStableKey,
        public int $evidenceId,
        public string $evidenceSourceVersion,
        public int $evidenceInvalidationVersion,
    ) {
        if ($buildingModelId < 1 || $evidenceId < 1 || $evidenceInvalidationVersion < 0) {
            throw new InvalidArgumentException('Project model evidence binding identifiers are invalid.');
        }
        ProjectModelEntity::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelEntity::assertStableKey($entityStableKey, 'Evidence binding entity');
        if ($evidenceSourceVersion === '' || mb_strlen($evidenceSourceVersion) > 80) {
            throw new InvalidArgumentException('Project model evidence source version is invalid.');
        }
    }
}
