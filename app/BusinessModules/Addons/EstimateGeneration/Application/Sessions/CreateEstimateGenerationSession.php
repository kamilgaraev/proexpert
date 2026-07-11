<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class CreateEstimateGenerationSession
{
    public function __construct(private SessionStateStore $stateStore) {}

    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes): EstimateGenerationSession
    {
        return $this->stateStore->create($attributes);
    }
}
