<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardFilteredUniverse;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;

final readonly class SupplierAwardReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private SupplierAwardFilteredUniverse $universe,
        private ReportSourceAccessPolicy $sourceAccess,
        private OwnerReportFilterApplier $filters,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'supplier_award_competitiveness'
            && $definition->formulaVersion === 'supplier-award.v1';
    }

    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void
    {
        $this->inspect($context, $query)->assertReady('supplier_award_competitiveness');
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $allowedDecisionIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'supplier_award_decision',
        );
        $owners = SupplierProposalDecision::query()
            ->join('supplier_requests as award_owner_supplier_request', 'award_owner_supplier_request.id', '=', 'supplier_proposal_decisions.supplier_request_id')
            ->join('purchase_requests as award_owner_request', 'award_owner_request.id', '=', 'award_owner_supplier_request.purchase_request_id')
            ->join('site_requests as award_owner_site_request', 'award_owner_site_request.id', '=', 'award_owner_request.site_request_id')
            ->where('supplier_proposal_decisions.organization_id', $context->scope->organizationId)
            ->whereNotNull('supplier_proposal_decisions.selected_at')
            ->where('supplier_proposal_decisions.selected_at', '<=', $query->asOf)
            ->when(
                $allowedDecisionIds !== null,
                static fn ($builder) => $builder->whereIn('supplier_proposal_decisions.id', $allowedDecisionIds),
            )
            ->when(
                $context->scope->projectIds !== [],
                static fn ($builder) => $builder->whereIn(
                    'award_owner_site_request.project_id',
                    $context->scope->projectIds,
                ),
            );
        $this->filters->apply($owners, $this->filters->only($query->filters, [
            'project', 'buyer', 'decision', 'non_lowest', 'period',
        ]), [
            'project' => 'award_owner_site_request.project_id',
            'buyer' => 'supplier_proposal_decisions.selected_by',
            'decision' => 'supplier_proposal_decisions.id',
            'non_lowest' => [
                'column' => 'supplier_proposal_decisions.is_lowest_price_selected',
                'invert_boolean' => true,
            ],
            'period' => 'supplier_proposal_decisions.selected_at',
        ]);
        $eligible = $owners->distinct()->count('supplier_proposal_decisions.id');
        $versions = $this->universe->query($context, $query);
        $projected = (clone $versions)->distinct()->count('decision_id');
        $invalidVersions = (clone $versions)
            ->where(function ($builder): void {
                $builder->whereNull('selected_proposal_version_id')
                    ->orWhereNull('cheapest_proposal_version_id')
                    ->orWhereNull('median_proposal_version_id');
            })
            ->count();

        return new SourceReadinessResult(
            $eligible,
            $projected,
            max(0, $eligible - $projected),
            0,
            $invalidVersions,
            (clone $versions)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }
}
