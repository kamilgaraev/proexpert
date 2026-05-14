<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class ChangeManagementModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/change-management.json';
    }
}
