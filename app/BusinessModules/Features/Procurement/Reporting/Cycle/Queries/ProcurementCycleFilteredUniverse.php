<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Queries;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleOwnerExpectationVersion;
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
        $builder = ProcurementCycleOwnerExpectationVersion::query()
            ->from('procurement_cycle_owner_expectation_versions as cycle_owner')
            ->leftJoin('procurement_process_events as cycle_filter_event', function ($join) use ($query): void {
                $join->on('cycle_filter_event.purchase_request_line_id', '=', 'cycle_owner.purchase_request_line_id')
                    ->on('cycle_filter_event.organization_id', '=', 'cycle_owner.organization_id')
                    ->where('cycle_filter_event.occurred_at', '<=', $query->asOf);
            })
            ->where('cycle_owner.organization_id', $context->scope->organizationId)
            ->where('cycle_owner.effective_from', '<=', $query->asOf)
            ->whereNotExists(function ($later) use ($query): void {
                $later->selectRaw('1')
                    ->from('procurement_cycle_owner_expectation_versions as later_cycle_owner')
                    ->whereColumn(
                        'later_cycle_owner.purchase_request_line_id',
                        'cycle_owner.purchase_request_line_id',
                    )
                    ->whereColumn('later_cycle_owner.organization_id', 'cycle_owner.organization_id')
                    ->where('later_cycle_owner.effective_from', '<=', $query->asOf)
                    ->where(function ($position): void {
                        $position
                            ->whereColumn('later_cycle_owner.effective_from', '>', 'cycle_owner.effective_from')
                            ->orWhere(function ($sameTime): void {
                                $sameTime
                                    ->whereColumn('later_cycle_owner.effective_from', 'cycle_owner.effective_from')
                                    ->whereColumn('later_cycle_owner.expectation_version', '>', 'cycle_owner.expectation_version');
                            });
                    });
            })
            ->where(function (Builder $events) use ($query): void {
                $events->whereNull('cycle_filter_event.id')
                    ->orWhereNotExists(function ($later) use ($query): void {
                        $later->selectRaw('1')
                            ->from('procurement_process_events as later_cycle_event')
                            ->whereColumn('later_cycle_event.organization_id', 'cycle_filter_event.organization_id')
                            ->whereColumn('later_cycle_event.purchase_request_line_id', 'cycle_filter_event.purchase_request_line_id')
                            ->where('later_cycle_event.occurred_at', '<=', $query->asOf)
                            ->where(function ($position): void {
                                $position->whereColumn('later_cycle_event.occurred_at', '>', 'cycle_filter_event.occurred_at')
                                    ->orWhere(function ($sameTime): void {
                                        $sameTime->whereColumn('later_cycle_event.occurred_at', 'cycle_filter_event.occurred_at')
                                            ->whereColumn('later_cycle_event.id', '>', 'cycle_filter_event.id');
                                    });
                            });
                    });
            })
            ->when(
                $allowedLineIds !== null,
                static fn (Builder $query): Builder => $query->whereIn('cycle_owner.purchase_request_line_id', $allowedLineIds),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $query): Builder => $query->whereIn(
                    DB::raw("NULLIF(cycle_owner.dimensions->>'project_id', '')::bigint"),
                    $context->scope->projectIds,
                ),
            );

        $this->filters->apply($builder, $query->filters, [
            'project' => DB::raw("NULLIF(cycle_owner.dimensions->>'project_id', '')::bigint"),
            'project_id' => DB::raw("NULLIF(cycle_owner.dimensions->>'project_id', '')::bigint"),
            'requester' => DB::raw("NULLIF(cycle_owner.dimensions->>'requester_id', '')::bigint"),
            'buyer' => DB::raw("NULLIF(cycle_owner.dimensions->>'buyer_id', '')::bigint"),
            'material' => DB::raw("NULLIF(cycle_owner.dimensions->>'material_id', '')::bigint"),
            'category' => DB::raw("cycle_owner.dimensions->>'category'"),
            'supplier' => DB::raw("NULLIF(cycle_owner.dimensions->>'supplier_party_id', '')::bigint"),
            'amount' => DB::raw("NULLIF(cycle_owner.dimensions->>'amount', '')::numeric"),
            'priority' => DB::raw("cycle_owner.dimensions->>'priority'"),
            'stage' => 'cycle_filter_event.stage',
            'status' => 'cycle_filter_event.event_code',
            'period' => 'cycle_filter_event.occurred_at',
        ]);

        return $builder
            ->selectRaw('cycle_owner.purchase_request_line_id as id')
            ->distinct();
    }
}
