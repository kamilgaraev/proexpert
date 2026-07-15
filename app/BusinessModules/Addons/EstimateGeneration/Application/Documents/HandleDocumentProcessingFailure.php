<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use Throwable;

final readonly class HandleDocumentProcessingFailure
{
    public function __construct(
        private FailureRecorder $failures,
        private FailDocumentProcessing $documents,
    ) {}

    public function handle(
        int $documentId,
        FailureExecutionSnapshot $snapshot,
        Throwable $error,
        int $attempt,
    ): void {
        $document = EstimateGenerationDocument::query()->with('session')->find($documentId);
        if (! $document instanceof EstimateGenerationDocument
            || in_array($document->status, ['ready', 'ignored'], true)
            || ! $document->session instanceof EstimateGenerationSession
            || (int) $document->session->state_version !== $snapshot->stateVersion
            || $document->session->status->value !== $snapshot->status
            || (int) $document->organization_id !== $snapshot->organizationId
            || (int) $document->project_id !== $snapshot->projectId
            || (int) $document->session_id !== $snapshot->sessionId) {
            return;
        }

        $failure = $this->failures->capture($error, new FailureContext(
            organizationId: $snapshot->organizationId,
            projectId: $snapshot->projectId,
            sessionId: $snapshot->sessionId,
            stage: ProcessingStage::UnderstandDocuments,
            operation: 'create_units',
            attempt: max(1, $attempt),
            correlationId: $snapshot->correlationId,
            eventId: $snapshot->eventId,
            expectedSessionStateVersion: $snapshot->stateVersion,
            expectedSessionStatus: $snapshot->status,
            documentId: (int) $document->getKey(),
        ));

        try {
            $this->documents->handle(
                $snapshot,
                $failure->code,
                ['failure_fingerprint' => $failure->fingerprint],
            );
        } catch (Throwable) {
        }
    }
}
