<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class WorkforceManagementModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/workforce-management.json';
    }
}
