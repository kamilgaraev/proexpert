<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class CreateEstimateGenerationSession
{
    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->create([
            ...$attributes,
            'status' => EstimateGenerationStatus::Draft->value,
            'state_version' => 0,
            'resume_status' => null,
        ]);
    }
}
