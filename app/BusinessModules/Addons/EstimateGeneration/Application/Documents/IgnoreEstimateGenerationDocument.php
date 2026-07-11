<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class IgnoreEstimateGenerationDocument
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private ReconcileEstimateGenerationDocuments $reconciler,
        private DocumentGenerationReadinessService $readiness,
    ) {}

    public function handle(EstimateGenerationSession $session, EstimateGenerationDocument $document, int $expectedVersion, ?string $reason): DocumentActionResult
    {
        [$session, $document] = DB::transaction(function () use ($session, $document, $expectedVersion, $reason): array {
            $lockedSession = EstimateGenerationSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $this->policy->documents($lockedSession, $expectedVersion);
            $lockedDocument = EstimateGenerationDocument::query()
                ->whereKey($document->getKey())
                ->where('session_id', $lockedSession->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array((string) $lockedDocument->status, ['ready', 'failed', 'needs_review'], true)) {
                throw ValidationException::withMessages(['document' => [trans_message('estimate_generation.document_ignore_not_allowed')]]);
            }
            $lockedDocument->forceFill([
                'status' => 'ignored', 'processing_stage' => 'completed', 'progress_percent' => 100,
                'ignored_at' => now(),
                'meta' => [...(is_array($lockedDocument->meta) ? $lockedDocument->meta : []),
                    'ignored_reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    'ignored_at' => now()->toISOString()],
            ])->save();
            $changedSession = $this->reconciler->changed($lockedSession);
            $reconciledSession = $this->reconciler->reconcile($changedSession);

            return [$reconciledSession, $lockedDocument];
        });
        $session = $session->fresh(['documents']) ?? $session;

        return new DocumentActionResult($document->fresh() ?? $document, $this->readiness->evaluate($session)['summary'], 'estimate_generation.document_ignored');
    }
}
