<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
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
        private HoldingContractDimensionResolver $contractDimensions,
        private HoldingAllocationContextResolver $allocationContexts,
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
        if ($recognizedAt === null) {
            $this->projector->recordGap([
                'organization_id' => $organizationId,
                'source_type' => 'performance_act',
                'source_id' => (int) $act->getKey(),
                'source_version' => $sourceVersion,
                'monetary_basis' => 'accepted_accrual',
                'business_effective_at' => $act->created_at,
            ], ['recognized_on']);

            return null;
        }
        $missing = [];
        try {
            $hierarchy = $this->hierarchies->resolveAt($organizationId, $recognizedAt);
        } catch (InvalidArgumentException $exception) {
            $hierarchy = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'hierarchy_coverage'
                : 'hierarchy';
        }
        try {
            $dimension = $this->contractDimensions->resolve(
                $organizationId,
                (int) $contract->getKey(),
                $recognizedAt,
            );
        } catch (InvalidArgumentException $exception) {
            $dimension = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'contract_dimension_coverage'
                : 'contract_dimensions';
        }
        try {
            $allocation = $this->allocationContexts->resolve(
                $organizationId,
                (int) $contract->getKey(),
                $projectId,
                $recognizedAt,
            );
        } catch (InvalidArgumentException $exception) {
            $allocation = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'allocation_context_coverage'
                : 'allocation_context';
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
                'business_effective_at' => $recognizedAt,
            ], $missing);

            return null;
        }
        if ($hierarchy === null || $dimension === null || $allocation === null) {
            return null;
        }

        $counterpartyOrganizationId = $dimension->counterpartyOrganizationId;
        $sourceRefs = [
            ['type' => 'approved_act', 'id' => (int) $act->getKey(), 'version' => $sourceVersion],
            [
                'type' => 'contract_allocation',
                'id' => $allocation->allocationId,
                'contract_id' => (int) $contract->getKey(),
                'version' => $allocation->eventId,
                'hash' => $allocation->evidenceHash,
            ],
            [
                'type' => 'contract_dimension',
                'id' => $dimension->eventId,
                'hash' => $dimension->evidenceHash,
            ],
            [
                'type' => 'organization_hierarchy',
                'hash' => $hierarchy->version,
                'evidence_hashes' => $hierarchy->evidenceHashes,
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
            'contractor_id' => $dimension->contractorId,
            'contract_status' => $dimension->contractStatus,
            'work_type_category' => $dimension->workTypeCategory,
            'contract_dimension_hash' => $dimension->evidenceHash,
            'allocation_id' => $allocation->allocationId,
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => 'performance_act',
            'source_id' => (int) $act->getKey(),
            'source_version' => $sourceVersion,
            'monetary_basis' => 'accepted_accrual',
            'allocated_amount_minor' => $active ? $this->moneyToMinor((string) $act->amount) : 0,
            'allocated_percentage' => null,
            'contract_amount_minor' => null,
            'currency' => $dimension->currency,
            'currency_source' => 'contract_dimension',
            'tax_basis' => 'approved_act_amount',
            'recognized_on' => $recognizedAt->format('Y-m-d'),
            'business_effective_at' => $recognizedAt,
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

    private function moneyToMinor(string $amount): int
    {
        try {
            return BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException('holding_allocation_amount_invalid');
        }
    }
}
