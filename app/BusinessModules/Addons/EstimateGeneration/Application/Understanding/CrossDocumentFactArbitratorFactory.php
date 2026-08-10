<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

interface CrossDocumentFactArbitratorFactory
{
    public function create(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $checkpointClaimToken,
        int $logicalAttempt,
    ): CrossDocumentFactArbitrator;
}
