<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Str;

final class ReconcileEstimateGenerationDocuments
{
    public function __construct(
        private AdvanceEstimateGeneration $advance,
        private DocumentGenerationReadinessService $readiness,
    ) {}

    public function changed(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $session = $session->fresh(['documents']) ?? $session;
        if ($session->status === EstimateGenerationStatus::Failed
            && $session->resume_status === EstimateGenerationStatus::ProcessingDocuments) {
            return $this->advance->documentsChanged($session);
        }
        if (in_array($session->status, [
            EstimateGenerationStatus::InputReviewRequired,
            EstimateGenerationStatus::ReadyToGenerate,
            EstimateGenerationStatus::Generating,
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
        ], true)) {
            return $this->advance->documentsChanged($session);
        }

        return $this->advance->documentsStarted($session);
    }

    public function assertMutable(EstimateGenerationSession $session): void
    {
        if ($session->status === EstimateGenerationStatus::Failed
            && $session->resume_status === EstimateGenerationStatus::ProcessingDocuments) {
            return;
        }
        if (! in_array($session->status, [
            EstimateGenerationStatus::Draft,
            EstimateGenerationStatus::ProcessingDocuments,
            EstimateGenerationStatus::InputReviewRequired,
            EstimateGenerationStatus::ReadyToGenerate,
            EstimateGenerationStatus::Generating,
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
        ], true)) {
            throw new InvalidEstimateGenerationTransition(
                $session->status,
                EstimateGenerationEvent::DocumentsChanged,
            );
        }
    }

    public function reconcile(EstimateGenerationSession $session): EstimateGenerationSession
    {
        $session = $session->fresh(['documents']) ?? $session;
        if ($session->status !== EstimateGenerationStatus::ProcessingDocuments) {
            return $session;
        }

        $readiness = $this->readiness->evaluate($session);
        if (! $readiness['can_generate']) {
            if ((int) ($readiness['summary']['pending_count'] ?? 0) === 0
                && (int) ($readiness['summary']['action_required_count'] ?? 0) > 0) {
                return $this->advance->documentsNeedReview($session);
            }

            return $session;
        }

        $session = $this->advance->documentsReady($session);
        if (($session->input_payload['generation_requested'] ?? false) !== true) {
            return $session;
        }

        $attemptId = (string) Str::uuid();
        $session = $this->advance->generationStarted($session, $attemptId);
        GenerateEstimateDraftJob::dispatch(
            (int) $session->getKey(),
            $session->state_version,
            $attemptId,
            FailureExecutionSnapshot::capture($session, 'generate_draft', $attemptId),
        )
            ->onQueue(GenerateEstimateDraftJob::QUEUE)
            ->afterCommit();

        return $session;
    }
}
