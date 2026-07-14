<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

interface EffectiveSettingsOperationStore
{
    public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair;
}
