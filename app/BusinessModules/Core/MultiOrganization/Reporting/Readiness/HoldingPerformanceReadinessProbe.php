<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPerformanceSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final readonly class HoldingPerformanceReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === HoldingPerformanceSnapshotMaterializer::CODE;
    }

    public function readyForOrganization(int $organizationId): bool
    {
        return HoldingPerformanceSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('quality_status', 'complete')
            ->where('freshness_status', 'fresh')
            ->exists();
    }
}
