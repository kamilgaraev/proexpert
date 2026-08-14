<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

interface EstimateClarificationAnswerRegistry
{
    /** @return list<string> */
    public function answeredKeys(int $organizationId, int $projectId, int $sessionId): array;
}
