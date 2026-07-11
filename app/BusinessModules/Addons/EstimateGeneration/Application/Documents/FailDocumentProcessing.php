<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SensitiveDiagnosticSanitizer;
use Illuminate\Database\Connection;

final readonly class FailDocumentProcessing
{
    public function __construct(
        private Connection $database,
        private ReconcileEstimateGenerationDocuments $sessions,
        private SensitiveDiagnosticSanitizer $diagnostics,
    ) {}

    /** @param array<string, mixed> $context */
    public function handle(FailureExecutionSnapshot $snapshot, string $errorCode, array $context = []): void
    {
        $session = $this->database->transaction(function () use ($snapshot, $errorCode, $context): ?EstimateGenerationSession {
            $document = EstimateGenerationDocument::query()->with('session')->lockForUpdate()->find($snapshot->documentId);
            if (! $document instanceof EstimateGenerationDocument
                || ! $document->session instanceof EstimateGenerationSession
                || (int) $document->organization_id !== $snapshot->organizationId
                || (int) $document->project_id !== $snapshot->projectId
                || (int) $document->session_id !== $snapshot->sessionId
                || (string) $document->source_version !== (string) $snapshot->sourceVersion
                || (int) $document->session->state_version !== $snapshot->stateVersion
                || $document->session->status->value !== $snapshot->status
                || in_array((string) $document->status, ['ready', 'ignored'], true)) {
                return null;
            }

            $document->forceFill([
                'status' => 'failed',
                'processing_stage' => 'completed',
                'progress_percent' => 100,
                'error_code' => $errorCode,
                'error_message_key' => 'estimate_generation.ocr_provider_error',
                'error_context' => $this->diagnostics->sanitize($context),
                'ocr_finished_at' => now(),
            ])->save();

            return $document->session;
        }, 3);

        if ($session instanceof EstimateGenerationSession) {
            $this->sessions->reconcile($session);
        }
    }
}
