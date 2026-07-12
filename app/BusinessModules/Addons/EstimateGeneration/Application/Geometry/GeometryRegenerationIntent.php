<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

final readonly class GeometryRegenerationIntent
{
    public string $idempotencyKey;

    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $stateVersion,
        public string $previousInputVersion,
        public string $inputVersion,
        public string $modelVersion,
        public string $generationAttemptId,
    ) {
        $this->idempotencyKey = hash('sha256', implode('|', [$organizationId, $projectId, $sessionId, $stateVersion, $inputVersion, $modelVersion]));
    }
}
