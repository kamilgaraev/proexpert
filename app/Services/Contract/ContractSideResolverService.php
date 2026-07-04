<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Models\Contract;
use App\Models\ContractParty;
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
            'firstParty',
            'secondParty',
        ]);

        $sideType = $contract->contract_side_type instanceof ContractSideTypeEnum
            ? $contract->contract_side_type
            : ($contract->contract_side_type ? ContractSideTypeEnum::tryFrom((string) $contract->contract_side_type) : null);

        if ($contract->firstParty instanceof ContractParty && $contract->secondParty instanceof ContractParty) {
            return [
                'type' => $sideType?->value,
                'display_label' => $sideType?->label() ?? 'Стороны договора не определены',
                'first_party' => $this->mapContractPartySnapshot($contract->firstParty),
                'second_party' => $this->mapContractPartySnapshot($contract->secondParty),
                'first_party_role_label' => $contract->firstParty->role?->label(),
                'second_party_role_label' => $contract->secondParty->role?->label(),
                'customer_organization' => $sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
                    ? $this->mapContractPartySnapshot($contract->firstParty)
                    : null,
                'executor_organization' => $sideType === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
                    ? $this->mapContractPartySnapshot($contract->secondParty)
                    : null,
            ];
        }

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
        $resolvedCustomer = $this->projectCustomerResolverService->resolveLegalCustomer($project);

        if ($resolvedCustomer['id'] === null || $resolvedCustomer['name'] === null) {
            return null;
        }

        return [
            'id' => (int) $resolvedCustomer['id'],
            'name' => (string) $resolvedCustomer['name'],
            'entity_type' => $resolvedCustomer['entity_type'] ?? 'organization',
            'source' => $resolvedCustomer['source'],
            'counterparty_id' => $resolvedCustomer['counterparty_id'] ?? null,
            'linked_organization_id' => $resolvedCustomer['linked_organization_id'] ?? null,
            'inn' => $resolvedCustomer['inn'] ?? null,
            'kpp' => $resolvedCustomer['kpp'] ?? null,
        ];
    }

    private function mapContractPartySnapshot(ContractParty $party): array
    {
        return [
            'contract_party_id' => $party->id,
            'id' => $party->counterparty_id ?? $party->linked_organization_id ?? $party->id,
            'counterparty_id' => $party->counterparty_id,
            'organization_id' => $party->linked_organization_id,
            'name' => $party->name,
            'legal_name' => $party->legal_name,
            'inn' => $party->inn,
            'kpp' => $party->kpp,
            'ogrn' => $party->ogrn,
            'legal_address' => $party->legal_address,
            'email' => $party->email,
            'phone' => $party->phone,
            'role' => $party->role?->value,
            'role_label' => $party->role?->label(),
            'entity_type' => $party->counterparty_id ? 'counterparty' : 'organization',
            'source' => 'contract_party_snapshot',
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
