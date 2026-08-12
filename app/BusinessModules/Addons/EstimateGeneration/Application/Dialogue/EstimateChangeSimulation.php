<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface EstimateChangeSimulation
{
    /** @return array{state:string,delta:?string,blockers:string[],affected:array<int,array<string,mixed>>,fingerprint:string,version_fence:array<string,mixed>,assumptions?:string[],risks?:string[]} */
    public function calculate(
        EstimateGenerationSession $session,
        EstimateCommandInterpretation $interpretation,
    ): array;
}
