<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class ProductionLaborModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/production-labor.json';
    }
}
