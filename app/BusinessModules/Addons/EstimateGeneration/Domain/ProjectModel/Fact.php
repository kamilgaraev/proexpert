<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class Fact
{
    public const ORIGINS = [
        'document',
        'ai_inference',
        'user_assumption',
        'ai_technology_recommendation',
        'unresolved',
    ];

    public const STATUSES = ['candidate', 'confirmed', 'conflicted', 'unresolved', 'invalidated'];

    public array $evidenceIds;

    public function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $entityId,
        public string $type,
        public mixed $value,
        public ?string $unit,
        public float $confidence,
        public string $origin,
        public string $status,
        array $evidenceIds,
        public int $version = 1,
        public ?string $supersedesFactId = null,
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Fact');
        ProjectModelInvariant::id($entityId, 'Fact entity');
        ProjectModelInvariant::id($type, 'Fact type');
        ProjectModelInvariant::confidence($confidence);
        if (! in_array($origin, self::ORIGINS, true) || ! in_array($status, self::STATUSES, true)
            || $version <= 0 || ($unit !== null && trim($unit) === '')) {
            throw new InvalidArgumentException('Project model fact is invalid.');
        }
        if (($origin === 'unresolved' && $status !== 'unresolved')
            || ($origin === 'ai_technology_recommendation' && $status === 'confirmed')) {
            throw new InvalidArgumentException('Project model fact origin and status are inconsistent.');
        }
        if ($status === 'confirmed' && $evidenceIds === [] && $origin !== 'user_assumption') {
            throw new InvalidArgumentException('Confirmed project model fact has no evidence.');
        }
        if ($supersedesFactId !== null) {
            ProjectModelInvariant::id($supersedesFactId, 'Superseded fact');
            if ($supersedesFactId === $id || $version === 1) {
                throw new InvalidArgumentException('Project model fact version chain is invalid.');
            }
        }
        $this->evidenceIds = ProjectModelInvariant::uniqueIds($evidenceIds, 'Evidence', true);
    }
}
