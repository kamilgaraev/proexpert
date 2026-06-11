<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class CrmModule extends ConstructionErpFeatureModule
{
    public const SLUG = 'crm';

    protected function manifestPath(): string
    {
        return 'ModuleList/features/crm.json';
    }
}
