<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

interface VisionModelPinningStore
{
    public function pinVision(
        string $correlationId,
        int $organizationId,
        int $sessionId,
        ?string $visionModelOverride,
        string $visionModelFallback,
    ): EffectiveSettingsPair;
}
