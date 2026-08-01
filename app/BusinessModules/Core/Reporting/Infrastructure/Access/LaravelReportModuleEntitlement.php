<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\Services\Entitlements\OrganizationEntitlementService;

final readonly class LaravelReportModuleEntitlement implements ReportModuleEntitlement
{
    public function __construct(private OrganizationEntitlementService $entitlements) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        return $this->entitlements->hasModuleAccess($organizationId, $moduleSlug);
    }
}
