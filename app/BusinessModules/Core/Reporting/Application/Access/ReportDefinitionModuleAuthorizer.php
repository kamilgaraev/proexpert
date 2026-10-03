<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ScopedReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use Throwable;

final readonly class ReportDefinitionModuleAuthorizer
{
    public function __construct(private ReportModuleEntitlement $entitlements) {}

    public function decision(int $organizationId): ReportDefinitionModuleAccessDecision
    {
        $authorizer = $this->entitlements instanceof ScopedReportModuleEntitlement
            ? new self($this->entitlements->forReadScope()) : $this;

        return new ReportDefinitionModuleAccessDecision($organizationId, $authorizer);
    }

    public function allows(int $organizationId, ReportDefinition $definition): bool
    {
        if ($organizationId <= 0) {
            return false;
        }

        try {
            return $this->entitlements->organizationHasModule(
                $organizationId,
                $definition->sourceModule,
            );
        } catch (Throwable) {
            return false;
        }
    }
}
