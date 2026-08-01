<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelRelation
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $stableKey,
        public string $fromEntityStableKey,
        public string $toEntityStableKey,
        public string $relationType,
        public array $payload,
    ) {
        ProjectModelEntity::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelEntity::assertStableKey($stableKey, 'Relation');
        ProjectModelEntity::assertStableKey($fromEntityStableKey, 'Relation source entity');
        ProjectModelEntity::assertStableKey($toEntityStableKey, 'Relation target entity');
        if ($fromEntityStableKey === $toEntityStableKey) {
            throw new InvalidArgumentException('Project model relation cannot reference the same entity twice.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $relationType) !== 1) {
            throw new InvalidArgumentException('Project model relation type is invalid.');
        }
        ProjectModelEntity::assertObject($payload, 'Relation payload');
    }
}
