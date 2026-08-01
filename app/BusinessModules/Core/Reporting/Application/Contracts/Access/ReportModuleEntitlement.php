<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Access;

interface ReportModuleEntitlement
{
    public function organizationHasModule(int $organizationId, string $moduleSlug): bool;
}
