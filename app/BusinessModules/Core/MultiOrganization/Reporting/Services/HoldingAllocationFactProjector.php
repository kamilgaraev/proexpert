<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingContractVersionEvidence;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Models\Contract;
use App\Models\ContractAllocationHistory;
use App\Models\ContractProjectAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingAllocationFactProjector
{
    public function __construct(private HoldingHierarchyResolver $hierarchies) {}

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
                'source_version' => 1,
                'monetary_basis' => 'contracted',
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
            ], ['contract_version_evidence']);

            return null;
        }
        $type = ContractAllocationTypeEnum::tryFrom((string) ($state['allocation_type'] ?? ''));
        $active = ($state['is_active'] ?? true) !== false && $history->action !== 'deleted';
        if (! $type instanceof ContractAllocationTypeEnum
            || ! in_array($type, [ContractAllocationTypeEnum::FIXED, ContractAllocationTypeEnum::PERCENTAGE], true)) {
            return null;
        }
        try {
            $hierarchy = $this->hierarchies->resolve((int) $contract->organization_id);
        } catch (InvalidArgumentException) {
            $this->recordGap([
                'organization_id' => (int) $contract->organization_id,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
            ], ['hierarchy']);

            return null;
        }
        [$currency, $currencySource] = $this->contractCurrency($contract, $history->created_at);
        if ($currency === null) {
            $this->recordGap([
                'organization_id' => (int) $contract->organization_id,
                'source_type' => 'contract',
                'source_id' => (int) $allocation->getKey(),
                'source_version' => (int) $history->getKey(),
                'monetary_basis' => 'contracted',
            ], ['currency']);

            return null;
        }
        $counterpartyOrganizationId = $contractVersion->counterparty_organization_id;

        $fixedMinor = $type === ContractAllocationTypeEnum::FIXED
            ? $this->moneyToMinor($active ? (string) ($state['allocated_amount'] ?? '0') : '0')
            : null;
        $percentage = $type === ContractAllocationTypeEnum::PERCENTAGE
            ? ($active ? (string) ($state['allocated_percentage'] ?? '0') : '0')
            : null;
        $contractMinor = $this->moneyToMinor((string) $contractVersion->total_amount);
        $source = [
            'organization_id' => (int) $contract->organization_id,
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'hierarchy_organization_ids' => $hierarchy->organizationIds,
            'contributor_organization_id' => (int) $contract->organization_id,
            'counterparty_organization_id' => $counterpartyOrganizationId === null ? null : (int) $counterpartyOrganizationId,
            'project_id' => (int) ($state['project_id'] ?? $history->project_id),
            'contract_id' => (int) $contract->getKey(),
            'allocation_id' => (int) $allocation->getKey(),
            ...$this->linkedEvidence(
                $contract,
                (int) ($state['project_id'] ?? $history->project_id),
                $history->created_at,
            ),
            'source_type' => 'contract',
            'source_id' => (int) $allocation->getKey(),
            'source_version' => (int) $history->getKey(),
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => $fixedMinor,
            'allocated_percentage' => $percentage,
            'contract_amount_minor' => $contractMinor,
            'currency' => $currency,
            'currency_source' => $currencySource,
            'tax_basis' => 'contract_total',
            'recognized_on' => $history->created_at->format('Y-m-d'),
            'source_refs' => [[
                'type' => 'contract_allocation',
                'id' => (int) $allocation->getKey(),
                'contract_id' => (int) $contractVersion->contract_id,
                'version' => (int) $history->getKey(),
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
                    'flow_class' => $fact->flowClass,
                    'allocated_amount_minor' => $allocationEvidence['allocated_amount_minor'] ?? null,
                    'allocated_percentage' => $allocationEvidence['allocated_percentage'] ?? null,
                    'contract_amount_minor' => $allocationEvidence['contract_amount_minor'] ?? null,
                    'source_refs' => $fact->sourceRefs,
                    'source_hash' => $sourceHash,
                    'projected_at' => now(),
                ],
            );
            if (! hash_equals((string) $record->source_hash, $sourceHash)) {
                throw new InvalidArgumentException('holding_allocation_fact_version_conflict');
            }

            HoldingAllocationProjectionGap::query()
                ->where('organization_id', $fact->organizationId)
                ->where('source_type', $fact->sourceType)
                ->where('source_id', $fact->sourceId)
                ->where('source_version', $fact->sourceVersion)
                ->where('monetary_basis', $fact->monetaryBasis)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now()]);

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
                || $source[$field] === null
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
        ?\DateTimeInterface $observedAt = null,
    ): void {
        $organizationId = (int) ($source['organization_id'] ?? 0);
        $sourceId = (int) ($source['source_id'] ?? 0);
        $sourceVersion = (int) ($source['source_version'] ?? 0);
        if (min($organizationId, $sourceId, $sourceVersion) < 1 || $missingFields === []) {
            return;
        }
        try {
            $hierarchy = $this->hierarchies->resolve($organizationId);
            $holdingId = $hierarchy->holdingId;
            $hierarchyVersion = $hierarchy->version;
        } catch (InvalidArgumentException) {
            $holdingId = (int) ($source['holding_id'] ?? $organizationId);
            $hierarchyVersion = (string) ($source['hierarchy_version'] ?? 'unresolved');
        }
        sort($missingFields, SORT_STRING);
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
            ['observed_at' => $observedAt ?? now(), 'resolved_at' => null],
        );
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

    private function contractCurrency(Contract $contract, ?\DateTimeInterface $asOf = null): array
    {
        $currencies = PaymentDocument::query()
            ->where('organization_id', $contract->organization_id)
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->getKey())
            ->when($asOf !== null, static fn ($query) => $query->where('created_at', '<=', $asOf))
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
            || (int) $evidence->organization_id !== (int) $contract->organization_id
            || ! is_numeric($evidence->total_amount)
            || $evidence->recorded_at === null
            || $history->created_at === null) {
            return false;
        }

        return true;
    }

    private function linkedEvidence(Contract $contract, int $projectId, \DateTimeInterface $asOf): array
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
            ->whereHas('history', static fn ($query) => $query->where('created_at', '<=', $asOf))
            ->latest('id')
            ->first();
        $childAllocation = ContractProjectAllocation::withTrashed()
            ->where('contract_id', $contract->getKey())
            ->where('project_id', $projectId)
            ->whereHas('history', static fn ($query) => $query->where('created_at', '<=', $asOf))
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
        $parentMinor = $this->historicalAllocationMinor($parentAllocation, $asOf);
        $childMinor = $this->historicalAllocationMinor($childAllocation, $asOf);

        return [
            'linked_parent_allocation_id' => (int) $parentAllocation->getKey(),
            'linked_incoming_minor' => $parentMinor,
            'linked_outgoing_minor' => $childMinor,
        ];
    }

    private function historicalAllocationMinor(
        ContractProjectAllocation $allocation,
        \DateTimeInterface $asOf,
    ): ?int {
        $history = ContractAllocationHistory::query()
            ->where('allocation_id', $allocation->getKey())
            ->where('created_at', '<=', $asOf)
            ->latest('id')
            ->first();
        if (! $history instanceof ContractAllocationHistory) {
            return null;
        }
        $state = $this->allocationState((int) $allocation->getKey(), (int) $history->getKey());
        if (($state['is_active'] ?? true) === false || $history->action === 'deleted') {
            return 0;
        }
        if (ContractAllocationTypeEnum::tryFrom((string) ($state['allocation_type'] ?? ''))
            !== ContractAllocationTypeEnum::FIXED
            || ! isset($state['allocated_amount'])) {
            return null;
        }

        return $this->moneyToMinor((string) $state['allocated_amount']);
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
