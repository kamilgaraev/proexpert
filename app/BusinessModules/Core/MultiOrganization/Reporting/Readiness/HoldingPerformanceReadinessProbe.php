<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Readiness;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPerformanceSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingHierarchyResolver;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\Models\ContractAllocationHistory;
use App\Models\ContractPerformanceAct;

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
            ->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
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
            && preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->quality_gap_watermark) === 1
            && $snapshot->recorded_cutoff !== null
            && (int) $snapshot->quality_gap_count === 0
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
            && $this->eligibleSourcesAreProjected($hierarchy->organizationIds, $snapshot->generated_at)
            && ! HoldingAllocationProjectionGap::query()
                ->where('holding_id', $hierarchy->holdingId)
                ->whereIn('organization_id', $hierarchy->organizationIds)
                ->where('business_effective_at', '<=', $snapshot->generated_at)
                ->where('recorded_at', '<=', $snapshot->recorded_cutoff)
                ->where(static fn ($query) => $query
                    ->where(static fn ($recorded) => $recorded
                        ->whereNull('resolved_at')
                        ->orWhere('resolved_at', '>', $snapshot->recorded_cutoff))
                    ->orWhere('resolved_business_effective_at', '>', $snapshot->generated_at))
                ->exists();
    }

    private function eligibleSourcesAreProjected(array $organizationIds, mixed $asOf): bool
    {
        $eligibleAllocations = ContractAllocationHistory::query()
            ->where('created_at', '<=', $asOf)
            ->whereHas('contract', static fn ($query) => $query->whereIn('organization_id', $organizationIds))
            ->count();
        $projectedAllocations = HoldingAllocationFactVersion::query()
            ->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
            ->whereIn('organization_id', $organizationIds)
            ->where('monetary_basis', 'contracted')
            ->whereDate('recognized_on', '<=', $asOf)
            ->count();
        $eligibleActs = ContractPerformanceAct::query()
            ->where('is_approved', true)
            ->whereIn('status', [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED])
            ->whereHas('contract', static fn ($query) => $query->whereIn('organization_id', $organizationIds))
            ->where(static fn ($query) => $query
                ->where('approval_date', '<=', $asOf)
                ->orWhere(static fn ($fallback) => $fallback->whereNull('approval_date')->where('created_at', '<=', $asOf)))
            ->distinct()
            ->count('id');
        $projectedActs = HoldingAllocationFactVersion::query()
            ->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
            ->whereIn('organization_id', $organizationIds)
            ->where('monetary_basis', 'accepted_accrual')
            ->whereDate('recognized_on', '<=', $asOf)
            ->distinct()
            ->count('source_id');
        $eligiblePayments = PaymentTransaction::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', PaymentTransactionStatus::COMPLETED)
            ->where('created_at', '<=', $asOf)
            ->distinct()
            ->count('id');
        $projectedPayments = HoldingAllocationFactVersion::query()
            ->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
            ->whereIn('organization_id', $organizationIds)
            ->where('monetary_basis', 'cash')
            ->whereDate('recognized_on', '<=', $asOf)
            ->distinct()
            ->count('source_id');

        return $projectedAllocations >= $eligibleAllocations
            && $projectedActs >= $eligibleActs
            && $projectedPayments >= $eligiblePayments;
    }
}
