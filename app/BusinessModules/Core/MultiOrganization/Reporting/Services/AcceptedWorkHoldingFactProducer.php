<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractAllocationHistory;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class AcceptedWorkHoldingFactProducer
{
    public function __construct(
        private HoldingAllocationFactProjector $projector,
        private HoldingHierarchyResolver $hierarchies,
    ) {}

    public function project(
        ContractPerformanceAct $act,
        ?DateTimeInterface $occurredAt = null,
        bool $active = true,
        ?int $eventVersionId = null,
    ): ?HoldingAllocationFactVersion {
        if ($active && (! $act->is_approved
            || ! in_array($act->status, [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED], true))) {
            return null;
        }

        $act->loadMissing(['contract.contractor', 'lines', 'completedWorks']);
        $contract = $act->contract;
        $organizationId = (int) ($contract?->organization_id ?? 0);
        $projectId = (int) $act->project_id;
        $recognizedAt = $occurredAt
            ?? $act->approval_date
            ?? $act->signed_at
            ?? $act->updated_at;
        $sourceVersion = $this->sourceVersion($eventVersionId ?? 0);
        if (! $contract instanceof Contract || min($organizationId, $projectId, $sourceVersion) < 1) {
            return null;
        }
        $allocation = ContractProjectAllocation::withTrashed()
            ->where('contract_id', $contract->getKey())
            ->where('project_id', $projectId)
            ->whereHas('history', static fn ($query) => $query->where('created_at', '<=', $recognizedAt))
            ->orderByDesc('id')
            ->first();
        $history = $allocation instanceof ContractProjectAllocation
            ? ContractAllocationHistory::query()
                ->where('allocation_id', $allocation->getKey())
                ->where('created_at', '<=', $recognizedAt)
                ->latest('id')
                ->first()
            : null;

        $missing = [];
        if (! $allocation instanceof ContractProjectAllocation || ! $history instanceof ContractAllocationHistory) {
            $missing[] = 'allocation_version';
        }
        if ($recognizedAt === null) {
            $missing[] = 'recognized_on';
        }
        [$currency, $currencySource] = $this->contractCurrency($contract, $recognizedAt);
        if ($currency === null) {
            $missing[] = 'currency';
        }
        try {
            $hierarchy = $this->hierarchies->resolve($organizationId);
        } catch (InvalidArgumentException) {
            $hierarchy = null;
            $missing[] = 'hierarchy';
        }
        if ($missing !== []) {
            $this->projector->recordGap([
                'organization_id' => $organizationId,
                'holding_id' => $hierarchy?->holdingId,
                'hierarchy_version' => $hierarchy?->version,
                'source_type' => 'performance_act',
                'source_id' => (int) $act->getKey(),
                'source_version' => $sourceVersion,
                'monetary_basis' => 'accepted_accrual',
            ], $missing);

            return null;
        }

        $counterpartyOrganizationId = $contract->contractor_id === null
            ? null
            : Contractor::query()->whereKey($contract->contractor_id)->value('source_organization_id');
        $sourceRefs = [
            ['type' => 'approved_act', 'id' => (int) $act->getKey(), 'version' => $sourceVersion],
            [
                'type' => 'contract_allocation',
                'id' => (int) $allocation->getKey(),
                'contract_id' => (int) $contract->getKey(),
                'version' => (int) $history->getKey(),
            ],
        ];
        $source = [
            'organization_id' => $organizationId,
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'hierarchy_organization_ids' => $hierarchy->organizationIds,
            'contributor_organization_id' => $organizationId,
            'counterparty_organization_id' => $counterpartyOrganizationId === null ? null : (int) $counterpartyOrganizationId,
            'project_id' => $projectId,
            'contract_id' => (int) $contract->getKey(),
            'allocation_id' => (int) $allocation->getKey(),
            ...$this->linkedEvidence($contract, $projectId, $recognizedAt),
            'source_type' => 'performance_act',
            'source_id' => (int) $act->getKey(),
            'source_version' => $sourceVersion,
            'monetary_basis' => 'accepted_accrual',
            'allocated_amount_minor' => $active ? $this->moneyToMinor((string) $act->amount) : 0,
            'allocated_percentage' => null,
            'contract_amount_minor' => null,
            'currency' => $currency,
            'currency_source' => $currencySource,
            'tax_basis' => 'approved_act_amount',
            'recognized_on' => $recognizedAt->format('Y-m-d'),
            'source_refs' => [
                ...$sourceRefs,
            ],
        ];
        $missing = $this->projector->missingEvidence($source);
        if ($missing !== []) {
            $this->projector->recordGap($source, $missing);

            return null;
        }

        return $this->projector->persist($this->projector->project($source), $source);
    }

    private function sourceVersion(int $eventVersionId): int
    {
        if ($eventVersionId < 1) {
            throw new InvalidArgumentException('accepted_work_event_version_missing');
        }

        return $eventVersionId;
    }

    private function linkedEvidence(Contract $contract, int $projectId, DateTimeInterface $recognizedAt): array
    {
        $parentContractId = (int) ($contract->getAttribute('parent_contract_id') ?? 0);
        if ($parentContractId < 1) {
            return [
                'linked_parent_allocation_id' => null,
                'linked_incoming_minor' => null,
                'linked_outgoing_minor' => null,
            ];
        }

        $parentAllocation = ContractProjectAllocation::withTrashed()
            ->where('contract_id', $parentContractId)
            ->where('project_id', $projectId)
            ->whereHas('history', static fn ($query) => $query->where('created_at', '<=', $recognizedAt))
            ->latest('id')
            ->first();
        $childAllocation = ContractProjectAllocation::withTrashed()
            ->where('contract_id', $contract->getKey())
            ->where('project_id', $projectId)
            ->whereHas('history', static fn ($query) => $query->where('created_at', '<=', $recognizedAt))
            ->latest('id')
            ->first();
        if (! $parentAllocation instanceof ContractProjectAllocation
            || ! $childAllocation instanceof ContractProjectAllocation) {
            return [
                'linked_parent_allocation_id' => null,
                'linked_incoming_minor' => null,
                'linked_outgoing_minor' => null,
            ];
        }
        $parentFact = HoldingAllocationFactVersion::query()
            ->where('allocation_id', $parentAllocation->getKey())
            ->where('monetary_basis', 'contracted')
            ->whereDate('recognized_on', '<=', $recognizedAt)
            ->orderByDesc('source_version')
            ->first();
        $childFact = HoldingAllocationFactVersion::query()
            ->where('allocation_id', $childAllocation->getKey())
            ->where('monetary_basis', 'contracted')
            ->whereDate('recognized_on', '<=', $recognizedAt)
            ->orderByDesc('source_version')
            ->first();
        if (! $parentFact instanceof HoldingAllocationFactVersion
            || ! $childFact instanceof HoldingAllocationFactVersion) {
            return [
                'linked_parent_allocation_id' => (int) $parentAllocation->getKey(),
                'linked_incoming_minor' => null,
                'linked_outgoing_minor' => null,
            ];
        }

        return [
            'linked_parent_allocation_id' => (int) $parentAllocation->getKey(),
            'linked_incoming_minor' => (int) $parentFact->amount_minor,
            'linked_outgoing_minor' => (int) $childFact->amount_minor,
        ];
    }

    private function contractCurrency(Contract $contract, DateTimeInterface $recognizedAt): array
    {
        $currencies = PaymentDocument::query()
            ->where('organization_id', $contract->organization_id)
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->getKey())
            ->where('created_at', '<=', $recognizedAt)
            ->whereNotNull('currency')
            ->distinct()
            ->pluck('currency')
            ->map(static fn (mixed $currency): string => mb_strtoupper((string) $currency))
            ->filter(static fn (string $currency): bool => preg_match('/^[A-Z]{3}$/D', $currency) === 1)
            ->unique()
            ->values()
            ->all();

        return count($currencies) === 1 ? [$currencies[0], 'payment_document_consensus'] : [null, null];
    }

    private function moneyToMinor(string $amount): int
    {
        try {
            return BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException('holding_allocation_amount_invalid');
        }
    }
}
