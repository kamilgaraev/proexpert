<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
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

        $act->loadMissing('contract');
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

        return $this->projectValues(
            organizationId: $organizationId,
            projectId: $projectId,
            contractId: (int) $contract->getKey(),
            actId: (int) $act->getKey(),
            sourceVersion: $sourceVersion,
            amount: (string) $act->amount,
            status: (string) $act->status,
            active: $active,
            recognizedAt: $recognizedAt,
            historyComplete: true,
            sourceHash: null,
        );
    }

    public function canProjectEvent(HoldingAcceptedWorkEventVersion $event, ?int $holdingId = null): bool
    {
        $projection = $this->projectionForEvent($event);

        return $projection['missing'] === []
            && (! $event->active
                || $holdingId === null
                || ($projection['identity']['holding_id'] ?? null) === $holdingId);
    }

    public function projectEvent(HoldingAcceptedWorkEventVersion $event): ?HoldingAllocationFactVersion
    {
        $projection = $this->projectionForEvent($event);
        if ($projection['missing'] !== []) {
            $this->projector->recordGap($projection['identity'], $projection['missing']);

            return null;
        }
        $source = $projection['source'];
        if (! is_array($source)) {
            return null;
        }

        return $this->projector->persist($this->projector->project($source), $source);
    }

    private function projectionForEvent(HoldingAcceptedWorkEventVersion $event): array
    {
        return $this->projection(
            organizationId: (int) $event->organization_id,
            projectId: (int) $event->project_id,
            contractId: (int) $event->contract_id,
            actId: (int) $event->performance_act_id,
            sourceVersion: (int) $event->getKey(),
            amount: (string) $event->amount,
            status: (string) $event->status,
            active: (bool) $event->active,
            recognizedAt: $event->occurred_at,
            historyComplete: (bool) $event->history_complete,
            sourceHash: (string) $event->source_hash,
        );
    }

    private function projectValues(
        int $organizationId,
        int $projectId,
        int $contractId,
        int $actId,
        int $sourceVersion,
        string $amount,
        string $status,
        bool $active,
        ?DateTimeInterface $recognizedAt,
        bool $historyComplete,
        ?string $sourceHash,
    ): ?HoldingAllocationFactVersion {
        $projection = $this->projection(
            $organizationId,
            $projectId,
            $contractId,
            $actId,
            $sourceVersion,
            $amount,
            $status,
            $active,
            $recognizedAt,
            $historyComplete,
            $sourceHash,
        );
        if ($projection['missing'] !== []) {
            $this->projector->recordGap($projection['identity'], $projection['missing']);

            return null;
        }
        $source = $projection['source'];
        if (! is_array($source)) {
            return null;
        }

        return $this->projector->persist($this->projector->project($source), $source);
    }

    private function projection(
        int $organizationId,
        int $projectId,
        int $contractId,
        int $actId,
        int $sourceVersion,
        string $amount,
        string $status,
        bool $active,
        ?DateTimeInterface $recognizedAt,
        bool $historyComplete,
        ?string $sourceHash,
    ): array {
        $identity = [
            'organization_id' => $organizationId,
            'source_type' => 'performance_act',
            'source_id' => $actId,
            'source_version' => $sourceVersion,
            'monetary_basis' => 'accepted_accrual',
            'business_effective_at' => $recognizedAt,
        ];
        $missing = [];
        if (min($organizationId, $projectId, $contractId, $actId, $sourceVersion) < 1) {
            $missing[] = 'accepted_work_event_identity';
        }
        if (! $historyComplete) {
            $missing[] = 'accepted_work_event_history';
        }
        if ($recognizedAt === null) {
            $missing[] = 'recognized_on';
        }
        if ($active && ! in_array(
            $status,
            [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED],
            true,
        )) {
            $missing[] = 'accepted_work_status';
        }
        if ($sourceHash !== null && preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            $missing[] = 'accepted_work_event_hash';
        }
        if ($missing !== []) {
            return ['identity' => $identity, 'missing' => array_values(array_unique($missing)), 'source' => null];
        }
        if (! $active) {
            return ['identity' => $identity, 'missing' => [], 'source' => null];
        }
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
                $contractId,
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
                $contractId,
                $projectId,
                $recognizedAt,
            );
        } catch (InvalidArgumentException $exception) {
            $allocation = null;
            $missing[] = $exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'allocation_context_coverage'
                : 'allocation_context';
        }
        $identity = [
            ...$identity,
            'holding_id' => $hierarchy?->holdingId,
            'hierarchy_version' => $hierarchy?->version,
        ];
        if ($missing !== [] || $hierarchy === null || $dimension === null || $allocation === null) {
            return ['identity' => $identity, 'missing' => array_values(array_unique($missing)), 'source' => null];
        }

        $counterpartyOrganizationId = $dimension->counterpartyOrganizationId;
        $dimensionRef = [
            'type' => 'contract_dimension',
            'id' => $dimension->eventId,
            'hash' => $dimension->evidenceHash,
        ];
        if ($dimension->currency === null) {
            $dimensionRef['currency_code'] = $dimension->rawCurrency;
        }
        $sourceRefs = [
            [
                'type' => 'approved_act',
                'id' => $actId,
                'version' => $sourceVersion,
            ],
            [
                'type' => 'contract_allocation',
                'id' => $allocation->allocationId,
                'contract_id' => $contractId,
                'version' => $allocation->eventId,
                'hash' => $allocation->evidenceHash,
            ],
            $dimensionRef,
            [
                'type' => 'organization_hierarchy',
                'hash' => $hierarchy->version,
                'evidence_hashes' => $hierarchy->evidenceHashes,
            ],
        ];
        try {
            $amountMinor = $active ? $this->moneyToMinor($amount) : 0;
        } catch (InvalidArgumentException) {
            return ['identity' => $identity, 'missing' => ['amount'], 'source' => null];
        }
        $source = [
            'organization_id' => $organizationId,
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'hierarchy_organization_ids' => $hierarchy->organizationIds,
            'contributor_organization_id' => $organizationId,
            'counterparty_organization_id' => $counterpartyOrganizationId === null ? null : (int) $counterpartyOrganizationId,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'contractor_id' => $dimension->contractorId,
            'contract_status' => $dimension->contractStatus,
            'work_type_category' => $dimension->workTypeCategory,
            'contract_dimension_hash' => $dimension->evidenceHash,
            'allocation_id' => $allocation->allocationId,
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => 'performance_act',
            'source_id' => $actId,
            'source_version' => $sourceVersion,
            'monetary_basis' => 'accepted_accrual',
            'allocated_amount_minor' => $amountMinor,
            'allocated_percentage' => null,
            'contract_amount_minor' => null,
            'currency' => $dimension->currency,
            'currency_source' => $dimension->currency === null
                ? 'unknown_contract_dimension'
                : 'contract_dimension',
            'tax_basis' => 'approved_act_amount',
            'recognized_on' => $recognizedAt->format('Y-m-d'),
            'business_effective_at' => $recognizedAt,
            'source_refs' => [
                ...$sourceRefs,
            ],
        ];
        $missing = $this->projector->missingEvidence($source);

        return ['identity' => $identity, 'missing' => $missing, 'source' => $missing === [] ? $source : null];
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
