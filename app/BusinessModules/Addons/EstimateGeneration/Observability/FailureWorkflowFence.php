<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;

final class FailureWorkflowFence
{
    public function decide(FailureData $failure, int $currentVersion, EstimateGenerationStatus $currentStatus): FailureWorkflowAction
    {
        if ($failure->context->expectedSessionStateVersion === null
            || $failure->context->expectedSessionStatus === null
            || $failure->context->expectedSessionStateVersion !== $currentVersion
            || $failure->context->expectedSessionStatus !== $currentStatus->value
            || $currentStatus->isTerminal()
            || $currentStatus === EstimateGenerationStatus::Failed
            || $failure->category === FailureCategory::Recoverable) {
            return FailureWorkflowAction::None;
        }

        if ($failure->category === FailureCategory::Terminal) {
            return in_array($currentStatus, [
                EstimateGenerationStatus::ProcessingDocuments,
                EstimateGenerationStatus::Generating,
                EstimateGenerationStatus::Applying,
            ], true) ? FailureWorkflowAction::Fail : FailureWorkflowAction::None;
        }

        return match ($currentStatus) {
            EstimateGenerationStatus::ProcessingDocuments => FailureWorkflowAction::ReviewDocuments,
            EstimateGenerationStatus::Generating => FailureWorkflowAction::ReviewGeneration,
            default => FailureWorkflowAction::None,
        };
    }
}
