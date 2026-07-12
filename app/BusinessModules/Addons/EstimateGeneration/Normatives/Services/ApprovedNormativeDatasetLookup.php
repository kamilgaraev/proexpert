<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

interface ApprovedNormativeDatasetLookup
{
    public function approved(string $version): bool;
}
