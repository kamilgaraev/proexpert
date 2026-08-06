<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use App\BusinessModules\Features\ContractManagement\Reporting\Enums\ContractSettlementPartyType;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerHistoryCheckpoint;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerVersion;
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\CurrencyCode;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Collection;

use function trans_message;

final readonly class ContractSettlementOwnerSource
{
    public function __construct(private ContractSettlementAllocationConserver $conserver) {}

    /**
     * @return list<ContractSettlementInput>
     */
    public function read(ReportScope $scope, ReportQuery $query): array
    {
        $this->assertSupportedFilters($query->filters->values);
        $this->assertOrganizationFilter($scope, $query->filters->values);
        $owners = $this->historicalOwners($scope, $query);
        $allocations = $owners['contract_allocation'];
        $contracts = $owners['contract']
            ->filter(function (Contract $contract) use ($scope, $allocations): bool {
                $contractAllocations = $allocations->where('contract_id', $contract->id);
                if ($scope->projectIds !== []
                    && ! $contractAllocations->contains(
                        static fn (ContractProjectAllocation $allocation): bool => in_array((int) $allocation->project_id, $scope->projectIds, true),
                    )) {
                    return false;
                }
                $contractIds = $this->resourceIds($scope, 'contract');

                return $contractIds === [] || in_array((int) $contract->id, $contractIds, true);
            })
            ->sortBy('id')
            ->values();

        $result = [];
        foreach ($contracts as $contract) {
            $contractAllocations = $allocations
                ->where('contract_id', $contract->id)
                ->where('is_active', true)
                ->sortBy('id')
                ->values();
            if ($contractAllocations->isEmpty()) {
                throw new DomainException('contract_settlement_allocation_required');
            }
            $result = [...$result, ...$this->contractInputs($contract, $contractAllocations, $query, $owners)];
        }

        return array_values(array_filter(
            $result,
            fn (ContractSettlementInput $input): bool => $this->matchesFilters($input, $query->filters->values),
        ));
    }

    /**
     * @param  Collection<int, ContractProjectAllocation>  $allocations
     * @return list<ContractSettlementInput>
     */
    private function contractInputs(
        Contract $contract,
        Collection $allocations,
        ReportQuery $query,
        array $owners,
    ): array {
        $totalMinor = self::minor((string) ($contract->total_amount ?? '0'));
        $currency = strtoupper((string) ($contract->currency ?? ''));
        if (CurrencyCode::tryFrom($currency) === null) {
            throw new DomainException('contract_settlement_currency_invalid');
        }

        $allActs = $owners['contract_performance_act']
            ->where('contract_id', $contract->id)
            ->where('is_approved', true)
            ->filter(static fn (ContractPerformanceAct $act): bool => $act->act_date->format('Y-m-d') <= $query->asOf->format('Y-m-d')
                && ($act->approval_date === null
                    || $act->approval_date->format('Y-m-d') <= $query->asOf->format('Y-m-d')))
            ->sortBy('id')
            ->values();

        $effective = $this->effectiveAllocations($allocations, $totalMinor, $allActs);
        $allocationIds = array_keys($effective);
        $allocations = $allocations->whereIn('id', $allocationIds)->values();
        $weights = $effective;
        $accepted = array_fill_keys($allocationIds, 0);
        $cash = array_fill_keys($allocationIds, 0);
        $dueAt = array_fill_keys($allocationIds, null);
        $contractRef = $this->sourceRef($contract, 'contract', [
            'organization_id' => (int) $contract->organization_id,
            'total_amount' => (string) $contract->total_amount,
            'currency' => $currency,
            'contract_side_type' => $contract->contract_side_type instanceof ContractSideTypeEnum
                ? $contract->contract_side_type->value
                : (string) $contract->contract_side_type,
            'status' => (string) $contract->status,
        ]);
        $refs = [];
        foreach ($allocations as $allocation) {
            $refs[(int) $allocation->id] = [
                $contractRef,
                $this->sourceRef($allocation, 'contract_allocation', [
                    'contract_id' => (int) $allocation->contract_id,
                    'project_id' => (int) $allocation->project_id,
                    'allocation_type' => $allocation->allocation_type->value,
                    'allocated_amount' => $allocation->allocated_amount,
                    'allocated_percentage' => $allocation->allocated_percentage,
                    'is_active' => (bool) $allocation->is_active,
                ]),
            ];
        }

        $acts = $allActs
            ->when(isset($query->filters->values['period_from']), static fn (Collection $items): Collection => $items
                ->filter(static fn (ContractPerformanceAct $act): bool => $act->act_date->format('Y-m-d') >= (string) $query->filters->values['period_from']))
            ->when(isset($query->filters->values['period_to']), static fn (Collection $items): Collection => $items
                ->filter(static fn (ContractPerformanceAct $act): bool => $act->act_date->format('Y-m-d') <= (string) $query->filters->values['period_to']));
        foreach ($acts as $act) {
            $this->distribute(
                self::minor((string) $act->amount),
                $act->project_id === null ? null : (int) $act->project_id,
                $allocations,
                $weights,
                $accepted,
            );
            foreach ($this->targetAllocationIds($act->project_id, $allocations) as $allocationId) {
                $refs[$allocationId][] = $this->sourceRef($act, 'contract_performance_act', [
                    'contract_id' => (int) $act->contract_id,
                    'project_id' => $act->project_id === null ? null : (int) $act->project_id,
                    'amount' => (string) $act->amount,
                    'act_date' => $act->act_date->format('Y-m-d'),
                    'approval_date' => $act->approval_date?->format('Y-m-d'),
                    'status' => (string) $act->status,
                    'is_approved' => (bool) $act->is_approved,
                ]);
            }
        }

        $documents = $owners['payment_document']
            ->where('organization_id', $contract->organization_id)
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->id)
            ->reject(static fn (PaymentDocument $document): bool => in_array($document->status->value, ['cancelled', 'rejected'], true))
            ->filter(fn (PaymentDocument $document): bool => $this->documentMatchesFilters($document, $query->filters->values))
            ->sortBy('id')
            ->values()
            ->each(function (PaymentDocument $document) use ($owners, $query): void {
                $transactions = $owners['payment_transaction']
                    ->where('payment_document_id', $document->id)
                    ->filter(static fn ($transaction): bool => $transaction->status === PaymentTransactionStatus::COMPLETED
                        && $transaction->transaction_date->format('Y-m-d') <= $query->asOf->format('Y-m-d')
                        && (! isset($query->filters->values['period_from'])
                            || $transaction->transaction_date->format('Y-m-d') >= (string) $query->filters->values['period_from'])
                        && (! isset($query->filters->values['period_to'])
                            || $transaction->transaction_date->format('Y-m-d') <= (string) $query->filters->values['period_to']))
                    ->sortBy('id')
                    ->values();
                $document->setRelation('transactions', $transactions);
            });
        if (($this->firstFilter($query->filters->values, ['instrument', 'instruments']) !== null
                || $this->firstFilter($query->filters->values, ['status', 'statuses']) !== null
                || isset($query->filters->values['due_from'])
                || isset($query->filters->values['due_to'])) && $documents->isEmpty()) {
            return [];
        }
        foreach ($documents as $document) {
            $this->assertDocumentCompatible($contract, $document, $currency);
            $documentAllocationIds = $this->targetAllocationIds($document->project_id, $allocations);
            foreach ($documentAllocationIds as $allocationId) {
                $refs[$allocationId][] = $this->sourceRef(
                    $document,
                    'payment_document',
                    [
                        'contract_id' => (int) $contract->id,
                        'project_id' => $document->project_id === null ? null : (int) $document->project_id,
                        'direction' => $document->direction->value,
                        'currency' => strtoupper((string) $document->currency),
                        'amount' => (string) $document->amount,
                        'status' => $document->status->value,
                        'due_date' => $document->due_date?->format('Y-m-d'),
                    ],
                );
                if ($document->due_date !== null) {
                    $candidate = $document->due_date->toDateTimeImmutable();
                    $dueAt[$allocationId] = $dueAt[$allocationId] === null || $candidate < $dueAt[$allocationId]
                        ? $candidate
                        : $dueAt[$allocationId];
                }
            }
            foreach ($document->transactions as $transaction) {
                if (strtoupper((string) $transaction->currency) !== $currency) {
                    throw new DomainException('contract_settlement_currency_mismatch');
                }
                $this->distribute(
                    self::minor((string) $transaction->amount),
                    $transaction->project_id ?? $document->project_id,
                    $allocations,
                    $weights,
                    $cash,
                );
                foreach ($this->targetAllocationIds($transaction->project_id ?? $document->project_id, $allocations) as $allocationId) {
                    $refs[$allocationId][] = $this->sourceRef(
                        $transaction,
                        'payment_transaction',
                        [
                            'payment_document_id' => (int) $transaction->payment_document_id,
                            'project_id' => $transaction->project_id === null ? null : (int) $transaction->project_id,
                            'amount' => (string) $transaction->amount,
                            'currency' => strtoupper((string) $transaction->currency),
                            'status' => $transaction->status->value,
                            'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
                        ],
                    );
                }
            }
        }

        $direction = $this->direction($contract);
        [$partyId, $partyType] = $this->partyIdentity($contract);
        $partyLabel = $this->partyLabel($contract, $partyType);
        $inputs = [];
        $allowedAllocationIds = $this->resourceIds($query->scope, 'contract_allocation');
        foreach ($allocations as $allocation) {
            $allocationId = (int) $allocation->id;
            if (($query->scope->projectIds !== [] && ! in_array((int) $allocation->project_id, $query->scope->projectIds, true))
                || ($allowedAllocationIds !== [] && ! in_array($allocationId, $allowedAllocationIds, true))) {
                continue;
            }
            $inputs[] = new ContractSettlementInput(
                contractId: (int) $contract->id,
                allocationId: $allocationId,
                projectId: (int) $allocation->project_id,
                partyId: $partyId,
                partyType: $partyType,
                partyLabel: $partyLabel,
                direction: $direction,
                currency: $currency,
                effectiveMinor: $effective[$allocationId],
                acceptedMinor: $accepted[$allocationId],
                cashMinor: $cash[$allocationId],
                dueAt: $dueAt[$allocationId],
                asOf: $query->asOf,
                sourceRefs: $refs[$allocationId],
            );
        }

        return $inputs;
    }

    /**
     * @param  Collection<int, ContractProjectAllocation>  $allocations
     * @param  Collection<int, ContractPerformanceAct>  $acts
     * @return array<int, int>
     */
    private function effectiveAllocations(Collection $allocations, int $contractMinor, Collection $acts): array
    {
        $effective = [];
        $percentageParts = [];
        $autoAllocations = [];
        $percentageTotal = 0;
        foreach ($allocations->sortBy('id') as $allocation) {
            $allocationId = (int) $allocation->id;
            match ($allocation->allocation_type) {
                ContractAllocationTypeEnum::FIXED => $effective[$allocationId] = self::minor((string) ($allocation->allocated_amount ?? '0')),
                ContractAllocationTypeEnum::PERCENTAGE => $percentageParts[$allocationId] = $this->percentageWeight((string) $allocation->allocated_percentage),
                ContractAllocationTypeEnum::AUTO => $autoAllocations[$allocationId] = (int) $allocation->project_id,
                ContractAllocationTypeEnum::CUSTOM => throw new DomainException('contract_settlement_custom_allocation_unsupported'),
            };
        }
        foreach ($percentageParts as $allocationId => $percentage) {
            $percentageTotal += $percentage;
            $effective[$allocationId] = $this->percentageMinor($contractMinor, $percentage);
        }
        if ($percentageTotal > 100_000_000) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        $remaining = $contractMinor - array_sum($effective);
        if ($remaining < 0) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }
        if ($autoAllocations !== []) {
            $weights = [];
            foreach ($autoAllocations as $allocationId => $projectId) {
                $weight = $acts
                    ->where('project_id', $projectId)
                    ->sum(static fn (ContractPerformanceAct $act): int => self::minor((string) $act->amount));
                $weights[$allocationId] = max(0, $weight);
            }
            if (array_sum($weights) === 0) {
                $weights = array_fill_keys(array_keys($autoAllocations), 1);
            }
            foreach ($this->conserver->allocate($remaining, $weights) as $allocationId => $amount) {
                $effective[$allocationId] = $amount;
            }
        } elseif ($remaining !== 0) {
            $percentageCount = count($percentageParts);
            $percentageOnly = count($effective) === $percentageCount;
            if (($percentageOnly && $percentageTotal !== 100_000_000)
                || (! $percentageOnly && $percentageCount === 0)
                || abs($remaining) > max(1, $percentageCount)) {
                throw new DomainException('contract_settlement_allocation_incomplete');
            }
            $finalAllocationId = array_key_last($effective);
            $effective[$finalAllocationId] += $remaining;
        }
        if (array_sum($effective) !== $contractMinor) {
            throw new DomainException('contract_settlement_allocation_not_conserved');
        }
        ksort($effective);

        return $effective;
    }

    /**
     * @param  Collection<int, ContractProjectAllocation>  $allocations
     * @param  array<int, int>  $weights
     * @param  array<int, int>  $target
     */
    private function distribute(int $amount, mixed $projectId, Collection $allocations, array $weights, array &$target): void
    {
        $allocationIds = $this->targetAllocationIds($projectId, $allocations);
        $selectedWeights = array_intersect_key($weights, array_flip($allocationIds));
        foreach ($this->conserver->allocate($amount, $selectedWeights) as $allocationId => $minor) {
            $target[$allocationId] += $minor;
        }
    }

    /**
     * @param  Collection<int, ContractProjectAllocation>  $allocations
     * @return list<int>
     */
    private function targetAllocationIds(mixed $projectId, Collection $allocations): array
    {
        if ($projectId === null) {
            return $allocations->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        }
        $ids = $allocations
            ->where('project_id', (int) $projectId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ids === []) {
            throw new DomainException('contract_settlement_source_project_unallocated');
        }

        return $ids;
    }

    private function assertDocumentCompatible(Contract $contract, PaymentDocument $document, string $contractCurrency): void
    {
        $currency = strtoupper((string) $document->currency);
        if ($currency !== $contractCurrency) {
            throw new DomainException('contract_settlement_currency_mismatch');
        }
        $expected = $this->direction($contract) === 'receivable'
            ? InvoiceDirection::INCOMING
            : InvoiceDirection::OUTGOING;
        if ($document->direction !== $expected) {
            throw new DomainException('contract_settlement_direction_mismatch');
        }
    }

    private function direction(Contract $contract): string
    {
        return $this->contractSideType($contract) === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            ? 'receivable'
            : 'payable';
    }

    private function partyIdentity(Contract $contract): array
    {
        $sideType = $this->contractSideType($contract);
        if ($sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR) {
            return [null, null];
        }

        $partyType = $sideType->requiresSupplier()
            ? ContractSettlementPartyType::SUPPLIER
            : ContractSettlementPartyType::CONTRACTOR;
        $partyId = $partyType === ContractSettlementPartyType::SUPPLIER
            ? $contract->supplier_id
            : $contract->contractor_id;
        if (! is_numeric($partyId) || (int) $partyId < 1) {
            throw new DomainException('contract_settlement_party_required');
        }

        return [(int) $partyId, $partyType];
    }

    private function contractSideType(Contract $contract): ContractSideTypeEnum
    {
        if ($contract->contract_side_type instanceof ContractSideTypeEnum) {
            return $contract->contract_side_type;
        }

        return ContractSideTypeEnum::tryFrom((string) $contract->contract_side_type)
            ?? throw new DomainException('contract_settlement_contract_side_invalid');
    }

    private function partyLabel(Contract $contract, ?ContractSettlementPartyType $partyType): string
    {
        $number = trim((string) $contract->number);
        if ($number === '') {
            throw new DomainException('contract_settlement_contract_number_required');
        }
        $key = match ($partyType) {
            ContractSettlementPartyType::CONTRACTOR => 'contractor',
            ContractSettlementPartyType::SUPPLIER => 'supplier',
            null => 'customer',
        };

        return trans_message(
            'reports.contract_settlement_exposure.party_labels.'.$key,
            ['number' => $number],
            'ru',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(ContractSettlementInput $input, array $filters): bool
    {
        $mapping = [
            [['entity', 'entities', 'entity_ids', 'contract', 'contract_ids'], $input->contractId],
            [['project', 'project_ids'], $input->projectId],
            [['allocation', 'allocation_ids'], $input->allocationId],
            [['party_keys'], $input->partyKey()],
            [['direction', 'directions'], $input->direction],
            [['currency', 'currencies'], $input->currency],
        ];
        foreach ($mapping as [$keys, $actual]) {
            $filter = $this->firstFilter($filters, $keys);
            if ($filter !== null && ! $this->filterContains($filter, $actual)) {
                return false;
            }
        }
        if (isset($filters['due_from']) && ($input->dueAt === null || $input->dueAt < new DateTimeImmutable((string) $filters['due_from']))) {
            return false;
        }
        if (isset($filters['due_to']) && ($input->dueAt === null || $input->dueAt > new DateTimeImmutable((string) $filters['due_to']))) {
            return false;
        }

        return true;
    }

    private function filterContains(mixed $filter, mixed $actual): bool
    {
        $values = is_array($filter) ? $filter : [$filter];

        return in_array($actual, $values, true) || in_array((string) $actual, $values, true);
    }

    /**
     * @return list<int>
     */
    private function resourceIds(ReportScope $scope, string $kind): array
    {
        $ids = [];
        foreach ($scope->resources as $resource) {
            if ($resource->kind === $kind) {
                $ids[] = $resource->id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function filterValues(mixed $filter): array
    {
        return array_map('strval', is_array($filter) ? $filter : [$filter]);
    }

    private function firstFilter(array $filters, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $filters)) {
                return $filters[$key];
            }
        }

        return null;
    }

    private function assertSupportedFilters(array $filters): void
    {
        $supported = array_fill_keys([
            'organization_id',
            'entity', 'entities', 'entity_ids', 'contract', 'contract_ids',
            'project', 'project_ids', 'allocation', 'allocation_ids',
            'party_keys', 'direction', 'directions',
            'currency', 'currencies', 'instrument', 'instruments',
            'status', 'statuses', 'due_from', 'due_to', 'period_from', 'period_to',
            'aging_bucket', 'aging_buckets',
        ], true);
        foreach (array_keys($filters) as $filter) {
            if (! isset($supported[$filter])) {
                throw new DomainException('report_filter_not_sealed');
            }
        }
    }

    private function assertOrganizationFilter(ReportScope $scope, array $filters): void
    {
        if (! array_key_exists('organization_id', $filters)) {
            return;
        }
        $organizationId = $filters['organization_id'];
        if ((! is_int($organizationId) && ! is_string($organizationId))
            || ! ctype_digit((string) $organizationId)
            || (int) $organizationId !== $scope->organizationId) {
            throw new DomainException('report_projection_scope_invalid');
        }
    }

    /**
     * @return array{type: string, id: string, version: int, hash: string}
     */
    private function sourceRef(
        \Illuminate\Database\Eloquent\Model $owner,
        string $type,
        array $payload,
    ): array {
        $version = $owner->getAttribute('_report_owner_version');
        $hash = $owner->getAttribute('_report_owner_hash');
        if (! is_numeric($version)
            || (int) $version < 1
            || ! is_string($hash)
            || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new DomainException('contract_settlement_owner_history_invalid');
        }

        return [
            'type' => $type,
            'id' => (string) $owner->getKey(),
            'version' => (int) $version,
            'hash' => $hash,
            'payload_hash' => hash('sha256', CanonicalJson::encode($payload)),
        ];
    }

    private function documentMatchesFilters(PaymentDocument $document, array $filters): bool
    {
        $instrument = $this->firstFilter($filters, ['instrument', 'instruments']);
        $status = $this->firstFilter($filters, ['status', 'statuses']);
        if ($instrument !== null && ! in_array($document->document_type->value, $this->filterValues($instrument), true)) {
            return false;
        }
        if ($status !== null && ! in_array($document->status->value, $this->filterValues($status), true)) {
            return false;
        }
        if (isset($filters['due_from'])
            && ($document->due_date === null || $document->due_date->format('Y-m-d') < (string) $filters['due_from'])) {
            return false;
        }
        if (isset($filters['due_to'])
            && ($document->due_date === null || $document->due_date->format('Y-m-d') > (string) $filters['due_to'])) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, Collection<int, object>>
     */
    private function historicalOwners(ReportScope $scope, ReportQuery $query): array
    {
        $asOf = ContractSettlementOwnerTimestamp::database($query->asOf);
        $checkpoint = ContractSettlementOwnerHistoryCheckpoint::query()
            ->where('organization_id', $scope->organizationId)
            ->where('completed_at', '<=', $asOf)
            ->first();
        if (! $checkpoint instanceof ContractSettlementOwnerHistoryCheckpoint) {
            throw new DomainException('contract_settlement_owner_history_checkpoint_missing');
        }
        $versions = ContractSettlementOwnerVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('occurred_at', '<=', $asOf)
            ->orderBy('owner_type')
            ->orderBy('owner_id')
            ->orderByDesc('version')
            ->get()
            ->unique(static fn (ContractSettlementOwnerVersion $version): string => $version->owner_type.':'.$version->owner_id);
        if ($versions->isEmpty()) {
            throw new DomainException('contract_settlement_owner_history_missing');
        }

        $classes = [
            'contract' => Contract::class,
            'contract_allocation' => ContractProjectAllocation::class,
            'contract_performance_act' => ContractPerformanceAct::class,
            'payment_document' => PaymentDocument::class,
            'payment_transaction' => \App\BusinessModules\Core\Payments\Models\PaymentTransaction::class,
        ];
        $owners = array_fill_keys(array_keys($classes), null);
        foreach ($owners as $type => $_) {
            $owners[$type] = collect();
        }
        foreach ($versions as $version) {
            if ($version->operation === 'delete') {
                continue;
            }
            $class = $classes[$version->owner_type] ?? null;
            if ($class === null || ! is_array($version->payload)) {
                throw new DomainException('contract_settlement_owner_history_invalid');
            }
            $owner = (new $class)->newFromBuilder($version->payload);
            $owner->setAttribute('_report_owner_version', (int) $version->version);
            $owner->setAttribute('_report_owner_hash', (string) $version->owner_hash);
            $owners[$version->owner_type]->push($owner);
        }

        return $owners;
    }

    private static function minor(string $amount): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new DomainException('contract_settlement_money_invalid');
        }
        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function percentageWeight(string $percentage): int
    {
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,6}))?$/', $percentage, $matches)) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        return ((int) $matches[1] * 1_000_000) + (int) str_pad($matches[2] ?? '', 6, '0');
    }

    private function percentageMinor(int $totalMinor, int $percentage): int
    {
        if ($percentage !== 0 && $totalMinor > intdiv(PHP_INT_MAX, $percentage)) {
            throw new DomainException('contract_settlement_allocation_overflow');
        }

        return intdiv(($totalMinor * $percentage) + 50_000_000, 100_000_000);
    }
}
