<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Enums\ProjectOrganizationRole;
use App\Models\Counterparty;
use App\Models\Organization;
use App\Models\ProjectOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantContractProjectCustomerProjection
{
    public function apply(Builder $projects): Builder
    {
        $participants = ProjectOrganization::query()
            ->joinSub(Organization::query()->select(['id', 'name']), 'report_customer_organization',
                static fn (JoinClause $join) => $join->on('report_customer_organization.id', '=', 'project_organization.organization_id'))
            ->whereColumn('project_organization.project_id', 'report_customer_project_source.id')
            ->where('project_organization.is_active', true)
            ->where(static function (Builder $query): void {
                $query->where('project_organization.role_new', ProjectOrganizationRole::CUSTOMER->value)
                    ->orWhere(static fn (Builder $fallback) => $fallback->whereNull('project_organization.role_new')
                        ->where('project_organization.role', ProjectOrganizationRole::CUSTOMER->value));
            })
            ->orderByDesc('project_organization.id')->limit(1)
            ->select(['project_organization.id', 'report_customer_organization.name',
                'report_customer_organization.id as customer_organization_id']);
        $projects->join('projects as report_customer_project_source', 'report_customer_project_source.id', '=', 'projects.id')
            ->leftJoinLateral($participants, 'report_customer_participant')
            ->leftJoinSub(Counterparty::query()->select(['id', 'organization_id', 'name', 'linked_organization_id']), 'report_project_counterparty',
                static fn (JoinClause $join) => $join->on('report_project_counterparty.id', '=', 'report_customer_project_source.customer_counterparty_id')
                    ->on('report_project_counterparty.organization_id', '=', 'report_customer_project_source.organization_id'))
            ->leftJoinSub(Organization::query()->select(['id', 'name']), 'report_project_owner',
                static fn (JoinClause $join) => $join->on('report_project_owner.id', '=', 'report_customer_project_source.organization_id'))
            ->select(['projects.id', 'report_project_counterparty.name as fallback_customer_name']);
        $hasText = "report_customer_project_source.customer IS NOT NULL AND report_customer_project_source.customer NOT IN ('', '0')";

        return $projects->selectRaw("CASE WHEN report_customer_participant.id IS NOT NULL THEN report_customer_participant.name
            WHEN report_project_counterparty.id IS NOT NULL THEN report_project_counterparty.name
            WHEN {$hasText} THEN BTRIM(report_customer_project_source.customer) ELSE report_project_owner.name END AS customer_name")
            ->selectRaw("CASE WHEN report_customer_participant.id IS NOT NULL THEN report_customer_participant.customer_organization_id
                WHEN report_project_counterparty.id IS NOT NULL THEN report_project_counterparty.linked_organization_id
                WHEN {$hasText} THEN NULL ELSE report_project_owner.id END AS customer_organization_id");
    }
}
