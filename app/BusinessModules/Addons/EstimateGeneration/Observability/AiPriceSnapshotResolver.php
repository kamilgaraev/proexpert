<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface AiPriceSnapshotResolver
{
    public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot;
}
