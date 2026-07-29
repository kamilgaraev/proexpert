<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
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
        $snapshot = IntercompanyContractFlowSnapshot::query()
            ->where('organization_id', $organizationId)
            ->latest('generated_at')
            ->latest('id')
            ->first();

        return $snapshot instanceof IntercompanyContractFlowSnapshot
            && (string) $snapshot->quality_status === 'complete'
            && (string) $snapshot->freshness_status === 'fresh'
            && ($snapshot->stale_at === null || $snapshot->stale_at->isFuture())
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->source_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->definition_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->query_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->hierarchy_watermark) === 1
            && trim((string) $snapshot->formula_version) !== ''
            && ctype_digit((string) $snapshot->allocation_watermark)
            && (int) $snapshot->allocation_watermark > 0
            && (int) $snapshot->row_count > 0
            && ! HoldingAllocationProjectionGap::query()
                ->where('organization_id', $organizationId)
                ->where('monetary_basis', 'contracted')
                ->whereNull('resolved_at')
                ->exists();
    }
}
