<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface SessionStateStore
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): EstimateGenerationSession;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function compareAndSet(
        EstimateGenerationSession $session,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): EstimateGenerationSession;
}
