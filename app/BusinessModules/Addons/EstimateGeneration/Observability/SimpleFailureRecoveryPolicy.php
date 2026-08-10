<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;

final class SimpleFailureRecoveryPolicy
{
    public function decide(
        FailureData $failure,
        int $currentStateVersion,
        EstimateGenerationStatus $currentStatus,
        bool $hasUsableDraft,
    ): FailureRecoveryOutcome {
        if ($failure->context->expectedSessionStateVersion !== $currentStateVersion
            || $failure->context->expectedSessionStatus !== $currentStatus->value
            || $currentStatus->isTerminal()
            || $currentStatus === EstimateGenerationStatus::Failed) {
            return FailureRecoveryOutcome::Ignore;
        }

        return match ($failure->category) {
            FailureCategory::Recoverable => FailureRecoveryOutcome::Retry,
            FailureCategory::UserActionRequired => FailureRecoveryOutcome::NeedsInput,
            FailureCategory::Terminal => $hasUsableDraft
                ? FailureRecoveryOutcome::NeedsInput
                : FailureRecoveryOutcome::Terminal,
        };
    }
}
