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
            $session = EstimateGenerationSession::query()->where('organization_id', $snapshot->organizationId)
                ->where('project_id', $snapshot->projectId)->whereKey($snapshot->sessionId)->lockForUpdate()->first();
            if (! $session instanceof EstimateGenerationSession || (int) $session->state_version !== $snapshot->stateVersion
                || $session->status->value !== $snapshot->status || $session->status->isTerminal()) {
                return null;
            }
            $document = EstimateGenerationDocument::query()->where('organization_id', $snapshot->organizationId)
                ->where('project_id', $snapshot->projectId)->where('session_id', $snapshot->sessionId)->lockForUpdate()->find($snapshot->documentId);
            if ($document instanceof EstimateGenerationDocument) {
                $document->setRelation('session', $session);
            }
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
            $lineage = $document->meta['processing_attempt_id'] ?? null;
            if (is_string($lineage) && $lineage !== '' && ! hash_equals($lineage, $snapshot->attemptId)) {
                return null;
            }

            if (config('estimate-generation.credits.enabled', false)) {
                app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditSourceInvalidation::class)
                    ->handle($session, 'document_failed');
                $session->save();
            }

            $document->forceFill([
                'status' => 'failed',
                'processing_stage' => 'completed',
                'progress_percent' => 100,
                'error_code' => $errorCode,
                'error_message_key' => 'estimate_generation.ocr_provider_error',
                'error_context' => $this->diagnostics->sanitize($context),
                'ocr_finished_at' => now(),
                'processing_control_status' => 'cancelled',
                'processing_control_reason' => 'document_failed',
                'processing_control_at' => now(),
            ])->save();

            return $document->session;
        }, 3);

        if ($session instanceof EstimateGenerationSession) {
            $this->sessions->reconcile($session);
        }
    }
}
