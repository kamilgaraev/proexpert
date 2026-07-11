<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface RetryableEstimateGenerationSessionRepository
{
    /** @param callable(EstimateGenerationSession): EstimateGenerationSession $operation */
    public function withLockedSession(
        int $sessionId,
        int $organizationId,
        int $projectId,
        callable $operation,
    ): EstimateGenerationSession;
}
