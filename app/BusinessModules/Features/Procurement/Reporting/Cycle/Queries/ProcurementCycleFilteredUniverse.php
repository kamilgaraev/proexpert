<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\ReportSourceAccessPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ProcurementCycleFilteredUniverse
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private OwnerReportFilterApplier $filters,
    ) {}

    public function query(ReportExecutionContext $context, ReportQuery $query): Builder
    {
        $allowedLineIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'purchase_request_line',
        );
        $builder = PurchaseRequestLine::query()
            ->join('purchase_requests as cycle_owner_request', 'cycle_owner_request.id', '=', 'purchase_request_lines.purchase_request_id')
            ->join('site_requests as cycle_owner_site_request', 'cycle_owner_site_request.id', '=', 'cycle_owner_request.site_request_id')
            ->leftJoin('materials as cycle_owner_material', 'cycle_owner_material.id', '=', 'purchase_request_lines.material_id')
            ->leftJoin('procurement_process_events as cycle_filter_event', function ($join) use ($query): void {
                $join->on('cycle_filter_event.purchase_request_line_id', '=', 'purchase_request_lines.id')
                    ->where('cycle_filter_event.occurred_at', '<=', $query->asOf);
            })
            ->leftJoin('purchase_orders as cycle_filter_order', 'cycle_filter_order.id', '=', 'cycle_filter_event.purchase_order_id')
            ->leftJoin('supplier_requests as cycle_filter_supplier_request', 'cycle_filter_supplier_request.id', '=', 'cycle_filter_event.supplier_request_id')
            ->where('cycle_owner_request.organization_id', $context->scope->organizationId)
            ->where('cycle_owner_request.created_at', '<=', $query->asOf)
            ->when(
                $allowedLineIds !== null,
                static fn (Builder $query): Builder => $query->whereIn('purchase_request_lines.id', $allowedLineIds),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $query): Builder => $query->whereIn(
                    'cycle_owner_site_request.project_id',
                    $context->scope->projectIds,
                ),
            );

        $this->filters->apply($builder, $query->filters, [
            'project' => 'cycle_owner_site_request.project_id',
            'project_id' => 'cycle_owner_site_request.project_id',
            'requester' => 'cycle_owner_site_request.user_id',
            'buyer' => 'cycle_owner_request.assigned_to',
            'material' => 'purchase_request_lines.material_id',
            'category' => 'cycle_owner_material.category',
            'supplier' => DB::raw('COALESCE(cycle_filter_order.supplier_id, cycle_filter_supplier_request.supplier_id)'),
            'amount' => 'cycle_owner_request.budget_amount',
            'priority' => 'cycle_owner_site_request.priority',
            'stage' => 'cycle_filter_event.stage',
            'status' => 'cycle_filter_event.event_code',
            'period' => 'cycle_filter_event.occurred_at',
        ]);

        return $builder
            ->select('purchase_request_lines.id')
            ->distinct();
    }
}
