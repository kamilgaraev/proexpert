<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantContractReportProjection
{
    public function apply(Builder $contracts, ?Builder $contractors, int $organizationId): Builder
    {
        $contracts->select('contracts.*')
            ->leftJoin('contract_parties as report_first_party', static fn (JoinClause $join) => $join
                ->on('report_first_party.contract_id', '=', 'contracts.id')->where('report_first_party.side', 'first'))
            ->leftJoin('contract_parties as report_second_party', static fn (JoinClause $join) => $join
                ->on('report_second_party.contract_id', '=', 'contracts.id')->where('report_second_party.side', 'second'));
        if ($contractors !== null) {
            $contractors->select(['contractors.id', 'contractors.name']);
            $contracts->leftJoinSub($contractors, 'report_legacy_contractor', static fn (JoinClause $join) => $join
                ->on('report_legacy_contractor.id', '=', 'contracts.contractor_id')->where('contracts.organization_id', $organizationId));
        }
        $legacyName = $contractors !== null ? "NULLIF(BTRIM(report_legacy_contractor.name), '')" : 'NULL';
        $legacyId = $contractors !== null ? 'report_legacy_contractor.id' : 'NULL';
        $contracts->selectRaw("CASE WHEN report_first_party.id IS NOT NULL AND report_second_party.id IS NOT NULL THEN
            CASE WHEN NOT COALESCE(contracts.is_self_execution, FALSE) AND report_second_party.linked_organization_id = ? THEN
                CASE WHEN report_first_party.linked_organization_id = ? THEN NULL ELSE NULLIF(BTRIM(report_first_party.name), '') END
            ELSE CASE WHEN report_second_party.linked_organization_id = ? THEN NULL ELSE NULLIF(BTRIM(report_second_party.name), '') END END
            ELSE {$legacyName} END AS counterparty_name", [$organizationId, $organizationId, $organizationId])
            ->selectRaw("CASE WHEN report_first_party.id IS NOT NULL AND report_second_party.id IS NOT NULL THEN NULL
                ELSE {$legacyId} END AS _report_counterparty_id");

        return $contracts->getModel()->newModelQuery()->fromSub($contracts, 'contracts');
    }
}
