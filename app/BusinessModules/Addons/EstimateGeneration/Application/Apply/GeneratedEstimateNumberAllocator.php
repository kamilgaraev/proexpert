<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface GeneratedEstimateNumberAllocator
{
    public function allocate(EstimateGenerationSession $session, int $attempt): string;
}
