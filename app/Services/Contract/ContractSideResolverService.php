<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectCustomerResolverService;

class ContractSideResolverService
{
    public function __construct(
        private readonly ProjectCustomerResolverService $projectCustomerResolverService
    ) {
    }

    public function resolve(Contract $contract): array
    {
        $contract->loadMissing([
            'organization',
            'project.organizations',
            'contractor.sourceOrganization',
            'supplier',
        ]);

        $sideType = $contract->contract_side_type instanceof ContractSideTypeEnum
            ? $contract->contract_side_type
            : ($contract->contract_side_type ? ContractSideTypeEnum::tryFrom((string) $contract->contract_side_type) : null);

        $ownerParty = $this->mapOrganizationParty($contract->organization);
        $projectCustomerParty = $contract->project instanceof Project
            ? $this->mapResolvedCustomerParty($contract->project)
            : null;
        $contractorParty = $this->mapContractorParty($contract);
        $supplierParty = $this->mapSupplierParty($contract);

        $customerParty = $ownerParty;
        $executorParty = $contractorParty ?? $supplierParty;

        switch ($sideType) {
            case ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR:
                $customerParty = $projectCustomerParty ?? $ownerParty;
                $executorParty = $contractorParty;
                break;
            case ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR:
            case ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR:
                $customerParty = $ownerParty;
                $executorParty = $contractorParty;
                break;
            case ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER:
                $customerParty = $ownerParty;
                $executorParty = $supplierParty;
                break;
            default:
                break;
        }

        return [
            'type' => $sideType?->value,
            'display_label' => $sideType?->label() ?? 'Стороны договора не определены',
            'customer_organization' => $customerParty,
            'executor_organization' => $executorParty,
        ];
    }

    public function resolveCustomerAlias(Contract $contract): ?array
    {
        $customerParty = $this->resolve($contract)['customer_organization'] ?? null;

        if ($customerParty === null) {
            return null;
        }

        return [
            'id' => $customerParty['id'],
            'name' => $customerParty['name'],
            'source' => 'contract_side',
            'role' => 'customer',
            'is_fallback_owner' => false,
            'entity_type' => $customerParty['entity_type'] ?? 'organization',
        ];
    }

    private function mapResolvedCustomerParty(Project $project): ?array
    {
        $resolvedCustomer = $this->projectCustomerResolverService->resolve($project);

        if ($resolvedCustomer['id'] === null || $resolvedCustomer['name'] === null) {
            return null;
        }

        return [
            'id' => (int) $resolvedCustomer['id'],
            'name' => (string) $resolvedCustomer['name'],
            'entity_type' => 'organization',
            'source' => $resolvedCustomer['source'],
        ];
    }

    private function mapOrganizationParty(?Organization $organization): ?array
    {
        if (!$organization instanceof Organization) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'entity_type' => 'organization',
        ];
    }

    private function mapContractorParty(Contract $contract): ?array
    {
        if (!$contract->contractor) {
            return null;
        }

        return [
            'id' => $contract->contractor->id,
            'name' => $contract->contractor->name,
            'entity_type' => 'contractor',
            'organization_id' => $contract->contractor->source_organization_id,
            'organization_name' => $contract->contractor->sourceOrganization?->name,
            'is_self_execution' => $contract->contractor->isSelfExecution(),
        ];
    }

    private function mapSupplierParty(Contract $contract): ?array
    {
        if (!$contract->supplier) {
            return null;
        }

        return [
            'id' => $contract->supplier->id,
            'name' => $contract->supplier->name,
            'entity_type' => 'supplier',
        ];
    }
}
