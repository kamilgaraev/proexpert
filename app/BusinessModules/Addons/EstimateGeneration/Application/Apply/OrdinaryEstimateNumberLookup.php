<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

interface OrdinaryEstimateNumberLookup
{
    public function exists(int $organizationId, string $number): bool;
}
