<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ScopedReportModuleEntitlement;
use App\Services\Entitlements\OrganizationEntitlementService;

final readonly class LaravelReportModuleEntitlement implements ScopedReportModuleEntitlement
{
    public function __construct(private OrganizationEntitlementService $entitlements) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        return $this->entitlements->hasModuleAccess($organizationId, $moduleSlug);
    }

    public function forReadScope(): ReportModuleEntitlement
    {
        return new class($this->entitlements) implements ReportModuleEntitlement
        {
            private array $modules = [];

            public function __construct(private readonly OrganizationEntitlementService $entitlements) {}

            public function organizationHasModule(int $organizationId, string $moduleSlug): bool
            {
                $this->modules[$organizationId] ??= $this->entitlements->getEffectiveModuleSlugs($organizationId);

                return in_array($moduleSlug, $this->modules[$organizationId], true);
            }
        };
    }
}
