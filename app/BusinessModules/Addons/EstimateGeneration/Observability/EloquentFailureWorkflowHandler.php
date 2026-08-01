<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use Illuminate\Database\Connection;

final readonly class EloquentFailureWorkflowHandler implements FailureWorkflowHandler
{
    public function __construct(
        private AdvanceEstimateGeneration $advance,
        private FailureWorkflowFence $fence,
        private AiEstimateQuotaService $aiEstimateQuota,
        private Connection $database,
    ) {}

    public function handle(FailureData $failure, ?int $expectedStateVersion = null): void
    {
        $this->database->transaction(function () use ($failure, $expectedStateVersion): void {
            $session = EstimateGenerationSession::query()
                ->whereKey($failure->context->sessionId)
                ->where('organization_id', $failure->context->organizationId)
                ->where('project_id', $failure->context->projectId)
                ->lockForUpdate()
                ->first();
            if (! $session instanceof EstimateGenerationSession
                || ($expectedStateVersion !== null && (int) $session->state_version !== $expectedStateVersion)) {
                return;
            }

            $stateVersion = (int) $session->state_version;
            $action = $this->fence->decide($failure, $stateVersion, $session->status);

            match ($action) {
                FailureWorkflowAction::ReviewDocuments => $this->advance->documentsNeedReview($session, $failure->code),
                FailureWorkflowAction::ReviewGeneration => $this->advance->generationNeedsReview($session, $failure->code),
                FailureWorkflowAction::Fail => $this->releaseQuotaAfterFailure($session, $failure, $stateVersion),
                FailureWorkflowAction::None => null,
            };
        }, 3);
    }

    private function releaseQuotaAfterFailure(EstimateGenerationSession $session, FailureData $failure, int $stateVersion): void
    {
        $failed = $this->advance->failed($session, $failure->code);
        $this->aiEstimateQuota->releaseForTerminalTechnicalFailure($failed, $failure, $stateVersion);
    }
}
