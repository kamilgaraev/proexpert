<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation;

use App\BusinessModules\Features\ConstructionErpFeatureModule;

final class ExecutiveDocumentationModule extends ConstructionErpFeatureModule
{
    protected function manifestPath(): string
    {
        return 'ModuleList/features/executive-documentation.json';
    }
}
