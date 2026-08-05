<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationContextSnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingContractDimensionSnapshot;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\Enums\CurrencyCode;
use App\Models\ContractPerformanceAct;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class HoldingPerformanceOptionDimensionQuery
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<int>  $organizationIds
     * @param  list<int>  $projectIds
     * @return array{
     *     complete:bool,
     *     dimensions:list<array{
     *         organization_id:int,
     *         project_id:int,
     *         contractor_id:int|null,
     *         contract_status:string,
     *         currency:string|null,
     *         recognized_on:string
     *     }>
     * }
     */
    public function resolve(
        int $holdingId,
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
    ): array {
        $organizations = $this->identities($organizationIds);
        $projects = $this->identities($projectIds);
        if ($holdingId < 1 || $asOf < $coverageStartedAt || $recordedCutoff < $coverageStartedAt) {
            throw new InvalidArgumentException('holding_performance_period_outside_coverage');
        }

        $dimensions = [];
        foreach ($this->acceptedRows(
            $organizations,
            $projects,
            $coverageStartedAt,
            $asOf,
            $recordedCutoff,
        ) as $row) {
            $dimension = $this->acceptedDimension(
                $row,
                $holdingId,
                $organizations,
                $projects,
                $coverageStartedAt,
            );
            if ($dimension === null) {
                return ['complete' => false, 'dimensions' => []];
            }
            if ($dimension !== []) {
                $dimensions[] = $dimension;
            }
        }
        foreach ($this->paymentRows(
            $organizations,
            $projects,
            $coverageStartedAt,
            $asOf,
            $recordedCutoff,
        ) as $row) {
            $dimension = $this->paymentDimension(
                $row,
                $holdingId,
                $organizations,
                $projects,
                $coverageStartedAt,
            );
            if ($dimension === null) {
                return ['complete' => false, 'dimensions' => []];
            }
            if ($dimension !== []) {
                $dimensions[] = $dimension;
            }
        }

        return ['complete' => true, 'dimensions' => $dimensions];
    }

    /** @return list<object> */
    private function acceptedRows(
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
    ): array {
        $eventAlias = 'accepted_event';
        $query = $this->connection->table('holding_accepted_work_event_versions as '.$eventAlias)
            ->whereIn($eventAlias.'.organization_id', $organizationIds)
            ->whereIn($eventAlias.'.project_id', $projectIds)
            ->whereBetween($eventAlias.'.occurred_at', [$coverageStartedAt, $asOf])
            ->where($eventAlias.'.recorded_at', '<=', $recordedCutoff)
            ->whereNotExists(function (Builder $newer) use ($eventAlias, $asOf, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_accepted_work_event_versions as newer_event')
                    ->whereColumn('newer_event.performance_act_id', $eventAlias.'.performance_act_id')
                    ->where('newer_event.occurred_at', '<=', $asOf)
                    ->where('newer_event.recorded_at', '<=', $recordedCutoff)
                    ->where(fn (Builder $tuple): Builder => $this->newerCaptureTuple(
                        $tuple,
                        'newer_event',
                        $eventAlias,
                    ));
            })
            ->where(function (Builder $relevant) use ($eventAlias, $asOf, $recordedCutoff): void {
                $relevant
                    ->where($eventAlias.'.active', true)
                    ->orWhereExists(function (Builder $prior) use ($eventAlias, $asOf, $recordedCutoff): void {
                        $prior
                            ->selectRaw('1')
                            ->from('holding_accepted_work_event_versions as prior_event')
                            ->whereColumn('prior_event.performance_act_id', $eventAlias.'.performance_act_id')
                            ->where('prior_event.active', true)
                            ->where('prior_event.occurred_at', '<=', $asOf)
                            ->where('prior_event.recorded_at', '<=', $recordedCutoff)
                            ->where(fn (Builder $tuple): Builder => $this->olderCaptureTuple(
                                $tuple,
                                'prior_event',
                                $eventAlias,
                            ));
                    });
            });

        return $this->withPointInTimeDimensions($query, $eventAlias, 'occurred_at')
            ->select($this->dimensionColumns($eventAlias, [
                'id as event_version_id',
                'performance_act_id as source_id',
                'contract_id',
                'project_id',
                'organization_id',
                'active',
                'amount',
                'status',
                'occurred_at as recognized_at',
                'history_complete',
                'source_hash',
            ]))
            ->orderBy($eventAlias.'.id')
            ->get()
            ->all();
    }

    /** @return list<object> */
    private function paymentRows(
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        DateTimeInterface $asOf,
        DateTimeInterface $recordedCutoff,
    ): array {
        $eventAlias = 'payment_event';
        $query = $this->connection->table('holding_payment_transaction_event_versions as '.$eventAlias)
            ->whereIn($eventAlias.'.organization_id', $organizationIds)
            ->where(function (Builder $scope) use ($eventAlias, $projectIds): void {
                $scope
                    ->whereIn($eventAlias.'.project_id', $projectIds)
                    ->orWhere(fn (Builder $gap): Builder => $gap
                        ->whereNull($eventAlias.'.project_id')
                        ->where($eventAlias.'.history_complete', false));
            })
            ->where(function (Builder $window) use ($eventAlias, $coverageStartedAt, $asOf): void {
                $window
                    ->whereBetween($eventAlias.'.recognized_at', [$coverageStartedAt, $asOf])
                    ->orWhere(fn (Builder $gap): Builder => $gap
                        ->where($eventAlias.'.history_complete', false)
                        ->whereBetween($eventAlias.'.occurred_at', [$coverageStartedAt, $asOf]));
            })
            ->where($eventAlias.'.occurred_at', '<=', $asOf)
            ->where($eventAlias.'.recorded_at', '<=', $recordedCutoff)
            ->whereNotExists(function (Builder $newer) use ($eventAlias, $asOf, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_payment_transaction_event_versions as newer_event')
                    ->whereColumn('newer_event.transaction_id', $eventAlias.'.transaction_id')
                    ->where('newer_event.occurred_at', '<=', $asOf)
                    ->where('newer_event.recorded_at', '<=', $recordedCutoff)
                    ->where(fn (Builder $tuple): Builder => $this->newerCaptureTuple(
                        $tuple,
                        'newer_event',
                        $eventAlias,
                    ));
            })
            ->where(function (Builder $relevant) use ($eventAlias, $asOf, $recordedCutoff): void {
                $relevant
                    ->where($eventAlias.'.active', true)
                    ->orWhereExists(function (Builder $prior) use ($eventAlias, $asOf, $recordedCutoff): void {
                        $prior
                            ->selectRaw('1')
                            ->from('holding_payment_transaction_event_versions as prior_event')
                            ->whereColumn('prior_event.transaction_id', $eventAlias.'.transaction_id')
                            ->where('prior_event.active', true)
                            ->where('prior_event.occurred_at', '<=', $asOf)
                            ->where('prior_event.recorded_at', '<=', $recordedCutoff)
                            ->where(fn (Builder $tuple): Builder => $this->olderCaptureTuple(
                                $tuple,
                                'prior_event',
                                $eventAlias,
                            ));
                    });
            });

        return $this->withPointInTimeDimensions($query, $eventAlias, 'recognized_at')
            ->select($this->dimensionColumns($eventAlias, [
                'id as event_version_id',
                'transaction_id as source_id',
                'contract_id',
                'project_id',
                'organization_id',
                'document_organization_id',
                'document_project_id',
                'contract_organization_id',
                'contract_project_id',
                'active',
                'amount',
                'currency as event_currency',
                'status',
                'recognized_at',
                'occurred_at',
                'history_complete',
                'source_hash',
            ]))
            ->orderBy($eventAlias.'.id')
            ->get()
            ->all();
    }

    private function withPointInTimeDimensions(
        Builder $query,
        string $eventAlias,
        string $effectiveColumn,
    ): Builder {
        $hierarchy = $this->connection->table('holding_organization_hierarchy_events as contributor_hierarchy')
            ->select([
                'contributor_hierarchy.organization_id',
                'contributor_hierarchy.parent_organization_id',
                'contributor_hierarchy.is_active',
                'contributor_hierarchy.is_deleted',
            ])
            ->selectRaw(
                'COALESCE(contributor_hierarchy.parent_organization_id, '
                .'contributor_hierarchy.organization_id) AS holding_id',
            )
            ->where($eventAlias.'.active', true)
            ->whereColumn('contributor_hierarchy.organization_id', $eventAlias.'.organization_id')
            ->whereColumn('contributor_hierarchy.observed_at', '<=', $eventAlias.'.'.$effectiveColumn)
            ->orderByDesc('contributor_hierarchy.observed_at')
            ->orderByDesc('contributor_hierarchy.id')
            ->limit(1);
        $hierarchyState = $this->hierarchyState($eventAlias, $effectiveColumn);
        $dimension = $this->connection->table('holding_contract_dimension_events as contract_dimension')
            ->select([
                'contract_dimension.id',
                'contract_dimension.contract_id',
                'contract_dimension.organization_id',
                'contract_dimension.contractor_id',
                'contract_dimension.counterparty_organization_id',
                'contract_dimension.contract_status',
                'contract_dimension.work_type_category',
                'contract_dimension.total_amount',
                'contract_dimension.currency',
                'contract_dimension.is_deleted',
                'contract_dimension.evidence_hash',
            ])
            ->where($eventAlias.'.active', true)
            ->whereColumn('contract_dimension.contract_id', $eventAlias.'.contract_id')
            ->whereColumn('contract_dimension.observed_at', '<=', $eventAlias.'.'.$effectiveColumn)
            ->orderByDesc('contract_dimension.observed_at')
            ->orderByDesc('contract_dimension.id')
            ->limit(1);
        $allocation = $this->connection->table('holding_allocation_context_events as allocation_context')
            ->select([
                'allocation_context.id',
                'allocation_context.allocation_id',
                'allocation_context.contract_id',
                'allocation_context.organization_id',
                'allocation_context.project_id',
                'allocation_context.allocation_type',
                'allocation_context.allocated_amount',
                'allocation_context.allocated_percentage',
                'allocation_context.is_resolvable',
                'allocation_context.is_active',
                'allocation_context.is_deleted',
                'allocation_context.evidence_hash',
            ])
            ->where($eventAlias.'.active', true)
            ->whereColumn('allocation_context.contract_id', $eventAlias.'.contract_id')
            ->whereColumn('allocation_context.project_id', $eventAlias.'.project_id')
            ->whereColumn('allocation_context.observed_at', '<=', $eventAlias.'.'.$effectiveColumn)
            ->whereNotExists(function (Builder $newer) use ($eventAlias, $effectiveColumn): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_allocation_context_events as newer_allocation')
                    ->whereColumn('newer_allocation.allocation_id', 'allocation_context.allocation_id')
                    ->whereColumn('newer_allocation.contract_id', $eventAlias.'.contract_id')
                    ->whereColumn('newer_allocation.project_id', $eventAlias.'.project_id')
                    ->whereColumn('newer_allocation.observed_at', '<=', $eventAlias.'.'.$effectiveColumn)
                    ->where(function (Builder $newerTuple): void {
                        $newerTuple
                            ->whereColumn('newer_allocation.observed_at', '>', 'allocation_context.observed_at')
                            ->orWhere(function (Builder $sameObserved): void {
                                $sameObserved
                                    ->whereColumn(
                                        'newer_allocation.observed_at',
                                        'allocation_context.observed_at',
                                    )
                                    ->whereColumn('newer_allocation.id', '>', 'allocation_context.id');
                            });
                    });
            })
            ->where('allocation_context.is_deleted', false)
            ->where('allocation_context.is_active', true)
            ->orderByDesc('allocation_context.allocation_id')
            ->limit(1);

        return $query
            ->leftJoinLateral($hierarchy, 'event_hierarchy')
            ->leftJoinLateral($hierarchyState, 'hierarchy_state')
            ->leftJoinLateral($dimension, 'event_dimension')
            ->leftJoinLateral($allocation, 'event_allocation');
    }

    private function hierarchyState(string $eventAlias, string $effectiveColumn): Builder
    {
        $candidateIds = $this->connection->table('holding_organization_hierarchy_events as hierarchy_candidate')
            ->select('hierarchy_candidate.organization_id')
            ->whereColumn('hierarchy_candidate.observed_at', '<=', $eventAlias.'.'.$effectiveColumn)
            ->where(function (Builder $scope): void {
                $scope
                    ->whereColumn('hierarchy_candidate.organization_id', 'event_hierarchy.holding_id')
                    ->orWhereColumn(
                        'hierarchy_candidate.parent_organization_id',
                        'event_hierarchy.holding_id',
                    );
            })
            ->distinct();
        $timeline = $this->connection->table('holding_organization_hierarchy_events as latest_hierarchy')
            ->select([
                'latest_hierarchy.organization_id',
                'latest_hierarchy.parent_organization_id',
                'latest_hierarchy.is_active',
                'latest_hierarchy.is_deleted',
                'latest_hierarchy.evidence_hash',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY latest_hierarchy.organization_id '
                .'ORDER BY latest_hierarchy.observed_at DESC, latest_hierarchy.id DESC) AS timeline_position',
            )
            ->whereIn('latest_hierarchy.organization_id', $candidateIds)
            ->whereColumn('latest_hierarchy.observed_at', '<=', $eventAlias.'.'.$effectiveColumn);

        return $this->connection->table('holding_organization_hierarchy_events')
            ->fromSub($timeline, 'historical_hierarchy')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE historical_hierarchy.organization_id = '
                .$eventAlias.'.organization_id) AS contributor_count',
            )
            ->selectRaw(
                'COUNT(*) FILTER (WHERE historical_hierarchy.organization_id = '
                .'event_hierarchy.holding_id) AS root_count',
            )
            ->selectRaw(
                "COALESCE(BOOL_AND(historical_hierarchy.evidence_hash ~ '^[a-f0-9]{64}$'), false) "
                .'AS hierarchy_evidence_valid',
            )
            ->where($eventAlias.'.active', true)
            ->where('historical_hierarchy.timeline_position', 1)
            ->where('historical_hierarchy.is_deleted', false)
            ->where('historical_hierarchy.is_active', true)
            ->where(function (Builder $member): void {
                $member
                    ->whereColumn('historical_hierarchy.organization_id', 'event_hierarchy.holding_id')
                    ->orWhereColumn(
                        'historical_hierarchy.parent_organization_id',
                        'event_hierarchy.holding_id',
                    );
            });
    }

    /** @param list<string> $eventColumns @return list<string> */
    private function dimensionColumns(string $eventAlias, array $eventColumns): array
    {
        return [
            ...array_map(static fn (string $column): string => $eventAlias.'.'.$column, $eventColumns),
            'event_hierarchy.holding_id as historical_holding_id',
            'hierarchy_state.contributor_count',
            'hierarchy_state.root_count',
            'hierarchy_state.hierarchy_evidence_valid',
            'event_dimension.id as dimension_event_id',
            'event_dimension.contract_id as dimension_contract_id',
            'event_dimension.organization_id as dimension_organization_id',
            'event_dimension.contractor_id',
            'event_dimension.counterparty_organization_id',
            'event_dimension.contract_status',
            'event_dimension.work_type_category',
            'event_dimension.total_amount',
            'event_dimension.currency as dimension_currency',
            'event_dimension.is_deleted as dimension_is_deleted',
            'event_dimension.evidence_hash as dimension_evidence_hash',
            'event_allocation.id as allocation_event_id',
            'event_allocation.allocation_id',
            'event_allocation.contract_id as allocation_contract_id',
            'event_allocation.organization_id as allocation_organization_id',
            'event_allocation.project_id as allocation_project_id',
            'event_allocation.allocation_type',
            'event_allocation.allocated_amount',
            'event_allocation.allocated_percentage',
            'event_allocation.is_resolvable as allocation_is_resolvable',
            'event_allocation.is_active as allocation_is_active',
            'event_allocation.is_deleted as allocation_is_deleted',
            'event_allocation.evidence_hash as allocation_evidence_hash',
        ];
    }

    private function acceptedDimension(
        object $row,
        int $holdingId,
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
    ): ?array {
        $organizationId = (int) $row->organization_id;
        $projectId = (int) $row->project_id;
        if (min(
            $organizationId,
            $projectId,
            (int) $row->contract_id,
            (int) $row->source_id,
            (int) $row->event_version_id,
        ) < 1
            || $this->databaseBoolean($row->history_complete) !== true
            || $this->recognizedOn($row->recognized_at) === null
            || ($row->source_hash !== null
                && preg_match('/^[a-f0-9]{64}$/D', (string) $row->source_hash) !== 1)) {
            return null;
        }
        $active = $this->databaseBoolean($row->active);
        if ($active === null) {
            return null;
        }
        if (! $active) {
            return [];
        }
        if (! in_array(
            (string) $row->status,
            [ContractPerformanceAct::STATUS_APPROVED, ContractPerformanceAct::STATUS_SIGNED],
            true,
        ) || ! $this->validMoney((string) $row->amount)) {
            return null;
        }

        return $this->activeDimension(
            $row,
            $holdingId,
            $organizationIds,
            $projectIds,
            $coverageStartedAt,
            false,
            null,
        );
    }

    private function paymentDimension(
        object $row,
        int $holdingId,
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
    ): ?array {
        $organizationId = (int) $row->organization_id;
        $projectId = (int) ($row->project_id ?? 0);
        if (min(
            $organizationId,
            $projectId,
            (int) ($row->contract_id ?? 0),
            (int) $row->source_id,
            (int) $row->event_version_id,
        ) < 1
            || $this->databaseBoolean($row->history_complete) !== true
            || $this->recognizedOn($row->recognized_at) === null
            || (int) ($row->document_organization_id ?? 0) !== $organizationId
            || (int) ($row->contract_organization_id ?? 0) !== $organizationId
            || (int) ($row->document_project_id ?? 0) !== $projectId
            || (int) ($row->contract_project_id ?? 0) !== $projectId
            || preg_match('/^[a-f0-9]{64}$/D', (string) $row->source_hash) !== 1) {
            return null;
        }
        $active = $this->databaseBoolean($row->active);
        if ($active === null) {
            return null;
        }
        if (! $active) {
            return [];
        }

        $rawCurrency = is_string($row->event_currency)
            ? mb_strtoupper(trim($row->event_currency))
            : '';
        if (preg_match('/^[A-Z]{3}$/D', $rawCurrency) !== 1
            || $row->amount === null
            || ! is_numeric($row->amount)
            || ! $this->validMoney((string) $row->amount)
            || ! in_array(
                PaymentTransactionStatus::tryFrom((string) $row->status),
                [PaymentTransactionStatus::COMPLETED, PaymentTransactionStatus::REFUNDED],
                true,
            )) {
            return null;
        }

        return $this->activeDimension(
            $row,
            $holdingId,
            $organizationIds,
            $projectIds,
            $coverageStartedAt,
            true,
            $rawCurrency,
        );
    }

    private function activeDimension(
        object $row,
        int $holdingId,
        array $organizationIds,
        array $projectIds,
        DateTimeInterface $coverageStartedAt,
        bool $requirePercentage,
        ?string $eventCurrency,
    ): ?array {
        $organizationId = (int) $row->organization_id;
        $projectId = (int) $row->project_id;
        if (! in_array($organizationId, $organizationIds, true)
            || ! in_array($projectId, $projectIds, true)
            || (int) ($row->historical_holding_id ?? 0) !== $holdingId
            || (int) ($row->contributor_count ?? 0) < 1
            || (int) ($row->root_count ?? 0) < 1
            || $this->databaseBoolean($row->hierarchy_evidence_valid ?? null) !== true
            || $this->databaseBoolean($row->dimension_is_deleted ?? null) !== false
            || $this->databaseBoolean($row->allocation_is_resolvable ?? null) !== true
            || $this->databaseBoolean($row->allocation_is_active ?? null) !== true
            || $this->databaseBoolean($row->allocation_is_deleted ?? null) !== false) {
            return null;
        }

        try {
            $dimension = new HoldingContractDimensionSnapshot(
                (int) ($row->dimension_event_id ?? 0),
                (int) ($row->dimension_contract_id ?? 0),
                (int) ($row->dimension_organization_id ?? 0),
                $row->contractor_id === null ? null : (int) $row->contractor_id,
                $row->counterparty_organization_id === null
                    ? null
                    : (int) $row->counterparty_organization_id,
                (string) ($row->contract_status ?? ''),
                $row->work_type_category === null ? null : (string) $row->work_type_category,
                $row->total_amount === null ? null : (string) $row->total_amount,
                mb_strtoupper(trim((string) ($row->dimension_currency ?? ''))),
                CurrencyCode::tryFrom(mb_strtoupper(trim((string) ($row->dimension_currency ?? ''))))?->value,
                (string) ($row->dimension_evidence_hash ?? ''),
                $coverageStartedAt->format(DateTimeInterface::ATOM),
            );
            $allocation = new HoldingAllocationContextSnapshot(
                (int) ($row->allocation_event_id ?? 0),
                (int) ($row->allocation_id ?? 0),
                (int) ($row->allocation_contract_id ?? 0),
                (int) ($row->allocation_organization_id ?? 0),
                (int) ($row->allocation_project_id ?? 0),
                (string) ($row->allocation_type ?? ''),
                $row->allocated_amount === null ? null : (string) $row->allocated_amount,
                $row->allocated_percentage === null ? null : (string) $row->allocated_percentage,
                (string) ($row->allocation_evidence_hash ?? ''),
                $coverageStartedAt->format(DateTimeInterface::ATOM),
            );
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($dimension->organizationId !== $organizationId
            || $dimension->contractId !== (int) $row->contract_id
            || $allocation->organizationId !== $organizationId
            || $allocation->contractId !== (int) $row->contract_id
            || $allocation->projectId !== $projectId
            || ($requirePercentage && $allocation->allocatedPercentage === null)
            || ($eventCurrency !== null && $eventCurrency !== $dimension->rawCurrency)) {
            return null;
        }

        $recognizedOn = $this->recognizedOn($row->recognized_at);
        if ($recognizedOn === null) {
            return null;
        }

        return [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contractor_id' => $dimension->contractorId,
            'contract_status' => $dimension->contractStatus,
            'currency' => $dimension->currency,
            'recognized_on' => $recognizedOn,
        ];
    }

    private function recognizedOn(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function validMoney(string $amount): bool
    {
        try {
            BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

            return true;
        } catch (MathException) {
            return false;
        }
    }

    private function databaseBoolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => null,
        };
    }

    /** @return list<int> */
    private function identities(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));
        sort($normalized, SORT_NUMERIC);
        if ($normalized === [] || array_filter($normalized, static fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('holding_performance_scope_invalid');
        }

        return $normalized;
    }

    private function newerCaptureTuple(Builder $query, string $candidate, string $base): Builder
    {
        return $query
            ->whereColumn($candidate.'.recorded_at', '>', $base.'.recorded_at')
            ->orWhere(fn (Builder $sameRecorded): Builder => $sameRecorded
                ->whereColumn($candidate.'.recorded_at', $base.'.recorded_at')
                ->whereColumn($candidate.'.id', '>', $base.'.id'));
    }

    private function olderCaptureTuple(Builder $query, string $candidate, string $base): Builder
    {
        return $query
            ->whereColumn($candidate.'.recorded_at', '<', $base.'.recorded_at')
            ->orWhere(fn (Builder $sameRecorded): Builder => $sameRecorded
                ->whereColumn($candidate.'.recorded_at', $base.'.recorded_at')
                ->whereColumn($candidate.'.id', '<', $base.'.id'));
    }
}
