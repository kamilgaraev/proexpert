<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ResetDocumentProcessingUnitsForAttempt;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Support\Str;

final class LaravelEstimateGenerationRetryDispatcher implements EstimateGenerationRetryDispatcher
{
    public function __construct(private readonly ResetDocumentProcessingUnitsForAttempt $resetter) {}

    public function dispatchDocuments(array $documentIds): void
    {
        foreach (array_values(array_unique($documentIds)) as $documentId) {
            $document = EstimateGenerationDocument::query()->with('session')->find($documentId);
            if (! $document instanceof EstimateGenerationDocument || ! $document->session instanceof EstimateGenerationSession) {
                continue;
            }
            $attemptId = (string) Str::uuid();
            $meta = is_array($document->meta) ? $document->meta : [];
            $oldAttemptId = is_string($meta['processing_attempt_id'] ?? null)
                ? $meta['processing_attempt_id']
                : null;
            $sourceVersion = DocumentSourceVersion::fromDocument($document);
            $preservedReady = $this->resetter->handle($document, $sourceVersion, $oldAttemptId, $attemptId);
            $includedPages = max(0, (int) $document->page_count);
            $executionProgress = $includedPages === 0
                ? 0
                : (int) floor(($preservedReady / $includedPages) * 100);
            $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
            $document->forceFill([
                'status' => 'queued',
                'processing_stage' => 'stored',
                'progress_percent' => $executionProgress,
                'ocr_started_at' => null,
                'ocr_finished_at' => null,
                'error_code' => null,
                'error_message_key' => null,
                'error_context' => null,
                'processed_page_count' => $preservedReady,
                'facts_summary' => [
                    ...$factsSummary,
                    'processing_outcome' => [
                        'type' => 'processing',
                        'counts' => [
                            'included' => $includedPages,
                            'ready' => $preservedReady,
                            'needs_user_action' => 0,
                            'terminal_system_failed' => 0,
                            'breaker_stopped' => 0,
                            'system_failed' => 0,
                            'processing' => max(0, $includedPages - $preservedReady),
                            'excluded' => 0,
                        ],
                        'retry_allowed' => false,
                        'execution_progress_percent' => $executionProgress,
                        'readiness' => 'processing',
                        'is_ready' => false,
                    ],
                ],
                'units_finalized_source_version' => null,
                'units_reconciled_source_version' => null,
                'units_reconcile_claim_token' => null,
                'units_reconcile_lease_expires_at' => null,
                'meta' => [
                    ...$meta,
                    'processing_attempt_id' => $attemptId,
                ],
            ])->saveQuietly();
            ProcessEstimateGenerationDocumentJob::dispatch(
                $documentId,
                FailureExecutionSnapshot::capture(
                    $document->session,
                    'document_manifest',
                    attemptId: $attemptId,
                    documentId: (int) $document->getKey(),
                    sourceVersion: $sourceVersion,
                ),
            )
                ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                ->afterCommit();
        }
    }

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): bool
    {
        $session = EstimateGenerationSession::query()->find($sessionId);
        if (! $session instanceof EstimateGenerationSession || (int) $session->state_version !== $stateVersion) {
            return false;
        }
        GenerateEstimateDraftJob::dispatch(
            $sessionId,
            $stateVersion,
            $attemptId,
            FailureExecutionSnapshot::capture($session, 'generate_draft', $attemptId),
        )
            ->onQueue(GenerateEstimateDraftJob::QUEUE)
            ->afterCommit();

        return true;
    }
}
