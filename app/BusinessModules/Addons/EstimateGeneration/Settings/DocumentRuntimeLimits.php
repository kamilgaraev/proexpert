<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;

interface DocumentRuntimeLimits
{
    public function assertWithinTotalPages(AiOperationContext $context, EffectiveEstimateGenerationSettings $settings): void;
}
