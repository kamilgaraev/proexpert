<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Queries;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\ReportSourceAccessPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SupplierAwardFilteredUniverse
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private OwnerReportFilterApplier $filters,
    ) {}

    public function query(ReportExecutionContext $context, ReportQuery $query): Builder
    {
        $allowedDecisionIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'supplier_award_decision',
        );
        $builder = SupplierAwardDecisionVersion::query()
            ->leftJoin('purchase_requests as award_filter_request', 'award_filter_request.id', '=', 'supplier_award_decision_versions.purchase_request_id')
            ->leftJoin('site_requests as award_filter_site_request', 'award_filter_site_request.id', '=', 'award_filter_request.site_request_id')
            ->leftJoin('supplier_proposal_versions as award_filter_version', 'award_filter_version.id', '=', 'supplier_award_decision_versions.selected_proposal_version_id')
            ->leftJoin('supplier_proposals as award_filter_proposal', 'award_filter_proposal.id', '=', 'award_filter_version.supplier_proposal_id')
            ->leftJoin('supplier_proposal_lines as award_filter_line', 'award_filter_line.supplier_proposal_id', '=', 'award_filter_proposal.id')
            ->leftJoin('materials as award_filter_material', 'award_filter_material.id', '=', 'award_filter_line.material_id')
            ->where('supplier_award_decision_versions.organization_id', $context->scope->organizationId)
            ->where('supplier_award_decision_versions.selected_at', '<=', $query->asOf)
            ->when(
                $allowedDecisionIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'supplier_award_decision_versions.decision_id',
                    $allowedDecisionIds,
                ),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    'award_filter_site_request.project_id',
                    $context->scope->projectIds,
                ),
            );
        $this->filters->apply($builder, $query->filters, [
            'project' => 'award_filter_site_request.project_id',
            'category' => 'award_filter_material.category',
            'material' => 'award_filter_line.material_id',
            'buyer' => 'supplier_award_decision_versions.selected_by',
            'supplier' => 'award_filter_proposal.supplier_id',
            'decision' => 'supplier_award_decision_versions.decision_id',
            'method' => DB::raw(
                "CASE WHEN jsonb_array_length(supplier_award_decision_versions.invited_supplier_ids) > 1 "
                ."THEN 'competitive' ELSE 'single_source' END",
            ),
            'currency' => 'award_filter_proposal.currency',
            'non_lowest' => [
                'column' => 'supplier_award_decision_versions.is_lowest_price_selected',
                'invert_boolean' => true,
            ],
            'period' => 'supplier_award_decision_versions.selected_at',
        ]);

        return $builder
            ->select('supplier_award_decision_versions.*')
            ->distinct();
    }
}
