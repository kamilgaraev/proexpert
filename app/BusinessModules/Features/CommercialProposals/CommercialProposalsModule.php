<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class CommercialProposalsModule extends ConstructionErpFeatureModule
{
    public const SLUG = 'commercial-proposals';

    protected function manifestPath(): string
    {
        return 'ModuleList/features/commercial-proposals.json';
    }
}
