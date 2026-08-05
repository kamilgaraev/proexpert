<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Backfill\SupplyBackfillEvidenceHasher;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityBackfillWatermark;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyReliabilityPeriod;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SupplyReliabilityReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private SupplyBackfillEvidenceHasher $evidence,
        private SupplyReliabilityPeriod $period,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'supply_reliability'
            && $definition->formulaVersion === 'supply-otif.v1';
    }

    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void
    {
        $this->inspect($context, $query)->assertReady('supply_reliability');
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $projects = $context->scope->projectIds;
        $allowedItemIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_order_item',
        );
        $authoritativeItems = DB::table('purchase_order_items as authoritative_item')
            ->join(
                'purchase_orders as authoritative_order',
                'authoritative_order.id',
                '=',
                'authoritative_item.purchase_order_id',
            )
            ->join(
                'purchase_requests as authoritative_request',
                'authoritative_request.id',
                '=',
                'authoritative_order.purchase_request_id',
            )
            ->join(
                'site_requests as authoritative_site_request',
                'authoritative_site_request.id',
                '=',
                'authoritative_request.site_request_id',
            )
            ->where('authoritative_order.organization_id', $context->scope->organizationId)
            ->where('authoritative_request.organization_id', $context->scope->organizationId)
            ->where('authoritative_site_request.organization_id', $context->scope->organizationId)
            ->whereNotNull('authoritative_order.sent_at')
            ->where('authoritative_order.sent_at', '<=', $query->asOf)
            ->when(
                $allowedItemIds !== null,
                static fn ($builder) => $builder->whereIn('authoritative_item.id', $allowedItemIds),
            )
            ->when(
                $projects !== [],
                static fn ($builder) => $builder->whereIn(
                    'authoritative_site_request.project_id',
                    $projects,
                ),
            );
        $authoritativeScopeItemIds = (clone $authoritativeItems)->select('authoritative_item.id');
        $promises = SentPurchaseOrderLineOwner::query()
            ->leftJoin('purchase_order_promise_versions as owner_promise', function ($join): void {
                $join->on(
                    'owner_promise.purchase_order_item_id',
                    '=',
                    'sent_purchase_order_line_owners.purchase_order_item_id',
                )->where('owner_promise.promise_version', 1);
            })
            ->where('sent_purchase_order_line_owners.organization_id', $context->scope->organizationId)
            ->whereIn('sent_purchase_order_line_owners.purchase_order_item_id', $authoritativeScopeItemIds)
            ->where('sent_purchase_order_line_owners.effective_from', '<=', $query->asOf)
            ->when(
                $allowedItemIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'sent_purchase_order_line_owners.purchase_order_item_id',
                    $allowedItemIds,
                ),
            )
            ->when(
                $projects !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'sent_purchase_order_line_owners.project_id',
                    $projects,
                ),
            );
        [$periodStart, $periodEnd] = $this->period->resolve($query);
        $promises->whereBetween('owner_promise.promised_at', [$periodStart, $periodEnd]);
        $reconciliationOwners = clone $promises;
        $authoritativeCohort = clone $authoritativeItems;
        $authoritativeCohort->whereIn(
            'authoritative_item.id',
            (clone $reconciliationOwners)->select(
                'sent_purchase_order_line_owners.purchase_order_item_id',
            ),
        );
        $authoritativeItemIds = (clone $authoritativeCohort)->select('authoritative_item.id');
        $eligible = (clone $authoritativeCohort)->distinct()->count('authoritative_item.id');
        $eligibleMaxItemId = (int) ((clone $authoritativeCohort)->max('authoritative_item.id') ?? 0);
        $eligiblePromiseIds = (clone $reconciliationOwners)
            ->whereNotNull('owner_promise.id')
            ->select('owner_promise.id');
        $owned = (clone $reconciliationOwners)->distinct()->count(
            'sent_purchase_order_line_owners.purchase_order_item_id',
        );
        $projected = (clone $reconciliationOwners)->whereNotNull('owner_promise.id')->distinct()->count(
            'sent_purchase_order_line_owners.purchase_order_item_id',
        );
        $missingOwner = max(0, $eligible - $owned);
        $missingPromise = max(0, $owned - $projected);
        $eligibleItemIds = (clone $authoritativeCohort)->select('authoritative_item.id');
        $missingSent = (clone $reconciliationOwners)
            ->whereNotNull('owner_promise.id')
            ->whereNotExists(function ($builder) use ($context, $query): void {
                $builder->selectRaw('1')
                    ->from('supply_lifecycle_events as readiness_event')
                    ->whereColumn(
                        'readiness_event.promise_version_id',
                        'owner_promise.id',
                    )
                    ->whereColumn(
                        'readiness_event.purchase_order_item_id',
                        'sent_purchase_order_line_owners.purchase_order_item_id',
                    )
                    ->where('readiness_event.organization_id', $context->scope->organizationId)
                    ->where('readiness_event.event_type', 'sent')
                    ->where('readiness_event.occurred_at', '<=', $query->asOf);
            })
            ->count();
        $watermark = SupplyReliabilityBackfillWatermark::query()
            ->where('organization_id', $context->scope->organizationId)
            ->first();
        $expectedCoverage = $watermark instanceof SupplyReliabilityBackfillWatermark
            ? $this->evidence->recompute(
                $context->scope->organizationId,
                (int) $watermark->target_item_id,
                $watermark->target_sent_at ?? new DateTimeImmutable('@0'),
            )
            : null;
        $incompleteBackfill = ! $watermark instanceof SupplyReliabilityBackfillWatermark
            || (int) $watermark->completed_item_id
                < min((int) $watermark->target_item_id, $eligibleMaxItemId)
            || $watermark->coverage_status !== 'complete'
            || (int) $watermark->gap_count > 0
            || ! is_array($expectedCoverage)
            || (int) $watermark->processed_item_count !== $expectedCoverage['processed_count']
            || ! hash_equals(
                (string) $watermark->input_hash,
                (string) $expectedCoverage['input_hash'],
            )
            || ! hash_equals(
                (string) $watermark->output_hash,
                (string) $expectedCoverage['output_hash'],
            )
            ? 1
            : 0;
        $missingReceiptLifecycle = DB::table('purchase_receipt_lines as readiness_line')
            ->join(
                'purchase_order_promise_versions as readiness_promise',
                'readiness_promise.purchase_order_item_id',
                '=',
                'readiness_line.purchase_order_item_id',
            )
            ->where('readiness_promise.organization_id', $context->scope->organizationId)
            ->whereIn('readiness_promise.id', $eligiblePromiseIds)
            ->whereRaw(
                "NULLIF(readiness_line.metadata->>'reporting_posted_at', '')::timestamptz <= ?",
                [$query->asOf],
            )
            ->whereNotExists(function ($event) use ($context): void {
                $event->selectRaw('1')
                    ->from('supply_lifecycle_events as received_event')
                    ->whereColumn('received_event.promise_version_id', 'readiness_promise.id')
                    ->whereColumn('received_event.source_id', 'readiness_line.id')
                    ->where('received_event.organization_id', $context->scope->organizationId)
                    ->where('received_event.source_type', 'purchase_receipt_line')
                    ->where('received_event.event_type', 'received');
            })
            ->count();
        $missingReversalLifecycle = DB::table('purchase_receipt_lines as readiness_line')
            ->join(
                'purchase_order_promise_versions as readiness_promise',
                'readiness_promise.purchase_order_item_id',
                '=',
                'readiness_line.purchase_order_item_id',
            )
            ->where('readiness_promise.organization_id', $context->scope->organizationId)
            ->whereIn('readiness_promise.id', $eligiblePromiseIds)
            ->whereNotNull('readiness_line.reversed_at')
            ->where('readiness_line.reversed_at', '<=', $query->asOf)
            ->whereNotExists(function ($event) use ($context): void {
                $event->selectRaw('1')
                    ->from('supply_lifecycle_events as reversed_event')
                    ->whereColumn('reversed_event.promise_version_id', 'readiness_promise.id')
                    ->whereColumn('reversed_event.source_id', 'readiness_line.id')
                    ->where('reversed_event.organization_id', $context->scope->organizationId)
                    ->where('reversed_event.source_type', 'purchase_receipt_line')
                    ->where('reversed_event.event_type', 'receipt_reversed');
            })
            ->count();
        $missingReturnLifecycle = DB::table('purchase_receipt_returns as readiness_return')
            ->join(
                'purchase_receipt_lines as readiness_return_line',
                'readiness_return_line.id',
                '=',
                'readiness_return.purchase_receipt_line_id',
            )
            ->join(
                'purchase_order_promise_versions as readiness_return_promise',
                'readiness_return_promise.purchase_order_item_id',
                '=',
                'readiness_return_line.purchase_order_item_id',
            )
            ->where('readiness_return.organization_id', $context->scope->organizationId)
            ->whereIn('readiness_return_promise.id', $eligiblePromiseIds)
            ->where('readiness_return.occurred_at', '<=', $query->asOf)
            ->where(static fn ($builder) => $builder
                ->whereNull('readiness_return.supply_lifecycle_event_id')
                ->orWhereNotExists(static function ($event): void {
                    $event->selectRaw('1')
                        ->from('supply_lifecycle_events as returned_event')
                        ->whereColumn('returned_event.id', 'readiness_return.supply_lifecycle_event_id')
                        ->whereColumn('returned_event.source_type', 'readiness_return.source_type')
                        ->whereColumn('returned_event.source_id', 'readiness_return.source_id')
                        ->whereColumn('returned_event.source_version', 'readiness_return.source_version')
                        ->where('returned_event.event_type', 'returned');
                }))
            ->count();
        $missingOrderLifecycle = (clone $reconciliationOwners)
            ->join(
                'purchase_orders as readiness_order',
                'readiness_order.id',
                '=',
                'sent_purchase_order_line_owners.purchase_order_id',
            )
            ->whereNotNull('owner_promise.id')
            ->where(static function ($builder) use ($query): void {
                $builder
                    ->where(static fn ($confirmed) => $confirmed
                        ->whereNotNull('readiness_order.confirmed_at')
                        ->where('readiness_order.confirmed_at', '<=', $query->asOf)
                        ->whereNotExists(static function ($event): void {
                            $event->selectRaw('1')
                                ->from('supply_lifecycle_events as confirmed_event')
                                ->whereColumn('confirmed_event.promise_version_id', 'owner_promise.id')
                                ->where('confirmed_event.event_type', 'confirmed');
                        }))
                    ->orWhere(static fn ($cancelled) => $cancelled
                        ->whereNotNull('readiness_order.cancelled_at')
                        ->where('readiness_order.cancelled_at', '<=', $query->asOf)
                        ->whereNotExists(static function ($event): void {
                            $event->selectRaw('1')
                                ->from('supply_lifecycle_events as cancelled_event')
                                ->whereColumn('cancelled_event.promise_version_id', 'owner_promise.id')
                                ->where('cancelled_event.event_type', 'cancelled');
                        }));
            })
            ->count();
        $unknown = (clone $reconciliationOwners)
            ->where(function ($builder): void {
                $builder->whereIn('sent_purchase_order_line_owners.unit_dimension', ['', 'unknown'])
                    ->orWhereIn('sent_purchase_order_line_owners.conversion_version', ['', 'unknown', 'unproven'])
                    ->orWhereNull('sent_purchase_order_line_owners.supplier_id')
                    ->orWhereNull('sent_purchase_order_line_owners.warehouse_id')
                    ->orWhereNull('sent_purchase_order_line_owners.material_id')
                    ->orWhereNull('owner_promise.ordered_value_minor')
                    ->orWhereNull('owner_promise.currency')
                    ->orWhereNull('owner_promise.value_basis');
            })
            ->count();
        $lifecycle = SupplyLifecycleEvent::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('purchase_order_item_id', $eligibleItemIds)
            ->whereIn('promise_version_id', $eligiblePromiseIds)
            ->where('occurred_at', '<=', $query->asOf);

        return new SourceReadinessResult(
            $eligible,
            $projected,
            $missingOwner
                + $missingPromise
                + $missingSent
                + $missingReceiptLifecycle
                + $missingReversalLifecycle
                + $missingReturnLifecycle
                + $missingOrderLifecycle
                + $incompleteBackfill,
            $unknown,
            (clone $reconciliationOwners)->where('sent_purchase_order_line_owners.source_version', '<', 1)->count()
                + (clone $lifecycle)->where('source_version', '<', 1)->count(),
            (clone $reconciliationOwners)->whereRaw('LENGTH(sent_purchase_order_line_owners.source_hash) <> 64')->count()
                + (clone $lifecycle)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }

}
