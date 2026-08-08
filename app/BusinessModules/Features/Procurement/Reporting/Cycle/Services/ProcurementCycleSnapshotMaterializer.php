<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessEvent as ProcessEventData;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTimeline;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleRow;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries\ProcurementCycleFilteredUniverse;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\Support\Reporting\OwnerSnapshotFirstWriter;
use App\Support\Reporting\OwnerSnapshotResultFactory;
use App\Support\Reporting\OwnerSnapshotSourceHash;
use App\Support\Reporting\ReportSourceAccessPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class ProcurementCycleSnapshotMaterializer
{
    private const KIND = 'procurement_cycle';

    private const ROW_SCHEMA = [
        ['id' => 'row_key'],
        ['id' => 'cohort_date'],
        ['id' => 'outcome_cohort_date'],
        ['id' => 'cohort_mature'],
        ['id' => 'outcome_code'],
        ['id' => 'purchase_request_id'],
        ['id' => 'purchase_request_line_id'],
        ['id' => 'stage'],
        ['id' => 'stage_started_at'],
        ['id' => 'closed_at'],
        ['id' => 'total_duration_seconds'],
        ['id' => 'sla_numerator'],
        ['id' => 'sla_denominator'],
        ['id' => 'quality_warnings'],
    ];

    public function __construct(
        private ProcurementCycleFormula $formula,
        private OwnerSnapshotSourceHash $sourceHashes,
        private OwnerSnapshotResultFactory $results,
        private ReportSourceAccessPolicy $sourceAccess,
        private ProcurementCycleFilteredUniverse $universe,
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
        $allowedLineIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_request_line',
        );
        $eligibleLineIds = $this->universe->query($context, $query);
        $eventQuery = ProcurementProcessEvent::query()
            ->where('procurement_process_events.organization_id', $organizationId)
            ->where('procurement_process_events.occurred_at', '<=', $query->asOf)
            ->whereIn('procurement_process_events.purchase_request_line_id', $eligibleLineIds)
            ->when(
                $allowedLineIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'procurement_process_events.purchase_request_line_id',
                    $allowedLineIds,
                ),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'procurement_process_events.project_id',
                    $context->scope->projectIds,
                ),
            );
        $events = $eventQuery
            ->select('procurement_process_events.*')
            ->orderBy('procurement_process_events.purchase_request_line_id')
            ->orderBy('procurement_process_events.occurred_at')
            ->orderBy('procurement_process_events.id')
            ->get();
        if ($events->isEmpty()) {
            return $this->materializeEmpty($organizationId, $query, $progress);
        }
        $policy = ProcurementCyclePolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(fn ($builder) => $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf))
            ->orderByDesc('effective_from')
            ->orderByDesc('policy_version')
            ->first();
        if (! $policy instanceof ProcurementCyclePolicyVersion) {
            throw new DomainException('Procurement cycle policy is unavailable for the requested cutoff.');
        }
        $purchaseOrderIds = $events->pluck('purchase_order_id')->filter()->unique()->values();
        $promises = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $organizationId)
            ->where('promise_version', 1)
            ->where('effective_from', '<=', $query->asOf)
            ->where(static fn (Builder $builder): Builder => $builder
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $query->asOf))
            ->whereIn('purchase_order_id', $purchaseOrderIds)
            ->get();
        $supplyEvents = SupplyLifecycleEvent::query()
            ->where('organization_id', $organizationId)
            ->whereIn('purchase_order_id', $purchaseOrderIds)
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $netByItem = $supplyEvents
            ->groupBy('purchase_order_item_id')
            ->map(static fn ($itemEvents) => $itemEvents->reduce(
                static fn (BigDecimal $total, SupplyLifecycleEvent $event): BigDecimal => $total
                    ->plus((string) $event->signed_quantity),
                BigDecimal::zero(),
            ));
        $incompletePurchaseOrderIds = $promises
            ->groupBy('purchase_order_id')
            ->filter(static function ($orderPromises) use ($netByItem): bool {
                return $orderPromises->contains(static function (PurchaseOrderPromiseVersion $promise) use ($netByItem): bool {
                    $net = $netByItem->get($promise->purchase_order_item_id, BigDecimal::zero());

                    return $net->isLessThan((string) $promise->ordered_quantity);
                });
            })
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $ownerLineByItem = DB::table('sent_purchase_order_line_owners')
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->whereIn('purchase_order_item_id', $promises->pluck('purchase_order_item_id'))
            ->pluck('purchase_request_line_id', 'purchase_order_item_id');
        $incompleteRequestLineIds = $promises
            ->groupBy(static fn (PurchaseOrderPromiseVersion $promise): int => (int) (
                $ownerLineByItem[$promise->purchase_order_item_id] ?? 0
            ))
            ->filter(static function ($linePromises, int $lineId) use ($netByItem): bool {
                return $lineId < 1 || $linePromises->contains(
                    static fn (PurchaseOrderPromiseVersion $promise): bool => $netByItem
                        ->get($promise->purchase_order_item_id, BigDecimal::zero())
                        ->isLessThan((string) $promise->ordered_quantity),
                );
            })
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $incompletePurchaseOrderIds = array_values(array_unique($incompletePurchaseOrderIds));
        $events = $events->reject(
            static fn (ProcurementProcessEvent $event): bool => $event->event_code === 'fully_received'
                && (in_array((int) $event->purchase_request_line_id, $incompleteRequestLineIds, true)
                    || $event->purchase_order_id === null
                    || in_array((int) $event->purchase_order_id, $incompletePurchaseOrderIds, true)),
        )->values();
        $sourceHash = $this->sourceHashes->make(
            $query->canonicalJson,
            [
                $policy->source_hash,
                ...$events->pluck('source_hash')->all(),
                ...$promises->pluck('source_hash')->all(),
                ...$supplyEvents->pluck('source_hash')->all(),
            ],
        );
        $existing = ProcurementCycleSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof ProcurementCycleSnapshot) {
            $progress->advance(100);

            return $this->snapshotRef($query, $existing);
        }

        $snapshot = DB::transaction(function () use ($context, $events, $organizationId, $policy, $progress, $query, $sourceHash) {
            $rows = [];
            $slaNumerator = 0;
            $slaDenominator = 0;
            $gapCount = 0;
            foreach ($events->groupBy('purchase_request_line_id') as $lineEvents) {
                $first = $lineEvents->first();
                $last = $lineEvents->last();
                if (! $first instanceof ProcurementProcessEvent || ! $last instanceof ProcurementProcessEvent) {
                    continue;
                }

                $warnings = [];
                if ($first->event_code !== 'request_created') {
                    $warnings[] = 'missing_request_created_event';
                }
                try {
                    $metric = $this->formula->calculate(
                        ProcurementProcessTimeline::fromEvents(
                            $lineEvents->map(
                                static fn (ProcurementProcessEvent $event): ProcessEventData => new ProcessEventData(
                                    (string) $event->event_code,
                                    new DateTimeImmutable((string) $event->occurred_at),
                                ),
                            )->values()->all(),
                        ),
                        $policy->policy($query->asOf),
                    );
                } catch (Throwable) {
                    $gapCount++;

                    continue;
                }
                if ($warnings !== []) {
                    $gapCount++;
                }
                $slaNumerator += $metric->slaNumerator;
                $slaDenominator += $metric->slaDenominator;
                $timestamps = [];
                foreach ($lineEvents as $event) {
                    $timestamps[$event->event_code] = $event->occurred_at->format(DATE_ATOM);
                }
                $rows[] = [
                    'organization_id' => $organizationId,
                    'row_key' => 'cycle_'.$first->purchase_request_line_id,
                    'purchase_request_id' => $first->purchase_request_id,
                    'purchase_request_line_id' => $first->purchase_request_line_id,
                    'project_id' => $first->project_id,
                    'supplier_request_id' => $last->supplier_request_id,
                    'supplier_proposal_version_id' => $last->supplier_proposal_version_id,
                    'purchase_order_id' => $last->purchase_order_id,
                    'purchase_receipt_id' => $last->purchase_receipt_id,
                    'stage' => $last->stage,
                    'stage_started_at' => $last->occurred_at,
                    'closed_at' => $metric->closed ? $last->occurred_at : null,
                    'cohort_date' => $first->occurred_at->setTimezone($context->scope->timezone)->format('Y-m-d'),
                    'outcome_cohort_date' => $metric->outcomeAt?->setTimezone(
                        $context->scope->timezone,
                    )->format('Y-m-d'),
                    'cohort_mature' => $metric->mature,
                    'outcome_code' => $metric->outcomeCode,
                    'stage_timestamps' => $timestamps,
                    'process_event_ids' => $lineEvents->pluck('id')->map(
                        static fn (mixed $id): int => (int) $id,
                    )->values()->all(),
                    'stage_duration_seconds' => $metric->stageDurationSeconds,
                    'total_duration_seconds' => $metric->totalDurationSeconds,
                    'sla_numerator' => $metric->slaNumerator,
                    'sla_denominator' => $metric->slaDenominator,
                    'quality_warnings' => $warnings,
                ];
            }

            $generatedAt = new DateTimeImmutable;
            $startCohorts = [];
            $outcomeCohorts = [];
            foreach ($rows as $row) {
                $startDate = (string) $row['cohort_date'];
                $startCohorts[$startDate] ??= [
                    'started_count' => 0,
                    'open_count' => 0,
                    'fully_received_count' => 0,
                    'cancelled_count' => 0,
                ];
                $startCohorts[$startDate]['started_count']++;
                $startCohorts[$startDate][$row['outcome_code'].'_count']++;
                if ($row['outcome_cohort_date'] !== null) {
                    $outcomeDate = (string) $row['outcome_cohort_date'];
                    $outcomeCohorts[$outcomeDate] ??= [
                        'outcome_count' => 0,
                        'mature_count' => 0,
                        'fully_received_count' => 0,
                        'cancelled_count' => 0,
                    ];
                    $outcomeCohorts[$outcomeDate]['outcome_count']++;
                    $outcomeCohorts[$outcomeDate][$row['outcome_code'].'_count']++;
                    if ($row['cohort_mature']) {
                        $outcomeCohorts[$outcomeDate]['mature_count']++;
                    }
                }
            }
            ksort($startCohorts, SORT_STRING);
            ksort($outcomeCohorts, SORT_STRING);
            $totals = [
                'row_count' => count($rows),
                'sla_numerator' => $slaNumerator,
                'sla_denominator' => $slaDenominator,
                'sla_ratio' => $slaDenominator === 0
                    ? null
                    : (string) BigDecimal::of($slaNumerator)->dividedBy(
                        BigDecimal::of($slaDenominator),
                        8,
                        RoundingMode::HalfUp,
                    ),
                'start_cohorts' => $startCohorts,
                'outcome_cohorts' => $outcomeCohorts,
            ];
            $snapshot = ProcurementCycleSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'scope_hash' => hash('sha256', CanonicalJson::encode($query->scope->canonicalIdentity())),
                'source_hash' => $sourceHash,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'policy_version_id' => $policy->getKey(),
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->modify('+'.(int) $policy->freshness_ttl_seconds.' seconds'),
                'row_count' => count($rows),
                'eligible_count' => $slaDenominator,
                'sla_numerator' => $slaNumerator,
                'gap_count' => $gapCount,
                'quality_status' => $gapCount === 0 ? 'complete' : 'partial',
                'reconciliation_status' => 'not_applicable',
                'totals' => $totals,
            ]);
            foreach ($rows as $row) {
                $row['snapshot_id'] = $snapshot->getKey();
                ProcurementCycleRow::query()->create($row);
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
        $existing = ProcurementCycleSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof ProcurementCycleSnapshot) {
            $progress->advance(100);

            return $this->snapshotRef($query, $existing);
        }

        $generatedAt = new DateTimeImmutable;
        $snapshot = ProcurementCycleSnapshot::query()->create([
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
            'sla_numerator' => 0,
            'gap_count' => 0,
            'quality_status' => 'complete',
            'reconciliation_status' => 'not_applicable',
            'totals' => [
                'row_count' => 0,
                'sla_numerator' => 0,
                'sla_denominator' => 0,
                'sla_ratio' => null,
                'start_cohorts' => [],
                'outcome_cohorts' => [],
            ],
        ]);
        $progress->advance(100);

        return $this->snapshotRef($query, $snapshot);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = ProcurementCycleSnapshot::query()
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

    private function snapshotRef(ReportQuery $query, ProcurementCycleSnapshot $snapshot): ReportSnapshotRef
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
