<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

interface EstimateClarificationCatalog
{
    /** @return list<CurrentEstimateClarification> */
    public function allCurrent(int $organizationId, int $projectId, int $sessionId): array;

    public function currentWorkContexts(int $organizationId, int $projectId, int $sessionId,
        ?\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot $snapshot = null, ?array $decisions = null,
        ?string $localEstimateKey = null): array;
}
