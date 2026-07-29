<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryBalanceFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryDemandFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryMovementFact;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryDemandSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryReorderPolicyVersion;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskRow;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryRiskSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseDailyBalanceRow;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\Support\Reporting\OwnerSnapshotResultFactory;
use App\Support\Reporting\OwnerSnapshotSourceHash;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class InventoryRiskSnapshotMaterializer
{
    private const KIND = 'inventory_risk';

    private const ROW_SCHEMA = [
        ['id' => 'row_key'],
        ['id' => 'balance_date'],
        ['id' => 'warehouse_id'],
        ['id' => 'project_id'],
        ['id' => 'material_id'],
        ['id' => 'risk_status'],
        ['id' => 'closing_on_hand'],
        ['id' => 'reserved_quantity'],
        ['id' => 'available_quantity'],
        ['id' => 'consumption_quantity'],
        ['id' => 'turnover'],
        ['id' => 'cost_turnover'],
        ['id' => 'days_on_hand'],
        ['id' => 'stockout_at'],
        ['id' => 'consumption_value_minor'],
        ['id' => 'on_hand_value_minor'],
        ['id' => 'currency'],
        ['id' => 'recommended_order_quantity'],
        ['id' => 'quality_warnings'],
    ];

    public function __construct(
        private WarehouseDailyBalanceMaterializer $balances,
        private InventoryRiskFormula $formula,
        private OwnerSnapshotSourceHash $sourceHashes,
        private OwnerSnapshotResultFactory $results,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertScope($context, $query);
        $balanceSnapshot = $this->balances->materialize($context, $query, $progress);
        $balanceRows = WarehouseDailyBalanceRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('balance_snapshot_id', $balanceSnapshot->getKey())
            ->orderBy('balance_date')
            ->orderBy('warehouse_id')
            ->orderBy('project_id')
            ->orderBy('material_id')
            ->orderBy('row_key')
            ->get();
        $events = WarehouseInventoryEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('occurred_at', '<=', $query->asOf)
            ->whereIn('warehouse_id', $balanceRows->pluck('warehouse_id')->unique()->all())
            ->whereIn('material_id', $balanceRows->pluck('material_id')->unique()->all())
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'project_id',
                    $context->scope->projectIds,
                ),
            )
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $demands = InventoryDemandSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(fn ($builder) => $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf))
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->where(
                    static fn (Builder $scope): Builder => $scope
                        ->whereNull('project_id')
                        ->orWhereIn('project_id', $context->scope->projectIds),
                ),
            )
            ->orderByDesc('approved_at')
            ->orderByDesc('source_version')
            ->get();
        $policies = InventoryReorderPolicyVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(fn ($builder) => $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf))
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->where(
                    static fn (Builder $scope): Builder => $scope
                        ->whereNull('project_id')
                        ->orWhereIn('project_id', $context->scope->projectIds),
                ),
            )
            ->orderByDesc('effective_from')
            ->orderByDesc('policy_version')
            ->get();
        $sourceHash = $this->sourceHashes->make(
            $query->canonicalJson,
            [
                $balanceSnapshot->source_hash,
                ...$events->pluck('source_hash')->all(),
                ...$demands->pluck('source_hash')->all(),
                ...$policies->pluck('source_hash')->all(),
            ],
        );
        $existing = InventoryRiskSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof InventoryRiskSnapshot) {
            $progress->advance(100);

            return $this->snapshotRef($query, $existing);
        }

        $snapshot = DB::transaction(function () use (
            $balanceRows,
            $balanceSnapshot,
            $context,
            $demands,
            $events,
            $policies,
            $progress,
            $query,
            $sourceHash,
        ): InventoryRiskSnapshot {
            $rows = [];
            $gapCount = 0;
            $valueByCurrency = [];
            foreach ($balanceRows as $balance) {
                $warnings = is_array($balance->quality_warnings) ? $balance->quality_warnings : [];
                $demand = $this->demandFor($balance, $demands);
                $policy = $this->policyFor($balance, $policies);
                if (! $demand instanceof InventoryDemandSnapshot) {
                    $warnings[] = 'missing_demand_snapshot';
                }
                if (! $policy instanceof InventoryReorderPolicyVersion) {
                    $warnings[] = 'missing_reorder_policy';
                }

                try {
                    $metric = $this->formula->row(
                        $this->openingFact($balance),
                        $this->closingFact($balance),
                        $this->movementFacts($balance, $events),
                        $demand instanceof InventoryDemandSnapshot ? $this->demandFact($demand) : null,
                        $policy?->policy(),
                    );
                    $warnings = array_values(array_unique([...$warnings, ...$metric->qualityWarnings]));
                } catch (Throwable) {
                    $gapCount++;

                    continue;
                }
                if ($warnings !== []) {
                    $gapCount++;
                }
                if ($metric->onHandValueMinor !== null && $metric->currency !== null) {
                    $valueByCurrency[$metric->currency] = ($valueByCurrency[$metric->currency] ?? 0)
                        + $metric->onHandValueMinor;
                }

                $rows[] = [
                    'organization_id' => $context->scope->organizationId,
                    'row_key' => 'inventory_risk_'.hash('sha256', (string) $balance->row_key),
                    'warehouse_id' => $balance->warehouse_id,
                    'project_id' => $balance->project_id,
                    'material_id' => $balance->material_id,
                    'balance_date' => $balance->balance_date,
                    'risk_status' => $this->riskStatus($metric->availableQuantity, $metric->recommendedOrderQuantity, $policy),
                    'opening_on_hand' => $balance->opening_on_hand,
                    'closing_on_hand' => $balance->closing_on_hand,
                    'reserved_quantity' => $balance->reserved_quantity,
                    'available_quantity' => $metric->availableQuantity,
                    'consumption_quantity' => $metric->consumptionQuantity,
                    'turnover' => $metric->turnover,
                    'cost_turnover' => $metric->costTurnover,
                    'days_on_hand' => $metric->daysOnHand,
                    'stockout_at' => $metric->stockoutAt,
                    'consumption_value_minor' => $metric->consumptionValueMinor,
                    'on_hand_value_minor' => $metric->onHandValueMinor,
                    'currency' => $metric->currency,
                    'recommended_order_quantity' => $metric->recommendedOrderQuantity,
                    'unit_dimension' => $balance->unit_dimension,
                    'unit_code' => $balance->unit_code,
                    'conversion_version' => $balance->conversion_version,
                    'demand_snapshot_id' => $demand?->getKey(),
                    'reorder_policy_version_id' => $policy?->getKey(),
                    'quality_warnings' => $warnings,
                ];
            }

            ksort($valueByCurrency, SORT_STRING);
            $generatedAt = new DateTimeImmutable;
            $freshnessTtl = $policies
                ->pluck('freshness_ttl_seconds')
                ->filter(static fn (mixed $value): bool => is_int($value) && $value > 0)
                ->min() ?? 86400;
            $snapshot = InventoryRiskSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $context->scope->organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'scope_hash' => hash('sha256', CanonicalJson::encode($query->scope->canonicalIdentity())),
                'source_hash' => $sourceHash,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'balance_snapshot_id' => $balanceSnapshot->getKey(),
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->modify('+'.(int) $freshnessTtl.' seconds'),
                'row_count' => count($rows),
                'gap_count' => $gapCount,
                'quality_status' => $gapCount === 0 ? 'complete' : 'partial',
                'reconciliation_status' => $gapCount === 0 ? 'matched' : 'mismatch',
                'totals' => [
                    'row_count' => count($rows),
                    'value_by_currency' => $valueByCurrency,
                ],
            ]);
            foreach ($rows as $row) {
                $row['snapshot_id'] = $snapshot->getKey();
                InventoryRiskRow::query()->create($row);
            }
            $progress->advance(100);

            return $snapshot;
        }, 3);

        return $this->snapshotRef($query, $snapshot);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = InventoryRiskSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->firstOrFail();

        return $this->results->result(
            $snapshot,
            (int) $record->row_count,
            (int) $record->gap_count,
            $record->totals,
            self::KIND,
            (string) $record->source_schema_version,
            $record->as_of->format(DATE_ATOM),
            self::ROW_SCHEMA,
            ['drill_down' => true, 'export' => true],
            ReportReconciliationStatus::tryFrom((string) $record->reconciliation_status)
                ?? ReportReconciliationStatus::MISMATCH,
        );
    }

    private function movementFacts(WarehouseDailyBalanceRow $balance, Collection $events): array
    {
        return $events
            ->filter(fn (WarehouseInventoryEvent $event): bool => $this->sameGrainAndDate($balance, $event))
            ->filter(static fn (WarehouseInventoryEvent $event): bool => ! in_array(
                $event->event_type,
                ['reservation', 'unreservation'],
                true,
            ))
            ->map(static function (WarehouseInventoryEvent $event): InventoryMovementFact {
                $type = $event->event_type === 'reserved_issue' ? 'issue' : (string) $event->event_type;

                return new InventoryMovementFact(
                    $type,
                    $type === 'adjustment'
                        ? (string) $event->on_hand_delta
                        : (string) BigDecimal::of((string) $event->on_hand_delta)->abs(),
                    (string) $event->unit_dimension,
                    (string) $event->unit_code,
                    (string) $event->conversion_version,
                    $event->transfer_pair_key,
                    $event->unit_price_minor,
                    $event->currency,
                    $event->currency_source,
                    $event->occurred_at->toDateTimeImmutable(),
                );
            })
            ->values()
            ->all();
    }

    private function sameGrainAndDate(
        WarehouseDailyBalanceRow $balance,
        WarehouseInventoryEvent $event,
    ): bool {
        return (int) $event->warehouse_id === (int) $balance->warehouse_id
            && $event->project_id === $balance->project_id
            && (int) $event->material_id === (int) $balance->material_id
            && $event->unit_dimension === $balance->unit_dimension
            && $event->unit_code === $balance->unit_code
            && $event->conversion_version === $balance->conversion_version
            && $event->occurred_at->format('Y-m-d') === $balance->balance_date->format('Y-m-d');
    }

    private function openingFact(WarehouseDailyBalanceRow $balance): InventoryBalanceFact
    {
        return new InventoryBalanceFact(
            (string) $balance->opening_on_hand,
            '0',
            (string) $balance->unit_dimension,
            (string) $balance->unit_code,
            (string) $balance->conversion_version,
            $balance->unit_price_minor,
            $balance->currency,
            $balance->currency_source,
        );
    }

    private function closingFact(WarehouseDailyBalanceRow $balance): InventoryBalanceFact
    {
        return new InventoryBalanceFact(
            (string) $balance->closing_on_hand,
            (string) $balance->reserved_quantity,
            (string) $balance->unit_dimension,
            (string) $balance->unit_code,
            (string) $balance->conversion_version,
            $balance->unit_price_minor,
            $balance->currency,
            $balance->currency_source,
        );
    }

    private function demandFact(InventoryDemandSnapshot $demand): InventoryDemandFact
    {
        return new InventoryDemandFact(
            (string) $demand->approved_quantity,
            (int) $demand->horizon_days,
            (string) $demand->unit_dimension,
            (string) $demand->unit_code,
            (string) $demand->conversion_version,
            $demand->approved_at->toDateTimeImmutable(),
        );
    }

    private function demandFor(WarehouseDailyBalanceRow $balance, Collection $demands): ?InventoryDemandSnapshot
    {
        return $demands
            ->filter(fn (InventoryDemandSnapshot $demand): bool => $this->appliesToBalance($balance, $demand))
            ->sortByDesc(fn (InventoryDemandSnapshot $demand): int => $this->specificity($demand))
            ->first();
    }

    private function policyFor(
        WarehouseDailyBalanceRow $balance,
        Collection $policies,
    ): ?InventoryReorderPolicyVersion {
        return $policies
            ->filter(fn (InventoryReorderPolicyVersion $policy): bool => $this->appliesToBalance($balance, $policy))
            ->sortByDesc(fn (InventoryReorderPolicyVersion $policy): int => $this->specificity($policy))
            ->first();
    }

    private function appliesToBalance(WarehouseDailyBalanceRow $balance, object $candidate): bool
    {
        return ($candidate->warehouse_id === null || (int) $candidate->warehouse_id === (int) $balance->warehouse_id)
            && ($candidate->project_id === null || $candidate->project_id === $balance->project_id)
            && ($candidate->material_id === null || (int) $candidate->material_id === (int) $balance->material_id)
            && $candidate->unit_dimension === $balance->unit_dimension
            && $candidate->unit_code === $balance->unit_code
            && $candidate->conversion_version === $balance->conversion_version;
    }

    private function specificity(object $candidate): int
    {
        return (int) ($candidate->warehouse_id !== null)
            + (int) ($candidate->project_id !== null)
            + (int) ($candidate->material_id !== null);
    }

    private function riskStatus(
        string $available,
        ?string $recommended,
        ?InventoryReorderPolicyVersion $policy,
    ): string {
        if ($recommended !== null && BigDecimal::of($recommended)->isPositive()) {
            return 'reorder';
        }
        if ($policy !== null && BigDecimal::of($available)->isGreaterThan((string) $policy->target_quantity)) {
            return 'excess';
        }

        return 'healthy';
    }

    private function snapshotRef(ReportQuery $query, InventoryRiskSnapshot $snapshot): ReportSnapshotRef
    {
        return $this->results->snapshot(
            self::KIND,
            (string) $snapshot->getKey(),
            $query->scope,
            $query->definition->definitionHash,
            (string) $snapshot->formula_version,
            (string) $snapshot->source_hash,
            new DateTimeImmutable((string) $snapshot->generated_at),
            $snapshot->stale_at === null ? null : new DateTimeImmutable((string) $snapshot->stale_at),
            ['as_of' => $snapshot->as_of->format(DATE_ATOM)],
        );
    }

    private function assertScope(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw new DomainException('Report query scope does not match execution scope.');
        }
    }
}
