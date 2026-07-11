<?php

declare(strict_types=1);

namespace App\Integrations\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\OrdinaryEstimateNumberLookup;
use App\Models\Estimate;

final class EloquentOrdinaryEstimateNumberLookup implements OrdinaryEstimateNumberLookup
{
    public function exists(int $organizationId, string $number): bool
    {
        return Estimate::query()
            ->where('organization_id', $organizationId)
            ->where('number', $number)
            ->exists();
    }
}
