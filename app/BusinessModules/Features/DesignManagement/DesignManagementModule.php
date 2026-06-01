<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class DesignManagementModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/design-management.json';
    }
}
