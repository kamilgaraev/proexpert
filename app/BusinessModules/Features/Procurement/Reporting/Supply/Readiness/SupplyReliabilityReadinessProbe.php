<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent;
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
        $promises = PurchaseOrderPromiseVersion::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('promise_version', 1)
            ->where('effective_from', '<=', $query->asOf)
            ->when(
                $allowedItemIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'purchase_order_item_id',
                    $allowedItemIds,
                ),
            )
            ->when(
                $projects !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('project_id', $projects),
            );
        $this->filters->apply($promises, $this->filters->only($query->filters, [
            'supplier', 'supplier_id', 'project', 'project_id', 'warehouse', 'warehouse_id',
            'material', 'material_id', 'buyer', 'priority', 'status', 'period', 'promised_month',
            'delay',
        ]), [
            'supplier' => 'purchase_order_promise_versions.supplier_id',
            'supplier_id' => 'purchase_order_promise_versions.supplier_id',
            'project' => 'purchase_order_promise_versions.project_id',
            'project_id' => 'purchase_order_promise_versions.project_id',
            'warehouse' => 'purchase_order_promise_versions.warehouse_id',
            'warehouse_id' => 'purchase_order_promise_versions.warehouse_id',
            'material' => 'purchase_order_promise_versions.material_id',
            'material_id' => 'purchase_order_promise_versions.material_id',
            'buyer' => 'purchase_order_promise_versions.buyer_id',
            'priority' => 'purchase_order_promise_versions.priority',
            'status' => $this->statusExpression($query, $tolerance),
            'period' => 'purchase_order_promise_versions.promised_at',
            'promised_month' => DB::raw("to_char(purchase_order_promise_versions.promised_at, 'YYYY-MM')"),
            'delay' => $this->delayExpression($query, $tolerance),
        ]);
        $eligiblePromiseIds = (clone $promises)->select('id');
        $projected = (clone $promises)->distinct()->count('purchase_order_item_id');
        $eligible = $allowedItemIds !== null && $query->filters->values === []
            ? count(array_unique($allowedItemIds))
            : $projected;
        $missingPromise = max(0, $eligible - $projected);
        $eligibleItemIds = (clone $promises)->select('purchase_order_item_id');
        $missingSent = (clone $promises)
            ->whereNotExists(function ($builder) use ($context, $query): void {
                $builder->selectRaw('1')
                    ->from('supply_lifecycle_events as readiness_event')
                    ->whereColumn(
                        'readiness_event.promise_version_id',
                        'purchase_order_promise_versions.id',
                    )
                    ->whereColumn(
                        'readiness_event.purchase_order_item_id',
                        'purchase_order_promise_versions.purchase_order_item_id',
                    )
                    ->where('readiness_event.organization_id', $context->scope->organizationId)
                    ->where('readiness_event.event_type', 'sent')
                    ->where('readiness_event.occurred_at', '<=', $query->asOf);
            })
            ->count();
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
        $unknown = (clone $promises)
            ->where(function ($builder): void {
                $builder->whereIn('unit_dimension', ['', 'unknown'])
                    ->orWhereIn('conversion_version', ['', 'unknown', 'unproven'])
                    ->orWhereNull('supplier_id')
                    ->orWhereNull('warehouse_id')
                    ->orWhereNull('material_id')
                    ->orWhereNull('ordered_value_minor')
                    ->orWhereNull('currency')
                    ->orWhereNull('value_basis');
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
            $missingPromise
                + $missingSent
                + $missingReceiptLifecycle
                + $missingReversalLifecycle,
            $unknown,
            (clone $promises)->where('source_version', '<', 1)->count()
                + (clone $lifecycle)->where('source_version', '<', 1)->count(),
            (clone $promises)->whereRaw('LENGTH(source_hash) <> 64')->count()
                + (clone $lifecycle)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }

    private function statusExpression(
        ReportQuery $query,
        string $tolerance,
    ): \Illuminate\Contracts\Database\Query\Expression
    {
        $cutoff = $query->asOf->format(DATE_ATOM);

        return DB::raw(
            "(CASE "
            ."WHEN EXISTS (SELECT 1 FROM supply_lifecycle_events status_cancel "
            ."WHERE status_cancel.promise_version_id = purchase_order_promise_versions.id "
            ."AND status_cancel.event_type = 'cancelled' AND status_cancel.occurred_at <= '{$cutoff}') "
            ."THEN 'cancelled' "
            ."WHEN COALESCE((SELECT SUM(status_qty.signed_quantity) FROM supply_lifecycle_events status_qty "
            ."WHERE status_qty.promise_version_id = purchase_order_promise_versions.id "
            ."AND status_qty.occurred_at <= '{$cutoff}'), 0) >= "
            ."(purchase_order_promise_versions.ordered_quantity - {$tolerance}) "
            ."THEN 'delivered' "
            ."WHEN COALESCE((SELECT SUM(status_qty.signed_quantity) FROM supply_lifecycle_events status_qty "
            ."WHERE status_qty.promise_version_id = purchase_order_promise_versions.id "
            ."AND status_qty.occurred_at <= '{$cutoff}'), 0) > 0 THEN 'partially_delivered' "
            ."WHEN EXISTS (SELECT 1 FROM supply_lifecycle_events status_confirm "
            ."WHERE status_confirm.promise_version_id = purchase_order_promise_versions.id "
            ."AND status_confirm.event_type = 'confirmed' AND status_confirm.occurred_at <= '{$cutoff}') "
            ."THEN 'confirmed' ELSE 'sent' END)",
        );
    }

    private function delayExpression(
        ReportQuery $query,
        string $tolerance,
    ): \Illuminate\Contracts\Database\Query\Expression
    {
        $cutoff = $query->asOf->format(DATE_ATOM);
        $receipt = "(SELECT MIN(delay_event.occurred_at) FROM supply_lifecycle_events delay_event "
            ."WHERE delay_event.promise_version_id = purchase_order_promise_versions.id "
            ."AND delay_event.occurred_at <= '{$cutoff}' "
            ."AND (SELECT COALESCE(SUM(delay_running.signed_quantity), 0) "
            ."FROM supply_lifecycle_events delay_running "
            ."WHERE delay_running.promise_version_id = purchase_order_promise_versions.id "
            ."AND (delay_running.occurred_at < delay_event.occurred_at "
            ."OR (delay_running.occurred_at = delay_event.occurred_at AND delay_running.id <= delay_event.id))) "
            .">= (purchase_order_promise_versions.ordered_quantity - {$tolerance}))";

        return DB::raw(
            "(CASE WHEN {$receipt} IS NULL THEN 'unreceived' "
            ."WHEN {$receipt} <= purchase_order_promise_versions.promised_at THEN 'on_time' "
            ."WHEN {$receipt} <= purchase_order_promise_versions.promised_at + INTERVAL '3 days' THEN 'delay_1_3' "
            ."WHEN {$receipt} <= purchase_order_promise_versions.promised_at + INTERVAL '7 days' THEN 'delay_4_7' "
            ."ELSE 'delay_over_7' END)",
        );
    }
}
