<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Export;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

interface EstimateGenerationExporter
{
    /** @return array<string, mixed> */
    public function export(EstimateGenerationSession $session): array;
}
