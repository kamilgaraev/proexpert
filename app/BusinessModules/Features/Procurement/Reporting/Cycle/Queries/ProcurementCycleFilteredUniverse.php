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
            ->join('procurement_process_events as cycle_filter_event', function ($join) use ($query): void {
                $join->on('cycle_filter_event.purchase_request_line_id', '=', 'purchase_request_lines.id')
                    ->where('cycle_filter_event.occurred_at', '<=', $query->asOf);
            })
            ->where('cycle_filter_event.organization_id', $context->scope->organizationId)
            ->whereNotExists(function ($later) use ($query): void {
                $later->selectRaw('1')
                    ->from('procurement_process_events as later_cycle_event')
                    ->whereColumn(
                        'later_cycle_event.purchase_request_line_id',
                        'cycle_filter_event.purchase_request_line_id',
                    )
                    ->where('later_cycle_event.occurred_at', '<=', $query->asOf)
                    ->where(function ($position): void {
                        $position
                            ->whereColumn('later_cycle_event.occurred_at', '>', 'cycle_filter_event.occurred_at')
                            ->orWhere(function ($sameTime): void {
                                $sameTime
                                    ->whereColumn('later_cycle_event.occurred_at', 'cycle_filter_event.occurred_at')
                                    ->whereColumn('later_cycle_event.id', '>', 'cycle_filter_event.id');
                            });
                    });
            })
            ->when(
                $allowedLineIds !== null,
                static fn (Builder $query): Builder => $query->whereIn('purchase_request_lines.id', $allowedLineIds),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $query): Builder => $query->whereIn(
                    DB::raw("NULLIF(cycle_filter_event.evidence->>'project_id', '')::bigint"),
                    $context->scope->projectIds,
                ),
            );

        $this->filters->apply($builder, $query->filters, [
            'project' => DB::raw("NULLIF(cycle_filter_event.evidence->>'project_id', '')::bigint"),
            'project_id' => DB::raw("NULLIF(cycle_filter_event.evidence->>'project_id', '')::bigint"),
            'requester' => DB::raw("NULLIF(cycle_filter_event.evidence->>'requester_id', '')::bigint"),
            'buyer' => DB::raw("NULLIF(cycle_filter_event.evidence->>'buyer_id', '')::bigint"),
            'material' => DB::raw("NULLIF(cycle_filter_event.evidence->>'material_id', '')::bigint"),
            'category' => DB::raw("cycle_filter_event.evidence->>'category'"),
            'supplier' => DB::raw("NULLIF(cycle_filter_event.evidence->>'supplier_party_id', '')::bigint"),
            'amount' => DB::raw("NULLIF(cycle_filter_event.evidence->>'amount', '')::numeric"),
            'priority' => DB::raw("cycle_filter_event.evidence->>'priority'"),
            'stage' => 'cycle_filter_event.stage',
            'status' => 'cycle_filter_event.event_code',
            'period' => 'cycle_filter_event.occurred_at',
        ]);

        return $builder
            ->select('purchase_request_lines.id')
            ->distinct();
    }
}
