<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class Entity
{
    public const TYPES = ['room', 'wall', 'opening', 'dimension', 'material', 'equipment', 'quantity'];

    public function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $type,
        public string $stableKey,
        public array $attributes = [],
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Entity');
        ProjectModelInvariant::id($stableKey, 'Entity stable key');
        if (! in_array($type, self::TYPES, true) || ($attributes !== [] && array_is_list($attributes))) {
            throw new InvalidArgumentException('Project model entity is invalid.');
        }
    }
}
