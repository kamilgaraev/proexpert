<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

final class LaravelEstimateGenerationRetryDispatcher implements EstimateGenerationRetryDispatcher
{
    public function dispatchDocuments(array $documentIds): void
    {
        foreach (array_values(array_unique($documentIds)) as $documentId) {
            $document = EstimateGenerationDocument::query()->with('session')->find($documentId);
            if (! $document instanceof EstimateGenerationDocument || ! $document->session instanceof EstimateGenerationSession) {
                continue;
            }
            ProcessEstimateGenerationDocumentJob::dispatch(
                $documentId,
                FailureExecutionSnapshot::capture($document->session, 'document_manifest'),
            )
                ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                ->afterCommit();
        }
    }

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): void
    {
        $session = EstimateGenerationSession::query()->find($sessionId);
        if (! $session instanceof EstimateGenerationSession || (int) $session->state_version !== $stateVersion) {
            return;
        }
        GenerateEstimateDraftJob::dispatch(
            $sessionId,
            $stateVersion,
            $attemptId,
            FailureExecutionSnapshot::capture($session, 'generate_draft', $attemptId),
        )
            ->onQueue(GenerateEstimateDraftJob::QUEUE)
            ->afterCommit();
    }
}
