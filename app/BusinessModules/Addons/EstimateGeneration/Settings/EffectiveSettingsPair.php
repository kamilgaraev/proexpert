<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

final readonly class EffectiveSettingsPair
{
    public function __construct(
        public EffectiveEstimateGenerationSettings $global,
        public EffectiveEstimateGenerationSettings $effective,
    ) {}
}
