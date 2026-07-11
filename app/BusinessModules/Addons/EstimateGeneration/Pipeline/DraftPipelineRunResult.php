<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class DraftPipelineRunResult
{
    public function __construct(
        public ?EstimateGenerationSession $session,
        public ?ProcessingStage $executedStage,
        public bool $dispatchNext,
        public bool $finalized,
    ) {}
}
