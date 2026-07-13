<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface SessionBaseInputVersionResolver
{
    public function resolve(EstimateGenerationSession $session): string;
}
