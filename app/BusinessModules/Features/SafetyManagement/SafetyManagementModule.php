<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class SafetyManagementModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/safety-management.json';
    }
}
