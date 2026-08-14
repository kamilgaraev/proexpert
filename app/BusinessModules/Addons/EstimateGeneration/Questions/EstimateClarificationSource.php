<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

interface EstimateClarificationSource
{
    public function findCurrent(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $questionKey,
    ): ?CurrentEstimateClarification;
}
