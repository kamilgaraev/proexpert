<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class GenerationAttemptGuard
{
    public function matches(
        EstimateGenerationSession $session,
        ?int $expectedStateVersion,
        ?string $attemptId,
    ): bool {
        return $attemptId !== null
            && $session->status === EstimateGenerationStatus::Generating
            && ($expectedStateVersion === null || $session->state_version === $expectedStateVersion)
            && ($session->input_payload['generation_attempt_id'] ?? null) === $attemptId;
    }
}
