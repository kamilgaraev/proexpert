<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class Decision
{
    public array $evidenceIds;

    public function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $targetType,
        public string $targetId,
        public ?string $selectedFactId,
        public string $actorType,
        public string $actorId,
        public string $reason,
        public int $version,
        array $evidenceIds = [],
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Decision');
        ProjectModelInvariant::id($targetId, 'Decision target');
        if (preg_match('/^[a-zA-Z0-9._:-]{1,191}$/D', $actorId) !== 1) {
            throw new InvalidArgumentException('Project model decision actor is invalid.');
        }
        if ($selectedFactId !== null) {
            ProjectModelInvariant::id($selectedFactId, 'Decision fact');
        }
        if (! in_array($targetType, ['fact', 'conflict'], true)
            || ! in_array($actorType, ['user', 'system'], true)
            || trim($reason) === '' || strlen($reason) > 1000 || $version <= 0) {
            throw new InvalidArgumentException('Project model decision is invalid.');
        }
        $this->evidenceIds = ProjectModelInvariant::uniqueIds($evidenceIds, 'Decision evidence', true);
    }
}
