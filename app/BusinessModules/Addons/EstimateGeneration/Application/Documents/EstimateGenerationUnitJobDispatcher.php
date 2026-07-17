<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface EstimateGenerationUnitJobDispatcher
{
    public function dispatch(int $unitId, string $sourceVersion, bool $priority = false): void;
}
