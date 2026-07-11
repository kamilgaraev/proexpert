<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface AiUsageStore
{
    public function record(AiUsageData $data): void;
}
