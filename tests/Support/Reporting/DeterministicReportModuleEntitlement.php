<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;

final readonly class DeterministicReportModuleEntitlement implements ReportModuleEntitlement
{
    public function __construct(
        private array $allowedModules = ['reports', 'act-reporting'],
        private ?array $allowedOrganizationIds = null,
    ) {}

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        return $organizationId > 0
            && ($this->allowedOrganizationIds === null
                || in_array($organizationId, $this->allowedOrganizationIds, true))
            && in_array($moduleSlug, $this->allowedModules, true);
    }
}
