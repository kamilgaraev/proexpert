<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\IntercompanyContractFlowSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final readonly class IntercompanyContractFlowReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === IntercompanyContractFlowSnapshotMaterializer::CODE;
    }

    public function readyForOrganization(int $organizationId): bool
    {
        return IntercompanyContractFlowSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('quality_status', 'complete')
            ->where('freshness_status', 'fresh')
            ->exists();
    }
}
