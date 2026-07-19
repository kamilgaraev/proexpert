<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionActionResult;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentSessionBuildingModelBridge;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Str;

final class RequestEstimateGeneration
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private AdvanceEstimateGeneration $advance,
        private DocumentGenerationReadinessService $readiness,
        private EloquentSessionBuildingModelBridge $buildingModels,
    ) {}

    public function handle(EstimateGenerationSession $session, int $expectedVersion, ?string $requestedMode): SessionActionResult
    {
        if ($session->status === EstimateGenerationStatus::Generating) {
            if (trim((string) ($session->input_payload['generation_attempt_id'] ?? '')) === '') {
                throw new InvalidEstimateGenerationState($session->status, 'generation_retry_without_attempt');
            }

            return new SessionActionResult($session, true, 'estimate_generation.generation_queued', 202);
        }

        $this->policy->generate($session, $expectedVersion);
        $generationMode = EstimateGenerationMode::fromInput(
            $requestedMode ?? ($session->input_payload['generation_mode'] ?? null),
        )->value;
        $generationInput = [];
        if (($session->input_payload['generation_mode'] ?? null) !== $generationMode) {
            if ($session->status === EstimateGenerationStatus::Applied) {
                $generationInput['generation_mode'] = $generationMode;
            } else {
                $session = $this->advance->update($session, [
                    EstimateGenerationStatus::Draft,
                    EstimateGenerationStatus::ProcessingDocuments,
                    EstimateGenerationStatus::ReadyToGenerate,
                    EstimateGenerationStatus::EstimateReviewRequired,
                    EstimateGenerationStatus::ReadyToApply,
                ], ['input_payload' => [...($session->input_payload ?? []), 'generation_mode' => $generationMode]]);
            }
        }

        $readiness = $this->readiness->evaluate($session->load('documents'));
        if (! $readiness['can_generate']) {
            if ($this->canWait($session, $readiness['summary'])) {
                $session = $this->advance->documentsStarted($session, ['input_payload' => [
                    ...($session->input_payload ?? []),
                    'generation_requested' => true,
                ]]);

                return new SessionActionResult($session, true, 'estimate_generation.generation_documents_pending', 202);
            }

            return new SessionActionResult($session, false, $readiness['blocking_message_key'] ?? 'estimate_generation.documents_require_action', 409, ['documents_summary' => $readiness['summary']]);
        }
        if (! $this->hasInput($session, $readiness['summary'])) {
            return new SessionActionResult($session, false, 'estimate_generation.input_required', 422, ['documents_summary' => $readiness['summary']]);
        }

        $this->buildingModels->rebuild((int) $session->getKey());
        $session = $this->advance->documentsReady($session);
        $attemptId = (string) Str::uuid();
        $session = $this->advance->generationStarted($session, $attemptId, $generationInput);
        GenerateEstimateDraftJob::dispatch(
            (int) $session->getKey(),
            $session->state_version,
            $attemptId,
            FailureExecutionSnapshot::capture($session, 'generate_draft', $attemptId),
        )
            ->onQueue(GenerateEstimateDraftJob::QUEUE)
            ->afterCommit();

        return new SessionActionResult($session, true, 'estimate_generation.generation_queued', 202);
    }

    /** @param array<string, mixed> $summary */
    private function hasInput(EstimateGenerationSession $session, array $summary): bool
    {
        return trim((string) ($session->input_payload['description'] ?? '')) !== '' || (int) ($summary['ready_count'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $summary */
    private function canWait(EstimateGenerationSession $session, array $summary): bool
    {
        return (int) ($summary['pending_count'] ?? 0) > 0
            && (int) ($summary['action_required_count'] ?? 0) === 0
            && (trim((string) ($session->input_payload['description'] ?? '')) !== '' || (int) ($summary['total'] ?? 0) > 0);
    }
}
