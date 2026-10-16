<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contractor;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

final class AssistantContractReportProjection
{
    public function apply(Builder $contracts, ?Builder $contractors, ?Builder $suppliers, ?Builder $customerProjects, int $organizationId): Builder
    {
        $contracts->select('contracts.*')
            ->leftJoin('contract_parties as report_first_party', static fn (JoinClause $join) => $join
                ->on('report_first_party.contract_id', '=', 'contracts.id')->where('report_first_party.side', 'first'))
            ->leftJoin('contract_parties as report_second_party', static fn (JoinClause $join) => $join
                ->on('report_second_party.contract_id', '=', 'contracts.id')->where('report_second_party.side', 'second'))
            ->leftJoinSub(Contractor::query()->select(['id', 'source_organization_id']), 'report_contractor_relation', static fn (JoinClause $join) => $join
                ->on('report_contractor_relation.id', '=', 'contracts.contractor_id'))
            ->leftJoinSub(Supplier::query()->select('id'), 'report_supplier_relation', static fn (JoinClause $join) => $join
                ->on('report_supplier_relation.id', '=', 'contracts.supplier_id'));
        if ($contractors !== null) {
            $contractors->select(['contractors.id', 'contractors.name']);
            $contracts->leftJoinSub($contractors, 'report_legacy_contractor', static fn (JoinClause $join) => $join
                ->on('report_legacy_contractor.id', '=', 'contracts.contractor_id')->where('contracts.organization_id', $organizationId));
        }
        if ($suppliers !== null) {
            $suppliers->select(['suppliers.id', 'suppliers.name']);
            $contracts->leftJoinSub($suppliers, 'report_legacy_supplier', static fn (JoinClause $join) => $join
                ->on('report_legacy_supplier.id', '=', 'contracts.supplier_id')->where('contracts.organization_id', $organizationId));
        }
        if ($customerProjects !== null) {
            $customers = (new AssistantContractProjectCustomerProjection)->apply($customerProjects);
            $contracts->leftJoinSub($customers, 'report_legacy_customer_project', static fn (JoinClause $join) => $join
                ->on('report_legacy_customer_project.id', '=', 'contracts.project_id')->where('contracts.organization_id', $organizationId));
        }
        $supplyTypes = ['general_contractor_to_supplier', 'contractor_to_supplier', 'subcontractor_to_supplier'];
        foreach (ContractSideTypeEnum::cases() as $type) {
            if ($type->requiresSupplier()) {
                $supplyTypes[] = $type->value;
            }
        }
        $usesSupplier = '(contracts.contract_side_type IN ('.implode(', ', array_fill(0, count($supplyTypes), '?')).')
            OR (contracts.contract_side_type IS NULL AND report_contractor_relation.id IS NULL))';
        $selfExecution = 'COALESCE(contracts.is_self_execution, FALSE) AND report_contractor_relation.source_organization_id = contracts.organization_id';
        $contractorName = $contractors !== null ? "CASE WHEN {$selfExecution} THEN NULL ELSE NULLIF(BTRIM(report_legacy_contractor.name), '') END" : 'NULL';
        $contractorName = "CASE WHEN contracts.contract_side_type IS NOT NULL AND NOT COALESCE(contracts.is_self_execution, FALSE)
            AND report_contractor_relation.id IS NULL THEN NULLIF(BTRIM(report_second_party.name), '') ELSE {$contractorName} END";
        $contractorId = $contractors !== null ? "CASE WHEN {$selfExecution} THEN NULL ELSE report_legacy_contractor.id END" : 'NULL';
        $supplierName = $suppliers !== null ? "NULLIF(BTRIM(report_legacy_supplier.name), '')" : 'NULL';
        $supplierName = "CASE WHEN contracts.contract_side_type IS NOT NULL AND report_supplier_relation.id IS NULL
            THEN NULLIF(BTRIM(report_second_party.name), '') ELSE {$supplierName} END";
        $supplierId = $suppliers !== null ? 'report_legacy_supplier.id' : 'NULL';
        $ownContractor = 'report_contractor_relation.source_organization_id = contracts.organization_id';
        $customerTypes = ['general_contract', 'customer_to_general_contractor'];
        $usesCustomer = "contracts.contract_side_type IN (?, ?) AND NOT COALESCE(contracts.is_self_execution, FALSE)
            AND (report_contractor_relation.id IS NULL OR {$ownContractor})";
        $resolvedCustomerName = $customerProjects !== null ? "CASE WHEN report_legacy_customer_project.customer_organization_id = contracts.organization_id
            THEN NULL ELSE NULLIF(BTRIM(report_legacy_customer_project.customer_name), '') END" : 'NULL';
        $fallbackCustomerName = $customerProjects !== null ? "NULLIF(BTRIM(report_legacy_customer_project.fallback_customer_name), '')" : 'NULL';
        $customerName = "CASE WHEN report_contractor_relation.id IS NULL THEN COALESCE(NULLIF(BTRIM(report_first_party.name), ''), {$fallbackCustomerName})
            ELSE {$resolvedCustomerName} END";
        $customerProjectId = $customerProjects !== null ? 'report_legacy_customer_project.id' : 'NULL';
        $legacyName = "CASE WHEN {$usesSupplier} THEN {$supplierName} WHEN {$usesCustomer} THEN {$customerName} ELSE {$contractorName} END";
        $contracts->selectRaw("CASE WHEN report_first_party.id IS NOT NULL AND report_second_party.id IS NOT NULL THEN
            CASE WHEN NOT COALESCE(contracts.is_self_execution, FALSE) AND report_second_party.linked_organization_id = ? THEN
                CASE WHEN report_first_party.linked_organization_id = ? THEN NULL ELSE NULLIF(BTRIM(report_first_party.name), '') END
            ELSE CASE WHEN report_second_party.linked_organization_id = ? THEN NULL ELSE NULLIF(BTRIM(report_second_party.name), '') END END
            ELSE {$legacyName} END AS counterparty_name", [$organizationId, $organizationId, $organizationId, ...$supplyTypes, ...$customerTypes])
            ->selectRaw("CASE WHEN report_first_party.id IS NOT NULL AND report_second_party.id IS NOT NULL THEN NULL
                WHEN {$usesSupplier} THEN NULL WHEN {$usesCustomer} THEN NULL ELSE {$contractorId} END AS _report_counterparty_id", [...$supplyTypes, ...$customerTypes])
            ->selectRaw("CASE WHEN report_first_party.id IS NOT NULL AND report_second_party.id IS NOT NULL THEN NULL
                WHEN {$usesSupplier} THEN {$supplierId} ELSE NULL END AS _report_counterparty_supplier_id", $supplyTypes)
            ->selectRaw("CASE WHEN report_first_party.id IS NOT NULL AND report_second_party.id IS NOT NULL THEN NULL
                WHEN {$usesCustomer} AND ({$customerName}) IS NOT NULL
                    AND (report_contractor_relation.id IS NOT NULL OR NULLIF(BTRIM(report_first_party.name), '') IS NULL)
                THEN {$customerProjectId} ELSE NULL END AS _report_counterparty_customer_project_id", $customerTypes);

        return $contracts->getModel()->newModelQuery()->fromSub($contracts, 'contracts');
    }
}
