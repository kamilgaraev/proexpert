<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class QualityControlModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/quality-control.json';
    }
}
