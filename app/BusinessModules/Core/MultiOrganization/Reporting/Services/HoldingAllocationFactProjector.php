<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Models\Contract;
use App\Models\ContractAllocationHistory;
use App\Models\ContractProjectAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class HoldingAllocationFactProjector
{
    public function recordContractAllocation(
        Contract $contract,
        ContractProjectAllocation $allocation,
    ): ?HoldingAllocationFactVersion {
        $type = $allocation->allocation_type;
        if (!$type instanceof ContractAllocationTypeEnum
            || !in_array($type, [ContractAllocationTypeEnum::FIXED, ContractAllocationTypeEnum::PERCENTAGE], true)) {
            return null;
        }

        $history = ContractAllocationHistory::query()
            ->where('allocation_id', $allocation->getKey())
            ->latest('id')
            ->first();
        if (!$history instanceof ContractAllocationHistory) {
            throw new InvalidArgumentException('holding_allocation_history_missing');
        }

        $fixedMinor = $type === ContractAllocationTypeEnum::FIXED
            ? $this->moneyToMinor((string) $allocation->allocated_amount)
            : null;
        $percentage = $type === ContractAllocationTypeEnum::PERCENTAGE
            ? (string) $allocation->allocated_percentage
            : null;
        $contractMinor = $this->moneyToMinor((string) $contract->total_amount);
        $fact = $this->project([
            'organization_id' => (int) $contract->organization_id,
            'holding_id' => (int) $contract->organization_id,
            'contributor_organization_id' => (int) $contract->organization_id,
            'project_id' => (int) $allocation->project_id,
            'contract_id' => (int) $contract->getKey(),
            'allocation_id' => (int) $allocation->getKey(),
            'source_type' => 'contract',
            'source_id' => (int) $allocation->getKey(),
            'source_version' => (int) $history->getKey(),
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => $fixedMinor,
            'allocated_percentage' => $percentage,
            'contract_amount_minor' => $contractMinor,
            'currency' => null,
            'recognized_on' => $history->created_at->format('Y-m-d'),
            'flow_class' => 'unclassified',
            'source_refs' => [[
                'type' => 'contract_allocation',
                'id' => (int) $allocation->getKey(),
                'version' => (int) $history->getKey(),
            ]],
        ]);

        return HoldingAllocationFactVersion::query()->firstOrCreate(
            [
                'organization_id' => $fact->organizationId,
                'source_type' => $fact->sourceType,
                'source_id' => $fact->sourceId,
                'source_version' => $fact->sourceVersion,
                'monetary_basis' => $fact->monetaryBasis,
            ],
            [
                'holding_id' => $fact->holdingId,
                'hierarchy_version' => 'unresolved',
                'contributor_organization_id' => $fact->contributorOrganizationId,
                'counterparty_organization_id' => $fact->counterpartyOrganizationId,
                'project_id' => $fact->projectId,
                'contract_id' => $fact->contractId,
                'linked_parent_allocation_id' => $fact->linkedParentAllocationId,
                'tax_basis' => 'source',
                'amount_minor' => $fact->amountMinor,
                'currency' => $fact->currency,
                'currency_source' => $fact->currencySource,
                'recognized_on' => $fact->recognizedOn,
                'flow_class' => $fact->flowClass,
                'allocated_amount_minor' => $fixedMinor,
                'allocated_percentage' => $percentage,
                'contract_amount_minor' => $contractMinor,
                'source_refs' => $fact->sourceRefs,
                'source_hash' => hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR)),
                'projected_at' => now(),
            ],
        );
    }

    public function project(array $source): HoldingAllocationFact
    {
        $amount = $source['allocated_amount_minor'] ?? null;
        $percentage = $source['allocated_percentage'] ?? null;
        if (($amount === null) === ($percentage === null)) {
            throw new InvalidArgumentException('holding_allocation_method_invalid');
        }

        if ($amount !== null) {
            if (!is_int($amount)) {
                throw new InvalidArgumentException('holding_allocation_amount_invalid');
            }
            $amountMinor = $amount;
        } else {
            $contractAmount = $source['contract_amount_minor'] ?? null;
            if (!is_int($contractAmount) || !is_numeric($percentage)) {
                throw new InvalidArgumentException('holding_allocation_percentage_invalid');
            }
            try {
                $amountMinor = BigDecimal::of($contractAmount)
                    ->multipliedBy(BigDecimal::of((string) $percentage))
                    ->dividedBy(100, 0, RoundingMode::HalfUp)
                    ->toInt();
            } catch (MathException) {
                throw new InvalidArgumentException('holding_allocation_percentage_invalid');
            }
        }

        $flowClass = isset($source['hierarchy_organization_ids']) && is_array($source['hierarchy_organization_ids'])
            ? $this->classify(
                (int) ($source['contributor_organization_id'] ?? 0),
                isset($source['counterparty_organization_id']) ? (int) $source['counterparty_organization_id'] : null,
                $source['hierarchy_organization_ids'],
            )
            : (string) ($source['flow_class'] ?? 'unclassified');

        return new HoldingAllocationFact(
            organizationId: (int) ($source['organization_id'] ?? 0),
            holdingId: (int) ($source['holding_id'] ?? 0),
            contributorOrganizationId: (int) ($source['contributor_organization_id'] ?? 0),
            counterpartyOrganizationId: isset($source['counterparty_organization_id']) ? (int) $source['counterparty_organization_id'] : null,
            projectId: (int) ($source['project_id'] ?? 0),
            contractId: (int) ($source['contract_id'] ?? 0),
            allocationId: (int) ($source['allocation_id'] ?? 0),
            linkedParentAllocationId: isset($source['linked_parent_allocation_id']) ? (int) $source['linked_parent_allocation_id'] : null,
            sourceType: (string) ($source['source_type'] ?? ''),
            sourceId: (int) ($source['source_id'] ?? 0),
            sourceVersion: (int) ($source['source_version'] ?? 0),
            monetaryBasis: (string) ($source['monetary_basis'] ?? ''),
            amountMinor: $amountMinor,
            currency: isset($source['currency']) ? mb_strtoupper((string) $source['currency']) : null,
            currencySource: isset($source['currency']) ? 'source' : 'unknown',
            recognizedOn: (string) ($source['recognized_on'] ?? ''),
            flowClass: $flowClass,
            sourceRefs: is_array($source['source_refs'] ?? null) ? array_values($source['source_refs']) : [],
        );
    }

    public function classify(
        int $contributorOrganizationId,
        ?int $counterpartyOrganizationId,
        array $hierarchyOrganizationIds,
    ): string {
        $members = array_fill_keys(array_map('intval', $hierarchyOrganizationIds), true);
        $contributorInside = isset($members[$contributorOrganizationId]);
        $counterpartyInside = $counterpartyOrganizationId !== null && isset($members[$counterpartyOrganizationId]);

        if ($contributorInside && $counterpartyInside) {
            return 'internal';
        }
        if ($contributorInside xor $counterpartyInside) {
            return 'external';
        }

        return 'unclassified';
    }

    private function moneyToMinor(string $amount): int
    {
        try {
            return BigDecimal::of($amount)
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException('holding_allocation_amount_invalid');
        }
    }
}
