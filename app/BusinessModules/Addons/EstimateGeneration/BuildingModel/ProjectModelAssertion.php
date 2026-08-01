<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelAssertion
{
    public function __construct(
        public int $buildingModelId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $stableKey,
        public string $entityStableKey,
        public string $assertionType,
        public array $payload,
        public float $confidence,
    ) {
        if ($buildingModelId < 1) {
            throw new InvalidArgumentException('Project model assertion building model identifier must be positive.');
        }
        ProjectModelEntity::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelEntity::assertStableKey($stableKey, 'Assertion');
        ProjectModelEntity::assertStableKey($entityStableKey, 'Assertion entity');
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $assertionType) !== 1) {
            throw new InvalidArgumentException('Project model assertion type is invalid.');
        }
        ProjectModelEntity::assertObject($payload, 'Assertion payload');
        ProjectModelEntity::assertConfidence($confidence);
    }
}
