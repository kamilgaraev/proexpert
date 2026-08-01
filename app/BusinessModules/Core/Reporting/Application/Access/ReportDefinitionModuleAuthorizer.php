<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use Throwable;

final readonly class ReportDefinitionModuleAuthorizer
{
    public function __construct(private ReportModuleEntitlement $entitlements) {}

    public function decision(int $organizationId): ReportDefinitionModuleAccessDecision
    {
        return new ReportDefinitionModuleAccessDecision($organizationId, $this);
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
