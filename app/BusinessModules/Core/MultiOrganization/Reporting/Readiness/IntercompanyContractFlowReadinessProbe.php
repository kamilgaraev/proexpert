<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\IntercompanyContractFlowSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\Models\ContractAllocationHistory;

final readonly class IntercompanyContractFlowReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private HoldingHierarchyResolver $hierarchies = new HoldingHierarchyResolver,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === IntercompanyContractFlowSnapshotMaterializer::CODE;
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
        $snapshot = IntercompanyContractFlowSnapshot::query()
            ->where('organization_id', $organizationId)
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if (! $snapshot instanceof IntercompanyContractFlowSnapshot) {
            return false;
        }
        $facts = HoldingAllocationFactVersion::query()
            ->where('holding_id', $hierarchy->holdingId)
            ->whereIn('organization_id', $hierarchy->organizationIds)
            ->where('monetary_basis', 'contracted');
        $allocationWatermark = (string) ((clone $facts)->max('id') ?? 0);
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
            && (int) $allocationWatermark > 0
            && (int) $snapshot->row_count > 0
            && HoldingAllocationFactVersion::query()
                ->whereIn('organization_id', $hierarchy->organizationIds)
                ->where('monetary_basis', 'contracted')
                ->whereDate('recognized_on', '<=', $snapshot->generated_at)
                ->count() >= ContractAllocationHistory::query()
                ->where('created_at', '<=', $snapshot->generated_at)
                ->whereHas('contract', static fn ($query) => $query
                    ->whereIn('organization_id', $hierarchy->organizationIds))
                ->count()
            && ! HoldingAllocationProjectionGap::query()
                ->where('holding_id', $hierarchy->holdingId)
                ->whereIn('organization_id', $hierarchy->organizationIds)
                ->where('monetary_basis', 'contracted')
                ->whereNull('resolved_at')
                ->exists();
    }
}
