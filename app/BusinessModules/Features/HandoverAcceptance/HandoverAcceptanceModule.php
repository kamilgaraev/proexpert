<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class HandoverAcceptanceModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/handover-acceptance.json';
    }
}
