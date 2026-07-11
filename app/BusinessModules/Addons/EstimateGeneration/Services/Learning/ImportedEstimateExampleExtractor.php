<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

interface ImportedEstimateExampleExtractor
{
    /** @return array<int, array<string, mixed>> */
    public function extractFromImportedEstimate(object $estimate, ?object $importSession = null): array;
}
