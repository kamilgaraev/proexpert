<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyLifecycleFact;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyLineFact;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyReliabilityPolicy;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityPolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityRow;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilitySnapshot;
use App\Support\Reporting\OwnerSnapshotFirstWriter;
use App\Support\Reporting\OwnerSnapshotResultFactory;
use App\Support\Reporting\OwnerSnapshotSourceHash;
use App\Support\Reporting\ReportSourceAccessPolicy;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SupplyReliabilitySnapshotMaterializer
{
    private const KIND = 'supply_reliability';

    private const ROW_SCHEMA = [
        ['id' => 'row_key'],
        ['id' => 'original_promised_at'],
        ['id' => 'purchase_order_id'],
        ['id' => 'purchase_order_item_id'],
        ['id' => 'supplier_id'],
        ['id' => 'ordered_quantity'],
        ['id' => 'net_received_quantity'],
        ['id' => 'delay_bucket'],
        ['id' => 'eligible'],
        ['id' => 'on_time'],
        ['id' => 'in_full'],
        ['id' => 'stable_in_full'],
        ['id' => 'mature'],
        ['id' => 'otif'],
        ['id' => 'quantity_otif_numerator'],
        ['id' => 'quantity_otif_denominator'],
        ['id' => 'value_otif_numerator_minor'],
        ['id' => 'value_otif_denominator_minor'],
        ['id' => 'value_currency'],
        ['id' => 'value_basis'],
        ['id' => 'quality_warnings'],
    ];

    public function __construct(
        private SupplyReliabilityFormula $formula,
        private OwnerSnapshotSourceHash $sourceHashes,
        private OwnerSnapshotResultFactory $results,
        private ReportSourceAccessPolicy $sourceAccess,
        private SupplyReliabilityPeriod $period,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        return OwnerSnapshotFirstWriter::run(
            $query,
            fn (): ReportSnapshotRef => $this->materializeLocked($context, $query, $progress),
        );
    }

    private function materializeLocked(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertScope($context, $query);
        $organizationId = $context->scope->organizationId;
        $allowedItemIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_order_item',
        );
        $ownerQuery = SentPurchaseOrderLineOwner::query()
            ->leftJoin('purchase_order_promise_versions as owner_promise', function ($join): void {
                $join->on(
                    'owner_promise.purchase_order_item_id',
                    '=',
                    'sent_purchase_order_line_owners.purchase_order_item_id',
                )->where('owner_promise.promise_version', 1);
            })
            ->where('sent_purchase_order_line_owners.organization_id', $organizationId)
            ->where('sent_purchase_order_line_owners.effective_from', '<=', $query->asOf)
            ->when(
                $allowedItemIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'sent_purchase_order_line_owners.purchase_order_item_id',
                    $allowedItemIds,
                ),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'sent_purchase_order_line_owners.project_id',
                    $context->scope->projectIds,
                ),
            );
        [$periodStart, $periodEnd] = $this->period->resolve($query);
        $ownerQuery->whereBetween('owner_promise.promised_at', [$periodStart, $periodEnd]);
        $ownerItems = $ownerQuery
            ->select('sent_purchase_order_line_owners.*')
            ->orderBy('sent_purchase_order_line_owners.purchase_order_item_id')
            ->get();
        if ($ownerItems->isEmpty()) {
            return $this->materializeEmpty($organizationId, $query, $progress);
        }
        $policy = SupplyReliabilityPolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(fn ($builder) => $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf))
            ->orderByDesc('effective_from')
            ->orderByDesc('policy_version')
            ->first();
        $resolvedPolicy = $policy?->policy() ?? new SupplyReliabilityPolicy;
        $policySourceHash = $policy?->source_hash ?? hash(
            'sha256',
            CanonicalJson::encode([
                'policy' => 'supply-reliability-default.v1',
                'quantity_tolerance' => $resolvedPolicy->quantityTolerance,
                'on_time_cutoff_seconds' => $resolvedPolicy->onTimeCutoffSeconds,
                'exclude_cancellation_before_send' => $resolvedPolicy->excludeCancellationBeforeSend,
                'post_send_exclusion_reason_codes' => $resolvedPolicy->postSendCancellationExclusionReasons,
                'maturity_seconds' => $resolvedPolicy->maturitySeconds,
            ]),
        );
        $freshnessTtlSeconds = $policy?->freshness_ttl_seconds ?? 86400;
        $promises = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $organizationId)
            ->where('promise_version', 1)
            ->whereIn('purchase_order_item_id', $ownerItems->pluck('purchase_order_item_id'))
            ->orderBy('purchase_order_item_id')
            ->get();
        $promiseIds = $promises->pluck('id')->all();
        $events = SupplyLifecycleEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('promise_version_id', $promiseIds)
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('purchase_order_item_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $eventsByLine = $events->groupBy('purchase_order_item_id');
        if ($query->filters->values !== []) {
            $filteredItemIds = $promises->pluck('purchase_order_item_id')->all();
            $ownerItems = $ownerItems->whereIn('purchase_order_item_id', $filteredItemIds)->values();
        }
        $eligibleSentCount = $ownerItems->unique('purchase_order_item_id')->count();
        $eligibleSentMaxItemId = (int) ($ownerItems->max('purchase_order_item_id') ?? 0);
        $sourceHash = $this->sourceHashes->make(
            $query->canonicalJson,
            [
                $policySourceHash,
                hash('sha256', CanonicalJson::encode([
                    'eligible_sent_count' => $eligibleSentCount,
                    'eligible_sent_max_item_id' => $eligibleSentMaxItemId,
                ])),
                ...$ownerItems->map(static fn ($item): string => hash(
                    'sha256',
                    $item->source_hash,
                ))->all(),
                ...$promises->pluck('source_hash')->all(),
                ...$events->pluck('source_hash')->all(),
            ],
        );
        $progress->advance(max($progress->percent(), 20));
        $existing = SupplyReliabilitySnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof SupplyReliabilitySnapshot) {
            $progress->advance(100);

            return $this->snapshotRef($query, $existing);
        }

        $snapshot = DB::transaction(function () use (
            $eventsByLine,
            $organizationId,
            $policy,
            $resolvedPolicy,
            $freshnessTtlSeconds,
            $progress,
            $promises,
            $query,
            $sourceHash,
            $eligibleSentCount,
        ) {
            $rows = [];
            $metrics = [];
            $gapCount = max(0, $eligibleSentCount - $promises->count());
            $promiseCount = $promises->count();
            foreach ($promises as $promiseIndex => $promise) {
                $progress->advanceProportion($promiseIndex, $promiseCount, 20, 85);
                $lineEvents = $eventsByLine->get($promise->purchase_order_item_id, collect());
                $lineGapCount = 0;
                if (! $lineEvents->contains('event_type', 'sent')) {
                    $lineGapCount++;
                }
                try {
                    $facts = $lineEvents->map(
                        static fn (SupplyLifecycleEvent $event): SupplyLifecycleFact => new SupplyLifecycleFact(
                            (string) $event->event_type,
                            (string) $event->signed_quantity,
                            (string) $event->unit_dimension,
                            (string) $event->unit_code,
                            (string) $event->conversion_version,
                            new DateTimeImmutable((string) $event->occurred_at),
                            (string) $event->getKey(),
                            $event->reversed_event_id === null ? null : (string) $event->reversed_event_id,
                            $event->reason_code,
                        ),
                    )->values()->all();
                    $metric = $this->formula->line(
                        new SupplyLineFact(
                            (string) $promise->ordered_quantity,
                            new DateTimeImmutable((string) $promise->promised_at),
                            (string) $promise->unit_dimension,
                            (string) $promise->unit_code,
                            (string) $promise->conversion_version,
                            $facts,
                            new DateTimeImmutable($query->asOf->format(DATE_ATOM)),
                            $promise->ordered_value_minor === null
                                ? null
                                : (int) $promise->ordered_value_minor,
                            $promise->currency,
                            $promise->value_basis,
                        ),
                        $resolvedPolicy,
                    );
                } catch (Throwable) {
                    $gapCount++;

                    continue;
                }
                $qualifyingReceipt = $this->qualifyingReceipt(
                    $promise,
                    $lineEvents,
                    $resolvedPolicy->quantityTolerance,
                );
                $cancelled = $lineEvents->first(
                    static fn (SupplyLifecycleEvent $event): bool => $event->event_type === 'cancelled',
                );
                $delayBucket = $this->delayBucket($promise, $qualifyingReceipt);
                $gapCount += $lineGapCount;
                $metrics[] = $metric;
                $rows[] = [
                    'organization_id' => $organizationId,
                    'row_key' => 'supply_'.$promise->purchase_order_item_id,
                    'purchase_order_id' => $promise->purchase_order_id,
                    'purchase_order_item_id' => $promise->purchase_order_item_id,
                    'promise_version_id' => $promise->getKey(),
                    'supplier_id' => $promise->supplier_id,
                    'project_id' => $promise->project_id,
                    'warehouse_id' => $promise->warehouse_id,
                    'material_id' => $promise->material_id,
                    'original_promised_at' => $promise->promised_at,
                    'promised_month' => $promise->promised_at->format('Y-m'),
                    'delay_bucket' => $delayBucket,
                    'ordered_quantity' => $promise->ordered_quantity,
                    'net_received_quantity' => $metric->netReceivedQuantity,
                    'unit_dimension' => $promise->unit_dimension,
                    'unit_code' => $promise->unit_code,
                    'conversion_version' => $promise->conversion_version,
                    'first_qualifying_receipt_at' => $qualifyingReceipt?->occurred_at,
                    'cancelled_at' => $cancelled?->occurred_at,
                    'eligible' => $metric->eligible,
                    'on_time' => $metric->onTime,
                    'in_full' => $metric->inFull,
                    'stable_in_full' => $metric->stableInFull,
                    'mature' => $metric->mature,
                    'otif' => $metric->otif,
                    'otif_numerator' => $metric->otifNumerator,
                    'eligible_denominator' => $metric->eligibleDenominator,
                    'quantity_otif_numerator' => $metric->quantityOtifNumerator,
                    'quantity_otif_denominator' => $metric->quantityOtifDenominator,
                    'value_otif_numerator_minor' => $metric->valueOtifNumeratorMinor,
                    'value_otif_denominator_minor' => $metric->valueOtifDenominatorMinor,
                    'value_currency' => $metric->valueCurrency,
                    'value_basis' => $metric->valueBasis,
                    'quality_warnings' => [],
                    'lifecycle_event_ids' => $lineEvents->pluck('id')->map(
                        static fn (mixed $id): int => (int) $id,
                    )->values()->all(),
                ];
            }

            $progress->advance(85);
            $summary = $this->formula->summarize($metrics);
            $generatedAt = new DateTimeImmutable;
            $totals = [
                'otif_numerator' => $summary->otifNumerator,
                'eligible_denominator' => $summary->eligibleDenominator,
                'otif_ratio' => $summary->otifRatio,
                'quantity_otif_numerator' => $summary->quantityOtifNumerator,
                'quantity_otif_denominator' => $summary->quantityOtifDenominator,
                'quantity_otif_ratio' => $summary->quantityOtifRatio,
                'value_otif_numerator_minor' => $summary->valueOtifNumeratorMinor,
                'value_otif_denominator_minor' => $summary->valueOtifDenominatorMinor,
                'value_otif_ratio' => $summary->valueOtifRatio,
                'value_otif_by_basis' => $summary->valueOtifByBasis,
            ];
            $snapshot = SupplyReliabilitySnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'scope_hash' => hash('sha256', CanonicalJson::encode($query->scope->canonicalIdentity())),
                'source_hash' => $sourceHash,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'policy_version_id' => $policy?->getKey(),
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->modify('+'.$freshnessTtlSeconds.' seconds'),
                'row_count' => count($rows),
                'eligible_count' => $summary->eligibleDenominator,
                'otif_numerator' => $summary->otifNumerator,
                'gap_count' => $gapCount,
                'quality_status' => $gapCount === 0 ? 'complete' : 'partial',
                'reconciliation_status' => 'not_applicable',
                'totals' => $totals,
            ]);
            $rowCount = count($rows);
            foreach ($rows as $rowIndex => $row) {
                $progress->advanceProportion($rowIndex, $rowCount, 90, 99);
                $row['snapshot_id'] = $snapshot->getKey();
                SupplyReliabilityRow::query()->create($row);
            }
            $progress->advance(100);

            return $snapshot;
        }, 3);

        return $this->snapshotRef($query, $snapshot);
    }

    private function materializeEmpty(
        int $organizationId,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $sourceHash = $this->sourceHashes->make(
            $query->canonicalJson,
            [hash('sha256', CanonicalJson::encode([
                'source' => self::KIND,
                'state' => 'empty',
                'policy' => 'not_applicable',
            ]))],
        );
        $existing = SupplyReliabilitySnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof SupplyReliabilitySnapshot) {
            $progress->advance(100);

            return $this->snapshotRef($query, $existing);
        }

        $summary = $this->formula->summarize([]);
        $generatedAt = new DateTimeImmutable;
        $snapshot = SupplyReliabilitySnapshot::query()->create([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'definition_hash' => $query->definition->definitionHash->value,
            'query_hash' => $query->queryHash->value,
            'scope_hash' => hash('sha256', CanonicalJson::encode($query->scope->canonicalIdentity())),
            'source_hash' => $sourceHash,
            'formula_version' => $query->definition->formulaVersion,
            'source_schema_version' => $query->definition->sourceSchemaVersion,
            'policy_version_id' => null,
            'as_of' => $query->asOf,
            'generated_at' => $generatedAt,
            'stale_at' => $generatedAt->modify('+86400 seconds'),
            'row_count' => 0,
            'eligible_count' => 0,
            'otif_numerator' => 0,
            'gap_count' => 0,
            'quality_status' => 'complete',
            'reconciliation_status' => 'not_applicable',
            'totals' => [
                'otif_numerator' => $summary->otifNumerator,
                'eligible_denominator' => $summary->eligibleDenominator,
                'otif_ratio' => $summary->otifRatio,
                'quantity_otif_numerator' => $summary->quantityOtifNumerator,
                'quantity_otif_denominator' => $summary->quantityOtifDenominator,
                'quantity_otif_ratio' => $summary->quantityOtifRatio,
                'value_otif_numerator_minor' => $summary->valueOtifNumeratorMinor,
                'value_otif_denominator_minor' => $summary->valueOtifDenominatorMinor,
                'value_otif_ratio' => $summary->valueOtifRatio,
                'value_otif_by_basis' => $summary->valueOtifByBasis,
            ],
        ]);
        $progress->advance(100);

        return $this->snapshotRef($query, $snapshot);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = SupplyReliabilitySnapshot::query()
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
            ReportReconciliationStatus::NOT_APPLICABLE,
        );
    }

    private function delayBucket(
        PurchaseOrderPromiseVersion $promise,
        ?SupplyLifecycleEvent $qualifyingReceipt,
    ): string {
        if ($qualifyingReceipt === null) {
            return 'unreceived';
        }
        $delayDays = intdiv(
            max(0, $qualifyingReceipt->occurred_at->getTimestamp() - $promise->promised_at->getTimestamp()),
            86400,
        );

        return match (true) {
            $delayDays === 0 => 'on_time',
            $delayDays <= 3 => 'delay_1_3',
            $delayDays <= 7 => 'delay_4_7',
            default => 'delay_over_7',
        };
    }

    private function qualifyingReceipt(
        PurchaseOrderPromiseVersion $promise,
        Collection $events,
        string $tolerance,
    ): ?SupplyLifecycleEvent {
        $required = BigDecimal::of((string) $promise->ordered_quantity)->minus($tolerance);
        $net = BigDecimal::zero();
        $qualifying = null;
        foreach ($events as $event) {
            if (! $event instanceof SupplyLifecycleEvent) {
                continue;
            }
            if (in_array($event->event_type, ['received', 'receipt_reversed', 'returned'], true)) {
                $wasQualified = $net->isGreaterThanOrEqualTo($required);
                $net = $net->plus((string) $event->signed_quantity);
                $isQualified = $net->isGreaterThanOrEqualTo($required);
                if (! $wasQualified && $isQualified && $event->event_type === 'received') {
                    $qualifying = $event;
                } elseif (! $isQualified) {
                    $qualifying = null;
                }
            }
        }

        return $qualifying;
    }

    private function snapshotRef(ReportQuery $query, SupplyReliabilitySnapshot $snapshot): ReportSnapshotRef
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
