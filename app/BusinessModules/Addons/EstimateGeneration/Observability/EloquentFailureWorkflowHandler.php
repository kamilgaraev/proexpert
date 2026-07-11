<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class EloquentFailureWorkflowHandler implements FailureWorkflowHandler
{
    public function __construct(private AdvanceEstimateGeneration $advance, private FailureWorkflowFence $fence) {}

    public function handle(FailureData $failure, ?int $expectedStateVersion = null): void
    {
        $session = EstimateGenerationSession::query()
            ->whereKey($failure->context->sessionId)
            ->where('organization_id', $failure->context->organizationId)
            ->where('project_id', $failure->context->projectId)
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || ($expectedStateVersion !== null && (int) $session->state_version !== $expectedStateVersion)) {
            return;
        }

        match ($this->fence->decide($failure, (int) $session->state_version, $session->status)) {
            FailureWorkflowAction::ReviewDocuments => $this->advance->documentsNeedReview($session, $failure->code),
            FailureWorkflowAction::ReviewGeneration => $this->advance->generationNeedsReview($session, $failure->code),
            FailureWorkflowAction::Fail => $this->advance->failed($session, $failure->code),
            FailureWorkflowAction::None => null,
        };
    }
}
