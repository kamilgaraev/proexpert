<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPerformanceSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final readonly class HoldingPerformanceReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private HoldingHierarchyResolver $hierarchies = new HoldingHierarchyResolver,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === HoldingPerformanceSnapshotMaterializer::CODE;
    }

    public function readyForOrganization(int $organizationId, ?ReportDefinition $definition = null): bool
    {
        try {
            $hierarchy = $this->hierarchies->resolve($organizationId);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if ($hierarchy->holdingId !== $organizationId) {
            return false;
        }
        $snapshot = HoldingPerformanceSnapshot::query()
            ->where('organization_id', $organizationId)
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if (! $snapshot instanceof HoldingPerformanceSnapshot) {
            return false;
        }
        $facts = HoldingAllocationFactVersion::query()
            ->where('holding_id', $hierarchy->holdingId)
            ->whereIn('organization_id', $hierarchy->organizationIds);
        $allocationWatermark = (string) ((clone $facts)->where('monetary_basis', 'contracted')->max('id') ?? 0);
        $actWatermark = (string) ((clone $facts)->where('monetary_basis', 'accepted_accrual')->max('id') ?? 0);
        $paymentWatermark = (string) ((clone $facts)->where('monetary_basis', 'cash')->max('id') ?? 0);
        $schemaVersions = (clone $facts)->distinct()->pluck('source_schema_version')->map(
            static fn (mixed $version): string => (string) $version,
        )->all();
        sort($schemaVersions, SORT_STRING);
        $expectedSchema = $definition?->sourceSchemaVersion ?? (string) $snapshot->source_schema_version;

        return (string) $snapshot->quality_status === 'complete'
            && (string) $snapshot->freshness_status === 'fresh'
            && ($snapshot->stale_at === null || $snapshot->stale_at->isFuture())
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->source_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->definition_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->query_hash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->hierarchy_watermark) === 1
            && ($definition === null || hash_equals($definition->definitionHash->value, (string) $snapshot->definition_hash))
            && ($definition === null || hash_equals($definition->formulaVersion, (string) $snapshot->formula_version))
            && hash_equals($expectedSchema, (string) $snapshot->source_schema_version)
            && $schemaVersions === [HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION]
            && hash_equals($hierarchy->version, (string) $snapshot->hierarchy_watermark)
            && hash_equals($allocationWatermark, (string) $snapshot->allocation_watermark)
            && hash_equals($actWatermark, (string) $snapshot->act_watermark)
            && hash_equals($paymentWatermark, (string) $snapshot->payment_watermark)
            && (int) $allocationWatermark > 0
            && (int) $actWatermark > 0
            && (int) $paymentWatermark > 0
            && (int) $snapshot->row_count > 0
            && ! HoldingAllocationProjectionGap::query()
                ->where('holding_id', $hierarchy->holdingId)
                ->whereIn('organization_id', $hierarchy->organizationIds)
                ->whereNull('resolved_at')
                ->exists();
    }
}
