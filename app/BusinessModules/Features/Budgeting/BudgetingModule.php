<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class BudgetingModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/budgeting.json';
    }
}
