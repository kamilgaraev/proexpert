<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

interface EstimateGenerationRetryDispatcher
{
    /** @param list<int> $documentIds */
    public function dispatchDocuments(array $documentIds): void;

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): void;
}
