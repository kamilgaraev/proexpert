<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingContractVersionEvidence;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Models\Contract;
use App\Models\ContractAllocationHistory;
use App\Models\ContractProjectAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingAllocationFactProjector
{
    private const UNKNOWN_SOURCE_VERSION = 0;

    public function __construct(
        private HoldingHierarchyResolver $hierarchies = new HoldingHierarchyResolver,
        private HoldingContractDimensionResolver $contractDimensions = new HoldingContractDimensionResolver,
        private HoldingAllocationContextResolver $allocationContexts = new HoldingAllocationContextResolver,
    ) {}

    public function recordContractAllocation(
        Contract $contract,
        ContractProjectAllocation $allocation,
    ): ?HoldingAllocationFactVersion {
        $history = ContractAllocationHistory::query()
            ->where('allocation_id', $allocation->getKey())
            ->latest('id')
            ->first();
        if (! $history instanceof ContractAllocationHistory || $history->created_at === null) {
            $this->recordGap([
                'organization_id' => (int) $contract->organization_id,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => self::UNKNOWN_SOURCE_VERSION,
                'monetary_basis' => 'contracted',
                'business_effective_at' => $allocation->created_at,
            ], ['allocation_history']);

            return null;
        }

        return $this->recordContractAllocationVersion($contract, $allocation, $history);
    }

    public function recordContractAllocationVersion(
        Contract $contract,
        ContractProjectAllocation $allocation,
        ContractAllocationHistory $history,
    ): ?HoldingAllocationFactVersion {
        if ($history->created_at === null
            || (int) $history->allocation_id !== (int) $allocation->getKey()
            || (int) $history->contract_id !== (int) $contract->getKey()) {
            return null;
        }
        $state = $this->allocationState((int) $allocation->getKey(), (int) $history->getKey());
        $contractVersion = HoldingContractVersionEvidence::query()
            ->where('allocation_history_id', $history->getKey())
            ->first();
        if (! $this->validContractVersion($contractVersion, $contract, $history)) {
            $this->recordGap([
                'organization_id' => (int) $contract->organization_id,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
                'business_effective_at' => $history->created_at,
            ], ['contract_version_evidence']);

            return null;
        }
        $organizationId = (int) $contractVersion->organization_id;
        $type = ContractAllocationTypeEnum::tryFrom((string) ($state['allocation_type'] ?? ''));
        $active = ($state['is_active'] ?? true) !== false && $history->action !== 'deleted';
        if (! $type instanceof ContractAllocationTypeEnum) {
            return null;
        }
        try {
            $hierarchy = $this->hierarchies->resolveAt(
                $organizationId,
                $history->created_at,
            );
        } catch (InvalidArgumentException $exception) {
            $this->recordGap([
                'organization_id' => $organizationId,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
                'business_effective_at' => $history->created_at,
            ], [$exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'hierarchy_coverage'
                : 'hierarchy']);

            return null;
        }
        try {
            $dimension = $this->contractDimensions->resolve(
                $organizationId,
                (int) $contract->getKey(),
                $history->created_at,
            );
        } catch (InvalidArgumentException $exception) {
            $this->recordGap([
                'organization_id' => $organizationId,
                'holding_id' => $hierarchy->holdingId,
                'hierarchy_version' => $hierarchy->version,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
                'business_effective_at' => $history->created_at,
            ], [$exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'contract_dimension_coverage'
                : 'contract_dimensions']);

            return null;
        }
        $dimensionTotal = $dimension->totalAmount;
        if ($dimensionTotal === null
            || ($contractVersion->contractor_id === null ? null : (int) $contractVersion->contractor_id)
                !== $dimension->contractorId
            || ($contractVersion->counterparty_organization_id === null
                ? null
                : (int) $contractVersion->counterparty_organization_id)
                !== $dimension->counterpartyOrganizationId
            || ! BigDecimal::of((string) $contractVersion->total_amount)
                ->isEqualTo(BigDecimal::of($dimensionTotal))) {
            $this->recordGap([
                'organization_id' => $organizationId,
                'holding_id' => $hierarchy->holdingId,
                'hierarchy_version' => $hierarchy->version,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
                'business_effective_at' => $history->created_at,
            ], ['contract_dimension_evidence']);

            return null;
        }
        $projectId = (int) ($state['project_id'] ?? $history->project_id);
        try {
            $allocationContext = $this->allocationContexts->resolve(
                $organizationId,
                (int) $contract->getKey(),
                $projectId,
                $history->created_at,
                allocationId: (int) $allocation->getKey(),
                requireActive: $active,
                requirePercentage: $type !== ContractAllocationTypeEnum::FIXED,
            );
            if ($allocationContext->allocationId !== (int) $allocation->getKey()
                || $allocationContext->allocationType !== $type->value) {
                throw new InvalidArgumentException('holding_allocation_context_unavailable');
            }
            if ($type === ContractAllocationTypeEnum::FIXED) {
                $expectedAmount = BigDecimal::of(
                    $active ? (string) ($state['allocated_amount'] ?? '') : '0',
                );
                if ($allocationContext->allocatedAmount === null
                    || ! BigDecimal::of($allocationContext->allocatedAmount)->isEqualTo($expectedAmount)) {
                    throw new InvalidArgumentException('holding_allocation_context_unavailable');
                }
            } else {
                $expectedPercentage = ! $active
                    ? BigDecimal::of(0)
                    : match ($type) {
                        ContractAllocationTypeEnum::PERCENTAGE => BigDecimal::of(
                            (string) ($state['allocated_percentage'] ?? throw new InvalidArgumentException(
                                'holding_allocation_context_unavailable',
                            )),
                        ),
                        ContractAllocationTypeEnum::AUTO,
                        ContractAllocationTypeEnum::CUSTOM => BigDecimal::of(
                            $allocationContext->allocatedPercentage
                                ?? throw new InvalidArgumentException('holding_allocation_context_unavailable'),
                        ),
                    };
                if ($allocationContext->allocatedPercentage === null
                    || ! BigDecimal::of($allocationContext->allocatedPercentage)->isEqualTo($expectedPercentage)) {
                    throw new InvalidArgumentException('holding_allocation_context_unavailable');
                }
            }
        } catch (InvalidArgumentException|MathException $exception) {
            $this->recordGap([
                'organization_id' => $organizationId,
                'holding_id' => $hierarchy->holdingId,
                'hierarchy_version' => $hierarchy->version,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
                'business_effective_at' => $history->created_at,
            ], [$exception->getMessage() === 'holding_reporting_context_historical_gap'
                ? 'allocation_context_coverage'
                : 'allocation_context']);

            return null;
        }
        $counterpartyOrganizationId = $dimension->counterpartyOrganizationId;

        $fixedMinor = $type === ContractAllocationTypeEnum::FIXED
            ? $this->moneyToMinor($allocationContext->allocatedAmount ?? '0')
            : null;
        $percentage = $type !== ContractAllocationTypeEnum::FIXED
            ? ($active ? $allocationContext->allocatedPercentage : '0')
            : null;
        $contractMinor = $this->moneyToMinor($dimensionTotal);
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
            'allocation_id' => $allocationContext->allocationId,
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => 'contract',
            'source_id' => (int) $allocation->getKey(),
            'source_version' => (int) $history->getKey(),
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => $fixedMinor,
            'allocated_percentage' => $percentage,
            'contract_amount_minor' => $contractMinor,
            'currency' => $dimension->currency,
            'currency_source' => 'contract_dimension',
            'tax_basis' => 'contract_total',
            'recognized_on' => $history->created_at->format('Y-m-d'),
            'business_effective_at' => $history->created_at,
            'source_refs' => [[
                'type' => 'contract_allocation',
                'id' => (int) $allocation->getKey(),
                'contract_id' => (int) $contractVersion->contract_id,
                'version' => (int) $history->getKey(),
            ], [
                'type' => 'allocation_context',
                'id' => $allocationContext->eventId,
                'hash' => $allocationContext->evidenceHash,
            ], [
                'type' => 'contract_dimension',
                'id' => $dimension->eventId,
                'hash' => $dimension->evidenceHash,
            ], [
                'type' => 'organization_hierarchy',
                'hash' => $hierarchy->version,
                'evidence_hashes' => $hierarchy->evidenceHashes,
            ]],
        ];

        $missing = $this->missingEvidence($source);
        if ($missing !== []) {
            $this->recordGap($source, $missing);

            return null;
        }

        return $this->persist($this->project($source), $source);
    }

    public function project(array $source): HoldingAllocationFact
    {
        $missing = $this->missingEvidence($source);
        if ($missing !== []) {
            throw new InvalidArgumentException('holding_allocation_evidence_missing:'.implode(',', $missing));
        }
        $amount = $source['allocated_amount_minor'] ?? null;
        $percentage = $source['allocated_percentage'] ?? null;
        if (($amount === null) === ($percentage === null)) {
            throw new InvalidArgumentException('holding_allocation_method_invalid');
        }
        if ($amount !== null) {
            if (! is_int($amount)) {
                throw new InvalidArgumentException('holding_allocation_amount_invalid');
            }
            $amountMinor = $amount;
        } else {
            $contractAmount = $source['contract_amount_minor'] ?? null;
            if (! is_int($contractAmount) || ! is_numeric($percentage)) {
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
        $currency = mb_strtoupper((string) $source['currency']);

        return new HoldingAllocationFact(
            organizationId: (int) $source['organization_id'],
            holdingId: (int) $source['holding_id'],
            hierarchyVersion: (string) $source['hierarchy_version'],
            contributorOrganizationId: (int) $source['contributor_organization_id'],
            counterpartyOrganizationId: isset($source['counterparty_organization_id']) ? (int) $source['counterparty_organization_id'] : null,
            projectId: (int) $source['project_id'],
            contractId: (int) $source['contract_id'],
            contractorId: isset($source['contractor_id']) ? (int) $source['contractor_id'] : null,
            contractStatus: (string) $source['contract_status'],
            workTypeCategory: isset($source['work_type_category'])
                ? (string) $source['work_type_category']
                : null,
            contractDimensionHash: (string) $source['contract_dimension_hash'],
            allocationId: (int) $source['allocation_id'],
            linkedParentAllocationId: isset($source['linked_parent_allocation_id']) ? (int) $source['linked_parent_allocation_id'] : null,
            linkedIncomingMinor: isset($source['linked_incoming_minor']) ? (int) $source['linked_incoming_minor'] : null,
            linkedOutgoingMinor: isset($source['linked_outgoing_minor']) ? (int) $source['linked_outgoing_minor'] : null,
            sourceType: (string) $source['source_type'],
            sourceId: (int) $source['source_id'],
            sourceVersion: (int) $source['source_version'],
            monetaryBasis: (string) $source['monetary_basis'],
            amountMinor: $amountMinor,
            currency: $currency,
            currencySource: (string) $source['currency_source'],
            taxBasis: (string) $source['tax_basis'],
            recognizedOn: (string) $source['recognized_on'],
            flowClass: $this->classify(
                (int) $source['contributor_organization_id'],
                isset($source['counterparty_organization_id']) ? (int) $source['counterparty_organization_id'] : null,
                $source['hierarchy_organization_ids'],
            ),
            sourceRefs: is_array($source['source_refs'] ?? null) ? array_values($source['source_refs']) : [],
        );
    }

    public function persist(HoldingAllocationFact $fact, array $allocationEvidence): HoldingAllocationFactVersion
    {
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'allocation_evidence' => [
                'allocated_amount_minor' => $allocationEvidence['allocated_amount_minor'] ?? null,
                'allocated_percentage' => $allocationEvidence['allocated_percentage'] ?? null,
                'contract_amount_minor' => $allocationEvidence['contract_amount_minor'] ?? null,
            ],
            'fact' => get_object_vars($fact),
        ]));

        return DB::transaction(function () use ($fact, $allocationEvidence, $sourceHash): HoldingAllocationFactVersion {
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
                    'hierarchy_version' => $fact->hierarchyVersion,
                    'contributor_organization_id' => $fact->contributorOrganizationId,
                    'counterparty_organization_id' => $fact->counterpartyOrganizationId,
                    'project_id' => $fact->projectId,
                    'contract_id' => $fact->contractId,
                    'contractor_id' => $fact->contractorId,
                    'contract_status' => $fact->contractStatus,
                    'work_type_category' => $fact->workTypeCategory,
                    'contract_dimension_hash' => $fact->contractDimensionHash,
                    'allocation_id' => $fact->allocationId,
                    'linked_parent_allocation_id' => $fact->linkedParentAllocationId,
                    'linked_incoming_minor' => $fact->linkedIncomingMinor,
                    'linked_outgoing_minor' => $fact->linkedOutgoingMinor,
                    'source_schema_version' => HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION,
                    'tax_basis' => $fact->taxBasis,
                    'amount_minor' => $fact->amountMinor,
                    'currency' => $fact->currency,
                    'currency_source' => $fact->currencySource,
                    'recognized_on' => $fact->recognizedOn,
                    'business_effective_at' => $allocationEvidence['business_effective_at']
                        ?? $fact->recognizedOn.' 00:00:00+00:00',
                    'flow_class' => $fact->flowClass,
                    'allocated_amount_minor' => $allocationEvidence['allocated_amount_minor'] ?? null,
                    'allocated_percentage' => $allocationEvidence['allocated_percentage'] ?? null,
                    'contract_amount_minor' => $allocationEvidence['contract_amount_minor'] ?? null,
                    'source_refs' => $fact->sourceRefs,
                    'source_hash' => $sourceHash,
                    'recorded_at' => now(),
                ],
            );
            if (! hash_equals((string) $record->source_hash, $sourceHash)) {
                throw new InvalidArgumentException('holding_allocation_fact_version_conflict');
            }

            HoldingAllocationProjectionGap::query()
                ->where('organization_id', $fact->organizationId)
                ->where('source_type', $fact->sourceType)
                ->where('source_id', $fact->sourceId)
                ->whereIn('source_version', self::resolvableGapSourceVersions($fact->sourceVersion))
                ->where('monetary_basis', $fact->monetaryBasis)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_business_effective_at' => $allocationEvidence['business_effective_at']
                        ?? $fact->recognizedOn.' 00:00:00+00:00',
                    'resolved_at' => now(),
                ]);

            return $record;
        });
    }

    public function missingEvidence(array $source): array
    {
        $required = [
            'organization_id',
            'holding_id',
            'hierarchy_version',
            'hierarchy_organization_ids',
            'contributor_organization_id',
            'project_id',
            'contract_id',
            'contractor_id',
            'contract_status',
            'work_type_category',
            'contract_dimension_hash',
            'allocation_id',
            'source_type',
            'source_id',
            'source_version',
            'monetary_basis',
            'currency',
            'currency_source',
            'tax_basis',
            'recognized_on',
        ];
        $missing = [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $source)
                || (! in_array($field, ['contractor_id', 'work_type_category'], true)
                    && $source[$field] === null)
                || $source[$field] === ''
                || ($field === 'hierarchy_organization_ids' && $source[$field] === [])) {
                $missing[] = $field;
            }
        }
        if (isset($source['linked_parent_allocation_id'])) {
            foreach (['linked_incoming_minor', 'linked_outgoing_minor'] as $field) {
                if (! isset($source[$field])) {
                    $missing[] = $field;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    public function recordGap(
        array $source,
        array $missingFields,
        ?DateTimeInterface $observedAt = null,
    ): void {
        $organizationId = (int) ($source['organization_id'] ?? 0);
        $sourceId = (int) ($source['source_id'] ?? 0);
        $sourceVersion = (int) ($source['source_version'] ?? self::UNKNOWN_SOURCE_VERSION);
        if ($organizationId < 1 || $sourceId < 1 || $sourceVersion < 0 || $missingFields === []) {
            return;
        }
        $holdingId = (int) ($source['holding_id'] ?? 0);
        $hierarchyVersion = (string) ($source['hierarchy_version'] ?? '');
        if ($holdingId < 1 || preg_match('/^[a-f0-9]{64}$/D', $hierarchyVersion) !== 1) {
            try {
                $hierarchy = $this->hierarchies->resolve($organizationId);
                $holdingId = $hierarchy->holdingId;
                $hierarchyVersion = $hierarchy->version;
            } catch (InvalidArgumentException) {
                $holdingId = $organizationId;
                $hierarchyVersion = 'unresolved';
            }
        }
        sort($missingFields, SORT_STRING);
        $businessEffectiveAt = self::gapBusinessEffectiveAt($source, $observedAt);
        $recordedAt = now();
        $identity = [
            'organization_id' => $organizationId,
            'holding_id' => $holdingId,
            'hierarchy_version' => $hierarchyVersion,
            'source_type' => (string) ($source['source_type'] ?? 'unknown'),
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'monetary_basis' => (string) ($source['monetary_basis'] ?? 'contracted'),
            'missing_fields' => array_values($missingFields),
        ];
        HoldingAllocationProjectionGap::query()->firstOrCreate(
            [...$identity, 'source_hash' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))],
            [
                'observed_at' => $recordedAt,
                'business_effective_at' => $businessEffectiveAt,
                'recorded_at' => $recordedAt,
                'resolved_business_effective_at' => null,
                'resolved_at' => null,
            ],
        );
    }

    public static function resolvableGapSourceVersions(int $sourceVersion): array
    {
        if ($sourceVersion < 0) {
            throw new InvalidArgumentException('holding_allocation_source_version_invalid');
        }

        return $sourceVersion === self::UNKNOWN_SOURCE_VERSION
            ? [self::UNKNOWN_SOURCE_VERSION]
            : [self::UNKNOWN_SOURCE_VERSION, $sourceVersion];
    }

    public static function gapBusinessEffectiveAt(
        array $source,
        ?DateTimeInterface $observedAt = null,
    ): DateTimeImmutable {
        $value = $source['business_effective_at']
            ?? $source['recognized_on']
            ?? $observedAt;

        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable(is_string($value) && $value !== '' ? $value : '0001-01-01T00:00:00+00:00');
    }

    public function classify(int $contributorOrganizationId, ?int $counterpartyOrganizationId, array $hierarchyOrganizationIds): string
    {
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

    private function allocationState(int $allocationId, int $historyId): array
    {
        $state = [];
        $versions = ContractAllocationHistory::query()
            ->where('allocation_id', $allocationId)
            ->where('id', '<=', $historyId)
            ->orderBy('id')
            ->get(['action', 'new_values']);
        foreach ($versions as $version) {
            if (is_array($version->new_values)) {
                $state = [...$state, ...$version->new_values];
            }
            if ($version->action === 'deleted') {
                $state['is_active'] = false;
            }
        }

        return $state;
    }

    private function validContractVersion(
        mixed $evidence,
        Contract $contract,
        ContractAllocationHistory $history,
    ): bool {
        if (! $evidence instanceof HoldingContractVersionEvidence
            || (int) $evidence->allocation_history_id !== (int) $history->getKey()
            || (int) $evidence->contract_id !== (int) $contract->getKey()
            || (int) $evidence->organization_id < 1
            || ! is_numeric($evidence->total_amount)
            || $evidence->recorded_at === null
            || $history->created_at === null) {
            return false;
        }

        return true;
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
