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
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class ContractSettlementOwnerSource
{
    public function __construct(private ContractSettlementAllocationConserver $conserver)
    {
    }

    /**
     * @return list<ContractSettlementInput>
     */
    public function read(ReportScope $scope, ReportQuery $query): array
    {
        $this->assertSupportedFilters($query->filters->values);
        $contracts = Contract::query()
            ->where('organization_id', $scope->organizationId)
            ->where('updated_at', '<=', $query->asOf)
            ->with(['activeAllocations' => static fn (Builder $builder): Builder => $builder
                ->where('updated_at', '<=', $query->asOf)
                ->orderBy('id')])
            ->when($scope->projectIds !== [], static function (Builder $builder) use ($scope): void {
                $builder->whereHas('activeAllocations', static fn (Builder $allocation): Builder => $allocation
                    ->whereIn('project_id', $scope->projectIds));
            })
            ->when($this->resourceIds($scope, 'contract') !== [], fn (Builder $builder): Builder => $builder
                ->whereIn('id', $this->resourceIds($scope, 'contract')))
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($contracts as $contract) {
            $allocations = $contract->activeAllocations;
            if ($allocations->isEmpty()) {
                throw new DomainException('contract_settlement_allocation_required');
            }
            $result = [...$result, ...$this->contractInputs($contract, $allocations, $query)];
        }

        return array_values(array_filter(
            $result,
            fn (ContractSettlementInput $input): bool => $this->matchesFilters($input, $query->filters->values),
        ));
    }

    /**
     * @param Collection<int, ContractProjectAllocation> $allocations
     * @return list<ContractSettlementInput>
     */
    private function contractInputs(Contract $contract, Collection $allocations, ReportQuery $query): array
    {
        $totalMinor = self::minor((string) ($contract->total_amount ?? '0'));
        $weights = [];
        foreach ($allocations as $allocation) {
            $weights[(int) $allocation->id] = $this->allocationWeight($allocation, $totalMinor);
        }
        if ($weights === []) {
            return [];
        }
        $allocations = $allocations->whereIn('id', array_keys($weights))->values();
        $effective = $this->conserver->allocate($totalMinor, $weights);
        $accepted = array_fill_keys(array_keys($weights), 0);
        $cash = array_fill_keys(array_keys($weights), 0);
        $dueAt = array_fill_keys(array_keys($weights), null);
        $refs = [];
        foreach ($allocations as $allocation) {
            $refs[(int) $allocation->id] = [
                $this->sourceRef('contract', (int) $contract->id, (string) $contract->updated_at),
                $this->sourceRef('contract_allocation', (int) $allocation->id, (string) $allocation->updated_at),
            ];
        }

        $acts = ContractPerformanceAct::query()
            ->where('contract_id', $contract->id)
            ->where('is_approved', true)
            ->where('act_date', '<=', $query->asOf->format('Y-m-d'))
            ->where('updated_at', '<=', $query->asOf)
            ->when(isset($query->filters->values['period_from']), static fn (Builder $builder): Builder => $builder
                ->where('act_date', '>=', (string) $query->filters->values['period_from']))
            ->when(isset($query->filters->values['period_to']), static fn (Builder $builder): Builder => $builder
                ->where('act_date', '<=', (string) $query->filters->values['period_to']))
            ->orderBy('id')
            ->get();
        foreach ($acts as $act) {
            $this->distribute(
                self::minor((string) $act->amount),
                $act->project_id === null ? null : (int) $act->project_id,
                $allocations,
                $weights,
                $accepted,
            );
            foreach ($this->targetAllocationIds($act->project_id, $allocations) as $allocationId) {
                $refs[$allocationId][] = $this->sourceRef('contract_performance_act', (int) $act->id, (string) $act->updated_at);
            }
        }

        $documents = PaymentDocument::query()
            ->where('organization_id', $contract->organization_id)
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('created_at', '<=', $query->asOf)
            ->when($this->firstFilter($query->filters->values, ['instrument', 'instruments']) !== null, fn (Builder $builder): Builder => $builder
                ->whereIn('document_type', $this->filterValues($this->firstFilter($query->filters->values, ['instrument', 'instruments']))))
            ->when($this->firstFilter($query->filters->values, ['status', 'statuses']) !== null, fn (Builder $builder): Builder => $builder
                ->whereIn('status', $this->filterValues($this->firstFilter($query->filters->values, ['status', 'statuses']))))
            ->when(isset($query->filters->values['due_from']), static fn (Builder $builder): Builder => $builder
                ->whereDate('due_date', '>=', (string) $query->filters->values['due_from']))
            ->when(isset($query->filters->values['due_to']), static fn (Builder $builder): Builder => $builder
                ->whereDate('due_date', '<=', (string) $query->filters->values['due_to']))
            ->with(['transactions' => static fn (Builder $builder): Builder => $builder
                ->where('status', PaymentTransactionStatus::COMPLETED->value)
                ->where('transaction_date', '<=', $query->asOf->format('Y-m-d'))
                ->when(isset($query->filters->values['period_from']), static fn (Builder $queryBuilder): Builder => $queryBuilder
                    ->where('transaction_date', '>=', (string) $query->filters->values['period_from']))
                ->when(isset($query->filters->values['period_to']), static fn (Builder $queryBuilder): Builder => $queryBuilder
                    ->where('transaction_date', '<=', (string) $query->filters->values['period_to']))
                ->orderBy('id')])
            ->orderBy('id')
            ->get();
        if (($this->firstFilter($query->filters->values, ['instrument', 'instruments']) !== null
                || $this->firstFilter($query->filters->values, ['status', 'statuses']) !== null
                || isset($query->filters->values['due_from'])
                || isset($query->filters->values['due_to'])) && $documents->isEmpty()) {
            return [];
        }
        foreach ($documents as $document) {
            $this->assertDocumentCompatible($contract, $document);
            foreach ($this->targetAllocationIds($document->project_id, $allocations) as $allocationId) {
                $refs[$allocationId][] = $this->sourceRef(
                    'payment_document',
                    (int) $document->id,
                    (string) $document->updated_at,
                );
            }
            foreach ($document->transactions as $transaction) {
                $this->distribute(
                    self::minor((string) $transaction->amount),
                    $transaction->project_id ?? $document->project_id,
                    $allocations,
                    $weights,
                    $cash,
                );
                foreach ($this->targetAllocationIds($transaction->project_id ?? $document->project_id, $allocations) as $allocationId) {
                    $refs[$allocationId][] = $this->sourceRef(
                        'payment_transaction',
                        (int) $transaction->id,
                        (string) $transaction->updated_at,
                    );
                    if ($document->due_date !== null) {
                        $candidate = $document->due_date->toDateTimeImmutable();
                        $dueAt[$allocationId] = $dueAt[$allocationId] === null || $candidate < $dueAt[$allocationId]
                            ? $candidate
                            : $dueAt[$allocationId];
                    }
                }
            }
        }

        $direction = $this->direction($contract);
        $partyId = $contract->contractor_id ?? $contract->supplier_id;
        $inputs = [];
        $allowedAllocationIds = $this->resourceIds($query->scope, 'contract_allocation');
        foreach ($allocations as $allocation) {
            $allocationId = (int) $allocation->id;
            if (($query->scope->projectIds !== [] && !in_array((int) $allocation->project_id, $query->scope->projectIds, true))
                || ($allowedAllocationIds !== [] && !in_array($allocationId, $allowedAllocationIds, true))) {
                continue;
            }
            $inputs[] = new ContractSettlementInput(
                contractId: (int) $contract->id,
                allocationId: $allocationId,
                projectId: (int) $allocation->project_id,
                partyId: $partyId === null ? null : (int) $partyId,
                direction: $direction,
                currency: 'RUB',
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

    private function allocationWeight(ContractProjectAllocation $allocation, int $contractMinor): int
    {
        return match ($allocation->allocation_type) {
            ContractAllocationTypeEnum::FIXED => self::minor((string) ($allocation->allocated_amount ?? '0')),
            ContractAllocationTypeEnum::PERCENTAGE => $this->percentageWeight((string) $allocation->allocated_percentage),
            ContractAllocationTypeEnum::AUTO => 1,
            ContractAllocationTypeEnum::CUSTOM => throw new DomainException('contract_settlement_custom_allocation_unsupported'),
        };
    }

    /**
     * @param Collection<int, ContractProjectAllocation> $allocations
     * @param array<int, int> $weights
     * @param array<int, int> $target
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
     * @param Collection<int, ContractProjectAllocation> $allocations
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

    private function assertDocumentCompatible(Contract $contract, PaymentDocument $document): void
    {
        $currency = strtoupper((string) $document->currency);
        if ($currency !== 'RUB') {
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
        return $contract->contract_side_type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            ? 'receivable'
            : 'payable';
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function matchesFilters(ContractSettlementInput $input, array $filters): bool
    {
        $mapping = [
            [['entity', 'entities', 'entity_ids', 'contract', 'contract_ids'], $input->contractId],
            [['project', 'project_ids'], $input->projectId],
            [['allocation', 'allocation_ids'], $input->allocationId],
            [['party', 'party_ids'], $input->partyId],
            [['direction', 'directions'], $input->direction],
            [['currency', 'currencies'], $input->currency],
        ];
        foreach ($mapping as [$keys, $actual]) {
            $filter = $this->firstFilter($filters, $keys);
            if ($filter !== null && !$this->filterContains($filter, $actual)) {
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
            'entity', 'entities', 'entity_ids', 'contract', 'contract_ids',
            'project', 'project_ids', 'allocation', 'allocation_ids',
            'party', 'party_ids', 'direction', 'directions',
            'currency', 'currencies', 'instrument', 'instruments',
            'status', 'statuses', 'due_from', 'due_to', 'period_from', 'period_to',
            'aging_bucket', 'aging_buckets',
        ], true);
        foreach (array_keys($filters) as $filter) {
            if (!isset($supported[$filter])) {
                throw new DomainException('report_filter_not_sealed');
            }
        }
    }

    /**
     * @return array{type: string, id: string, version: int, hash: string}
     */
    private function sourceRef(string $type, int $id, string $version): array
    {
        return [
            'type' => $type,
            'id' => (string) $id,
            'version' => 1,
            'hash' => hash('sha256', CanonicalJson::encode([
                'id' => $id,
                'type' => $type,
                'updated_at' => $version,
            ])),
        ];
    }

    private static function minor(string $amount): int
    {
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new DomainException('contract_settlement_money_invalid');
        }
        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function percentageWeight(string $percentage): int
    {
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,6}))?$/', $percentage, $matches)) {
            throw new DomainException('contract_settlement_allocation_invalid');
        }

        return ((int) $matches[1] * 1_000_000) + (int) str_pad($matches[2] ?? '', 6, '0');
    }
}
