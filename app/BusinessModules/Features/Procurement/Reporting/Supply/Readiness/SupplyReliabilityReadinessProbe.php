<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityBackfillWatermark;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityPolicyVersion;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SupplyReliabilityReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private OwnerReportFilterApplier $filters,
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
        $policy = SupplyReliabilityPolicyVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(static fn ($builder) => $builder
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $query->asOf))
            ->orderByDesc('effective_from')
            ->orderByDesc('policy_version')
            ->first();
        $tolerance = $policy instanceof SupplyReliabilityPolicyVersion
            ? (string) $policy->quantity_tolerance
            : '0';
        $promises = SentPurchaseOrderLineOwner::query()
            ->leftJoin('purchase_order_promise_versions as owner_promise', function ($join): void {
                $join->on(
                    'owner_promise.purchase_order_item_id',
                    '=',
                    'sent_purchase_order_line_owners.purchase_order_item_id',
                )->where('owner_promise.promise_version', 1);
            })
            ->where('sent_purchase_order_line_owners.organization_id', $context->scope->organizationId)
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
        $this->filters->apply($promises, $this->filters->only($query->filters, [
            'supplier', 'supplier_id', 'project', 'project_id', 'warehouse', 'warehouse_id',
            'material', 'material_id', 'buyer', 'priority', 'status', 'period', 'promised_month',
            'delay',
        ]), [
            'supplier' => 'sent_purchase_order_line_owners.supplier_id',
            'supplier_id' => 'sent_purchase_order_line_owners.supplier_id',
            'project' => 'sent_purchase_order_line_owners.project_id',
            'project_id' => 'sent_purchase_order_line_owners.project_id',
            'warehouse' => 'sent_purchase_order_line_owners.warehouse_id',
            'warehouse_id' => 'sent_purchase_order_line_owners.warehouse_id',
            'material' => 'sent_purchase_order_line_owners.material_id',
            'material_id' => 'sent_purchase_order_line_owners.material_id',
            'buyer' => 'sent_purchase_order_line_owners.buyer_id',
            'priority' => 'sent_purchase_order_line_owners.priority',
            'status' => $this->statusExpression($query, $tolerance),
            'period' => 'owner_promise.promised_at',
            'promised_month' => DB::raw("to_char(owner_promise.promised_at, 'YYYY-MM')"),
            'delay' => $this->delayExpression($query, $tolerance),
        ]);
        $eligiblePromiseIds = (clone $promises)->whereNotNull('owner_promise.id')->select('owner_promise.id');
        $eligible = (clone $promises)->distinct()->count(
            'sent_purchase_order_line_owners.purchase_order_item_id',
        );
        $eligibleMaxItemId = (int) ((clone $promises)->max('sent_purchase_order_line_owners.purchase_order_item_id') ?? 0);
        $owned = $eligible;
        $projected = (clone $promises)->whereNotNull('owner_promise.id')->distinct()->count(
            'sent_purchase_order_line_owners.purchase_order_item_id',
        );
        $missingOwner = max(0, $eligible - $owned);
        $missingPromise = max(0, $owned - $projected);
        $eligibleItemIds = (clone $promises)->select(
            'sent_purchase_order_line_owners.purchase_order_item_id',
        );
        $missingSent = (clone $promises)
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
        $incompleteBackfill = $watermark instanceof SupplyReliabilityBackfillWatermark
            && (int) $watermark->completed_item_id
                < min((int) $watermark->target_item_id, $eligibleMaxItemId)
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
        $missingOrderLifecycle = (clone $promises)
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
        $unknown = (clone $promises)
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
            (clone $promises)->where('sent_purchase_order_line_owners.source_version', '<', 1)->count()
                + (clone $lifecycle)->where('source_version', '<', 1)->count(),
            (clone $promises)->whereRaw('LENGTH(sent_purchase_order_line_owners.source_hash) <> 64')->count()
                + (clone $lifecycle)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }

    private function statusExpression(
        ReportQuery $query,
        string $tolerance,
    ): \Illuminate\Contracts\Database\Query\Expression {
        $cutoff = $query->asOf->format(DATE_ATOM);

        return DB::raw(
            '(CASE '
            .'WHEN EXISTS (SELECT 1 FROM supply_lifecycle_events status_cancel '
            .'WHERE status_cancel.promise_version_id = owner_promise.id '
            ."AND status_cancel.event_type = 'cancelled' AND status_cancel.occurred_at <= '{$cutoff}') "
            ."THEN 'cancelled' "
            .'WHEN COALESCE((SELECT SUM(status_qty.signed_quantity) FROM supply_lifecycle_events status_qty '
            .'WHERE status_qty.promise_version_id = owner_promise.id '
            ."AND status_qty.occurred_at <= '{$cutoff}'), 0) >= "
            ."(owner_promise.ordered_quantity - {$tolerance}) "
            ."THEN 'delivered' "
            .'WHEN COALESCE((SELECT SUM(status_qty.signed_quantity) FROM supply_lifecycle_events status_qty '
            .'WHERE status_qty.promise_version_id = owner_promise.id '
            ."AND status_qty.occurred_at <= '{$cutoff}'), 0) > 0 THEN 'partially_delivered' "
            .'WHEN EXISTS (SELECT 1 FROM supply_lifecycle_events status_confirm '
            .'WHERE status_confirm.promise_version_id = owner_promise.id '
            ."AND status_confirm.event_type = 'confirmed' AND status_confirm.occurred_at <= '{$cutoff}') "
            ."THEN 'confirmed' ELSE 'sent' END)",
        );
    }

    private function delayExpression(
        ReportQuery $query,
        string $tolerance,
    ): \Illuminate\Contracts\Database\Query\Expression {
        $cutoff = $query->asOf->format(DATE_ATOM);
        $receipt = '(SELECT MIN(delay_event.occurred_at) FROM supply_lifecycle_events delay_event '
            .'WHERE delay_event.promise_version_id = owner_promise.id '
            ."AND delay_event.occurred_at <= '{$cutoff}' "
            .'AND (SELECT COALESCE(SUM(delay_running.signed_quantity), 0) '
            .'FROM supply_lifecycle_events delay_running '
            .'WHERE delay_running.promise_version_id = owner_promise.id '
            .'AND (delay_running.occurred_at < delay_event.occurred_at '
            .'OR (delay_running.occurred_at = delay_event.occurred_at AND delay_running.id <= delay_event.id))) '
            .">= (owner_promise.ordered_quantity - {$tolerance}))";

        return DB::raw(
            "(CASE WHEN {$receipt} IS NULL THEN 'unreceived' "
            ."WHEN {$receipt} <= owner_promise.promised_at THEN 'on_time' "
            ."WHEN {$receipt} <= owner_promise.promised_at + INTERVAL '3 days' THEN 'delay_1_3' "
            ."WHEN {$receipt} <= owner_promise.promised_at + INTERVAL '7 days' THEN 'delay_4_7' "
            ."ELSE 'delay_over_7' END)",
        );
    }
}
