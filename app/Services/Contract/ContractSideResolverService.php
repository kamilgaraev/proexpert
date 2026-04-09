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

        $firstParty = null;
        $secondParty = null;
        $firstPartyRoleLabel = null;
        $secondPartyRoleLabel = null;

        switch ($sideType) {
            case ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR:
                $firstParty = $projectCustomerParty ?? $ownerParty;
                $secondParty = $contractorParty;
                $firstPartyRoleLabel = 'Заказчик';
                $secondPartyRoleLabel = 'Генподрядчик';
                break;
            case ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR:
                $firstParty = $ownerParty;
                $secondParty = $contractorParty;
                $firstPartyRoleLabel = 'Генподрядчик';
                $secondPartyRoleLabel = 'Подрядчик';
                break;
            case ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER:
                $firstParty = $ownerParty;
                $secondParty = $supplierParty;
                $firstPartyRoleLabel = 'Генподрядчик';
                $secondPartyRoleLabel = 'Поставщик';
                break;
            case ContractSideTypeEnum::CONTRACTOR_TO_SUBCONTRACTOR:
                $firstParty = $ownerParty;
                $secondParty = $contractorParty;
                $firstPartyRoleLabel = 'Подрядчик';
                $secondPartyRoleLabel = 'Субподрядчик';
                break;
            case ContractSideTypeEnum::CONTRACTOR_TO_SUPPLIER:
                $firstParty = $ownerParty;
                $secondParty = $supplierParty;
                $firstPartyRoleLabel = 'Подрядчик';
                $secondPartyRoleLabel = 'Поставщик';
                break;
            case ContractSideTypeEnum::SUBCONTRACTOR_TO_SUPPLIER:
                $firstParty = $ownerParty;
                $secondParty = $supplierParty;
                $firstPartyRoleLabel = 'Субподрядчик';
                $secondPartyRoleLabel = 'Поставщик';
                break;
            default:
                break;
        }

        $deprecatedCustomer = $sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            ? $firstParty
            : null;
        $deprecatedExecutor = $sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            ? $secondParty
            : null;

        return [
            'type' => $sideType?->value,
            'display_label' => $sideType?->label() ?? 'Стороны договора не определены',
            'first_party' => $firstParty,
            'second_party' => $secondParty,
            'first_party_role_label' => $firstPartyRoleLabel,
            'second_party_role_label' => $secondPartyRoleLabel,
            'customer_organization' => $deprecatedCustomer,
            'executor_organization' => $deprecatedExecutor,
        ];
    }

    public function resolveCustomerAlias(Contract $contract): ?array
    {
        $sideType = $contract->contract_side_type instanceof ContractSideTypeEnum
            ? $contract->contract_side_type
            : ($contract->contract_side_type ? ContractSideTypeEnum::tryFrom((string) $contract->contract_side_type) : null);

        if ($sideType !== ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR) {
            return null;
        }

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
