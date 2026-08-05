<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryRiskGrain;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryDemandSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryReorderPolicyVersion;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseDailyBalanceRow;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseDailyBalanceSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\Support\Reporting\OwnerSnapshotSourceHash;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\ReportSourceAccessPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WarehouseDailyBalanceMaterializer
{
    public function __construct(
        private OwnerSnapshotSourceHash $sourceHashes,
        private ReportSourceAccessPolicy $sourceAccess,
        private InventoryRiskGrainUniverse $grainUniverse,
        private OwnerReportFilterApplier $filters,
        private InventoryRiskPeriod $period,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): WarehouseDailyBalanceSnapshot {
        $this->assertScope($context, $query);
        $allowedWarehouseIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'warehouse',
        );
        [$fromDate, $toDate] = $this->period($query);
        $periodEnd = (new DateTimeImmutable(
            $toDate.' 23:59:59.999999',
            $query->scope->timezone,
        ))->setTimezone(new DateTimeZone('UTC'));
        $eventCutoff = $query->asOf < $periodEnd ? $query->asOf : $periodEnd;
        $events = WarehouseInventoryEvent::query()
            ->join('materials as balance_event_filter_material', 'balance_event_filter_material.id', '=', 'warehouse_inventory_events.material_id')
            ->where('warehouse_inventory_events.organization_id', $context->scope->organizationId)
            ->where('warehouse_inventory_events.occurred_at', '<=', $eventCutoff)
            ->when(
                $allowedWarehouseIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'warehouse_id',
                    $allowedWarehouseIds,
                ),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'project_id',
                    $context->scope->projectIds,
                ),
            )
            ;
        $this->applySourceFilters(
            $events,
            $query,
            'warehouse_inventory_events',
            'balance_event_filter_material',
        );
        $events = $events
            ->select('warehouse_inventory_events.*')
            ->orderBy('warehouse_inventory_events.occurred_at')
            ->orderBy('warehouse_inventory_events.id')
            ->get();
        $grains = $this->grainUniverse->collect(
            $context->scope->organizationId,
            $eventCutoff,
            $allowedWarehouseIds,
            $context->scope->projectIds,
            $query->filters,
            $events,
        );
        $planningSourceHashes = [
            ...InventoryDemandSnapshot::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('effective_from', '<=', $eventCutoff)
                ->where(fn (Builder $builder): Builder => $builder
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $eventCutoff))
                ->when(
                    $allowedWarehouseIds !== null,
                    static fn (Builder $builder): Builder => $builder->where(
                        static fn (Builder $scope): Builder => $scope
                            ->whereNull('warehouse_id')
                            ->orWhereIn('warehouse_id', $allowedWarehouseIds),
                    ),
                )
                ->when(
                    $context->scope->projectIds !== [],
                    static fn (Builder $builder): Builder => $builder->where(
                        static fn (Builder $scope): Builder => $scope
                            ->whereNull('project_id')
                            ->orWhereIn('project_id', $context->scope->projectIds),
                    ),
                )
                ->pluck('source_hash')
                ->all(),
            ...InventoryReorderPolicyVersion::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('effective_from', '<=', $eventCutoff)
                ->where(fn (Builder $builder): Builder => $builder
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $eventCutoff))
                ->when(
                    $allowedWarehouseIds !== null,
                    static fn (Builder $builder): Builder => $builder->where(
                        static fn (Builder $scope): Builder => $scope
                            ->whereNull('warehouse_id')
                            ->orWhereIn('warehouse_id', $allowedWarehouseIds),
                    ),
                )
                ->when(
                    $context->scope->projectIds !== [],
                    static fn (Builder $builder): Builder => $builder->where(
                        static fn (Builder $scope): Builder => $scope
                            ->whereNull('project_id')
                            ->orWhereIn('project_id', $context->scope->projectIds),
                    ),
                )
                ->pluck('source_hash')
                ->all(),
        ];
        $sourceHash = $this->sourceHashes->make(
            $query->canonicalJson,
            [...$events->pluck('source_hash')->all(), ...$planningSourceHashes],
        );
        $existing = WarehouseDailyBalanceSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof WarehouseDailyBalanceSnapshot) {
            $progress->advance(max($progress->percent(), 45));

            return $existing;
        }

        return DB::transaction(function () use (
            $context,
            $events,
            $fromDate,
            $grains,
            $progress,
            $query,
            $sourceHash,
            $toDate,
        ): WarehouseDailyBalanceSnapshot {
            $rows = [];
            $gapCount = 0;
            $eventsByGrain = $events->groupBy(fn (WarehouseInventoryEvent $event): string => $this->grain($event));
            foreach ($grains as $grain) {
                if (! $grain instanceof InventoryRiskGrain) {
                    continue;
                }
                $grainEvents = $eventsByGrain->get($grain->key(), new Collection);
                $eventsByDate = $grainEvents->groupBy(
                    static fn (WarehouseInventoryEvent $event): string => ReportingBalanceDay::resolve(
                        $event->occurred_at,
                        $query->scope->timezone,
                    ),
                );
                $openingOnHand = BigDecimal::zero();
                $reserved = BigDecimal::zero();
                $hasVerifiedOpening = false;
                $unitPriceMinor = null;
                $currency = null;
                $currencySource = null;
                foreach ($this->balanceDates($eventsByDate, $fromDate, $toDate) as $balanceDate) {
                    $dayEvents = $eventsByDate->get($balanceDate, new Collection);
                    $warnings = [];
                    if (! $hasVerifiedOpening) {
                        $hasVerifiedOpening = $this->hasOpeningEvidence($dayEvents);
                        if (! $hasVerifiedOpening) {
                            $warnings[] = 'missing_verified_opening';
                        }
                    }

                    $receipts = BigDecimal::zero();
                    $issues = BigDecimal::zero();
                    $inboundTransfers = BigDecimal::zero();
                    $outboundTransfers = BigDecimal::zero();
                    $returns = BigDecimal::zero();
                    $positiveAdjustments = BigDecimal::zero();
                    $negativeAdjustments = BigDecimal::zero();
                    $closingOnHand = $openingOnHand;
                    foreach ($dayEvents as $event) {
                        $delta = BigDecimal::of((string) $event->on_hand_delta);
                        $reserved = $reserved->plus((string) $event->reserved_delta);
                        $closingOnHand = $closingOnHand->plus($delta);
                        match ((string) $event->event_type) {
                            'receipt' => $receipts = $receipts->plus($delta),
                            'issue', 'reserved_issue' => $issues = $issues->plus($delta->abs()),
                            'transfer_in' => $inboundTransfers = $inboundTransfers->plus($delta),
                            'transfer_out' => $outboundTransfers = $outboundTransfers->plus($delta->abs()),
                            'return' => $returns = $returns->plus($delta),
                            'adjustment' => $delta->isNegative()
                                ? $negativeAdjustments = $negativeAdjustments->plus($delta->abs())
                                : $positiveAdjustments = $positiveAdjustments->plus($delta),
                            'reservation', 'unreservation' => null,
                            default => throw new DomainException('Unsupported warehouse inventory event type.'),
                        };
                        if ($event->unit_price_minor !== null) {
                            $unitPriceMinor = (int) $event->unit_price_minor;
                            $currency = (string) $event->currency;
                            $currencySource = (string) $event->currency_source;
                        }
                    }

                    $available = $closingOnHand->minus($reserved);
                    if ($closingOnHand->isNegative() || $reserved->isNegative() || $available->isNegative()) {
                        $warnings[] = 'negative_replayed_balance';
                    }
                    if ($unitPriceMinor === null || $currency === null || $currencySource === null) {
                        $warnings[] = 'missing_valuation_basis';
                    }

                    if ($balanceDate >= $fromDate) {
                        if ($warnings !== []) {
                            $gapCount++;
                        }
                        $rows[] = [
                            'organization_id' => $context->scope->organizationId,
                            'row_key' => $this->rowKey($grain, $balanceDate),
                            'warehouse_id' => $grain->warehouseId,
                            'project_id' => $grain->projectId,
                            'material_id' => $grain->materialId,
                            'balance_date' => $balanceDate,
                            'opening_on_hand' => $this->quantity($openingOnHand),
                            'receipts' => $this->quantity($receipts),
                            'issues' => $this->quantity($issues),
                            'inbound_transfers' => $this->quantity($inboundTransfers),
                            'outbound_transfers' => $this->quantity($outboundTransfers),
                            'returns' => $this->quantity($returns),
                            'positive_adjustments' => $this->quantity($positiveAdjustments),
                            'negative_adjustments' => $this->quantity($negativeAdjustments),
                            'closing_on_hand' => $this->quantity($closingOnHand),
                            'reserved_quantity' => $this->quantity($reserved),
                            'available_quantity' => $this->quantity($available),
                            'unit_dimension' => $grain->unitDimension,
                            'unit_code' => $grain->unitCode,
                            'conversion_version' => $grain->conversionVersion,
                            'unit_price_minor' => $unitPriceMinor,
                            'currency' => $currency,
                            'currency_source' => $currencySource,
                            'quality_warnings' => array_values(array_unique($warnings)),
                        ];
                    }
                    $openingOnHand = $closingOnHand;
                }
            }

            $snapshot = WarehouseDailyBalanceSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $context->scope->organizationId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'source_hash' => $sourceHash,
                'row_count' => count($rows),
                'gap_count' => $gapCount,
                'reconciliation_status' => $gapCount === 0 ? 'matched' : 'mismatch',
                'generated_at' => new DateTimeImmutable,
            ]);
            foreach ($rows as $row) {
                $row['balance_snapshot_id'] = $snapshot->getKey();
                WarehouseDailyBalanceRow::query()->create($row);
            }
            $progress->advance(max($progress->percent(), 45));

            return $snapshot;
        }, 3);
    }

    private function period(ReportQuery $query): array
    {
        return $this->period->resolve($query);
    }

    private function balanceDates(Collection $eventsByDate, string $fromDate, string $toDate): array
    {
        $dates = [];
        foreach ($eventsByDate->keys() as $eventDate) {
            if (is_string($eventDate) && $eventDate < $fromDate) {
                $dates[$eventDate] = true;
            }
        }

        $cursor = new DateTimeImmutable($fromDate);
        $end = new DateTimeImmutable($toDate);
        while ($cursor <= $end) {
            $dates[$cursor->format('Y-m-d')] = true;
            $cursor = $cursor->modify('+1 day');
        }
        ksort($dates, SORT_STRING);

        return array_keys($dates);
    }

    private function grain(WarehouseInventoryEvent $event): string
    {
        return implode(':', [
            $event->warehouse_id,
            $event->project_id ?? 'null',
            $event->material_id,
            $event->unit_dimension,
            $event->unit_code,
            $event->conversion_version,
        ]);
    }

    private function hasOpeningEvidence(Collection $events): bool
    {
        return $events->contains(
            static fn (WarehouseInventoryEvent $event): bool => in_array(
                $event->opening_basis,
                ['verified_zero', 'opening_inventory', 'prior_verified_closing'],
                true,
            ),
        );
    }

    private function rowKey(InventoryRiskGrain $grain, string $balanceDate): string
    {
        return 'inventory_balance_'.hash('sha256', $grain->key().':'.$balanceDate);
    }

    private function quantity(BigDecimal $quantity): string
    {
        return (string) $quantity->toScale(6, RoundingMode::Unnecessary);
    }

    private function assertScope(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw new DomainException('Report query scope does not match execution scope.');
        }
    }

    private function applySourceFilters(
        Builder $builder,
        ReportQuery $query,
        string $table,
        string $materialAlias,
    ): void {
        $this->filters->apply($builder, $this->filters->only($query->filters, [
            'organization', 'organization_id', 'warehouse', 'warehouse_id', 'project', 'project_id',
            'material', 'material_id', 'category', 'abc', 'xyz',
        ]), [
            'organization' => $table.'.organization_id',
            'organization_id' => $table.'.organization_id',
            'warehouse' => $table.'.warehouse_id',
            'warehouse_id' => $table.'.warehouse_id',
            'project' => $table.'.project_id',
            'project_id' => $table.'.project_id',
            'material' => $table.'.material_id',
            'material_id' => $table.'.material_id',
            'category' => $materialAlias.'.category',
            'abc' => DB::raw($materialAlias.".additional_properties->>'abc_class'"),
            'xyz' => DB::raw($materialAlias.".additional_properties->>'xyz_class'"),
        ]);
    }
}
