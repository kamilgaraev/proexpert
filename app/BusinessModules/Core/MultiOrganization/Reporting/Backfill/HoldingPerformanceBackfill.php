<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Backfill;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;

final readonly class HoldingPerformanceBackfill
{
    public function __construct(private HoldingAllocationFactProjector $projector)
    {
    }

    public function projectSlice(iterable $sourceRows): array
    {
        $ids = [];
        foreach ($sourceRows as $source) {
            if (!is_array($source)) {
                continue;
            }
            $fact = $this->projector->project($source);
            $record = HoldingAllocationFactVersion::query()->firstOrCreate(
                [
                    'organization_id' => $fact->organizationId,
                    'source_type' => $fact->sourceType,
                    'source_id' => $fact->sourceId,
                    'source_version' => $fact->sourceVersion,
                    'monetary_basis' => $fact->monetaryBasis,
                ],
                [
                    'holding_id' => $fact->holdingId,
                    'hierarchy_version' => (string) ($source['hierarchy_version'] ?? 'unresolved'),
                    'contributor_organization_id' => $fact->contributorOrganizationId,
                    'counterparty_organization_id' => $fact->counterpartyOrganizationId,
                    'project_id' => $fact->projectId,
                    'contract_id' => $fact->contractId,
                    'linked_parent_allocation_id' => $fact->linkedParentAllocationId,
                    'tax_basis' => (string) ($source['tax_basis'] ?? 'source'),
                    'amount_minor' => $fact->amountMinor,
                    'currency' => $fact->currency,
                    'currency_source' => $fact->currencySource,
                    'recognized_on' => $fact->recognizedOn,
                    'flow_class' => $fact->flowClass,
                    'allocated_amount_minor' => $source['allocated_amount_minor'] ?? null,
                    'allocated_percentage' => $source['allocated_percentage'] ?? null,
                    'contract_amount_minor' => $source['contract_amount_minor'] ?? null,
                    'source_refs' => $fact->sourceRefs,
                    'source_hash' => hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR)),
                    'projected_at' => now(),
                ],
            );
            $ids[] = (int) $record->getKey();
        }

        return $ids;
    }
}
