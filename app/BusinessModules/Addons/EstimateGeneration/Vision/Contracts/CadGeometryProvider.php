<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\Models\Organization;

interface CadGeometryProvider
{
    public function extract(string $storageKey, Organization $organization): VectorGeometryData;
}
