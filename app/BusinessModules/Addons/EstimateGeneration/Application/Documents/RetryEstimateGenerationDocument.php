<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class RetryEstimateGenerationDocument
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private ReconcileEstimateGenerationDocuments $reconciler,
        private DocumentGenerationReadinessService $readiness,
    ) {}

    public function handle(EstimateGenerationSession $session, EstimateGenerationDocument $document, int $expectedVersion, ?string $reason): DocumentActionResult
    {
        [$session, $document] = DB::transaction(function () use ($session, $document, $expectedVersion, $reason): array {
            $lockedSession = EstimateGenerationSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $lockedDocument = EstimateGenerationDocument::query()
                ->where('organization_id', $lockedSession->organization_id)
                ->where('project_id', $lockedSession->project_id)
                ->where('session_id', $lockedSession->id)
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            $this->policy->documents($lockedSession, $expectedVersion);
            if (! in_array((string) $lockedDocument->status, ['ready', 'failed', 'needs_review', 'ignored'], true)) {
                throw ValidationException::withMessages(['document' => [trans_message('estimate_generation.document_retry_not_allowed')]]);
            }

            $lockedDocument->pages()->delete();
            $lockedDocument->processingUnits()->delete();
            $lockedDocument->forceFill([
                'status' => 'queued',
                'processing_stage' => 'stored',
                'progress_percent' => 0,
                'ocr_started_at' => null,
                'ocr_finished_at' => null,
                'error_code' => null,
                'error_message_key' => null,
                'error_context' => null,
                'ignored_at' => null,
                'extracted_text' => null,
                'structured_payload' => [],
                'processed_page_count' => 0,
                'meta' => [
                    ...(is_array($lockedDocument->meta) ? $lockedDocument->meta : []),
                    'retry_requested_at' => now()->toISOString(),
                    'retry_reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                ],
            ])->save();

            return [$this->reconciler->changed($lockedSession), $lockedDocument];
        }, 3);

        ProcessEstimateGenerationDocumentJob::dispatch((int) $document->getKey())
            ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
            ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
            ->afterCommit();
        $session = $session->fresh(['documents']) ?? $session;

        return new DocumentActionResult(
            $document->fresh() ?? $document,
            $this->readiness->evaluate($session)['summary'],
            'estimate_generation.document_retry_queued',
        );
    }
}
