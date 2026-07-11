<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class EloquentFailureWorkflowHandler implements FailureWorkflowHandler
{
    public function __construct(private AdvanceEstimateGeneration $advance) {}

    public function handle(FailureData $failure, ?int $expectedStateVersion = null): void
    {
        if ($failure->category === FailureCategory::Recoverable) {
            return;
        }

        $session = EstimateGenerationSession::query()
            ->whereKey($failure->context->sessionId)
            ->where('organization_id', $failure->context->organizationId)
            ->where('project_id', $failure->context->projectId)
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || ($expectedStateVersion !== null && (int) $session->state_version !== $expectedStateVersion)
            || $session->status->isTerminal()
            || $session->status === EstimateGenerationStatus::Failed) {
            return;
        }

        if ($failure->category === FailureCategory::Terminal) {
            $this->advance->failed($session, $failure->code);

            return;
        }

        match ($session->status) {
            EstimateGenerationStatus::ProcessingDocuments => $this->advance->documentsNeedReview($session, $failure->code),
            EstimateGenerationStatus::Generating => $this->advance->generationNeedsReview($session, $failure->code),
            default => null,
        };
    }
}
