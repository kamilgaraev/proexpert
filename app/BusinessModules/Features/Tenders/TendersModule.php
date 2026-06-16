<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class TendersModule extends ConstructionErpFeatureModule
{
    public const SLUG = 'tenders';

    protected function manifestPath(): string
    {
        return 'ModuleList/features/tenders.json';
    }
}
