<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class MachineryOperationsModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/machinery-operations.json';
    }
}
