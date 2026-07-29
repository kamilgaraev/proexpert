<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
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
        $snapshot = HoldingPerformanceSnapshot::query()
            ->where('organization_id', $organizationId)
            ->latest('generated_at')
            ->latest('id')
            ->first();

        return $snapshot instanceof HoldingPerformanceSnapshot
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
            && ctype_digit((string) $snapshot->act_watermark)
            && (int) $snapshot->act_watermark > 0
            && ctype_digit((string) $snapshot->payment_watermark)
            && (int) $snapshot->payment_watermark > 0
            && (int) $snapshot->row_count > 0
            && ! HoldingAllocationProjectionGap::query()
                ->where('organization_id', $organizationId)
                ->whereNull('resolved_at')
                ->exists();
    }
}
