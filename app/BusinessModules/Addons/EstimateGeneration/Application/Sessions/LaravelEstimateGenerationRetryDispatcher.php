<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;

final class LaravelEstimateGenerationRetryDispatcher implements EstimateGenerationRetryDispatcher
{
    public function dispatchDocuments(array $documentIds): void
    {
        foreach (array_values(array_unique($documentIds)) as $documentId) {
            ProcessEstimateGenerationDocumentJob::dispatch($documentId)
                ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                ->afterCommit();
        }
    }

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): void
    {
        GenerateEstimateDraftJob::dispatch($sessionId, $stateVersion, $attemptId)
            ->onQueue(GenerateEstimateDraftJob::QUEUE)
            ->afterCommit();
    }
}
