<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentUnitExhaustionHandler
{
    public function handle(int $unitId): void;
}
