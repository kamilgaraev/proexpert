<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Customer\CustomerPortalService;
use App\Services\Project\ProjectParticipantService;
use Tests\TestCase;

class CustomerContractsVisibilityTest extends TestCase
{
    public function test_customer_contracts_are_filtered_by_contract_side_and_return_meta_filters(): void
    {
        $ownerOrganization = Organization::factory()->create();
        $customerOrganization = Organization::factory()->create();
        $unrelatedOrganization = Organization::factory()->create();

        $participantService = app(ProjectParticipantService::class);
        $customerPortalService = app(CustomerPortalService::class);

        $ownerContractor = $this->createContractor($ownerOrganization, 'Генподрядчик owner');
        $customerExecutor = $this->createContractor($customerOrganization, 'Исполнитель customer-side');
        $unrelatedContractor = $this->createContractor($unrelatedOrganization, 'Чужой подрядчик');

        $fallbackProject = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Fallback project',
        ]);
        $projectWithCustomer = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Resolved customer project',
        ]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $unrelatedOrganization->id,
            'name' => 'Foreign project',
        ]);

        $participantService->attach(
            $projectWithCustomer,
            $customerOrganization->id,
            ProjectOrganizationRole::CUSTOMER
        );

        $fallbackContract = $this->createContract(
            $ownerOrganization,
            $fallbackProject,
            $ownerContractor,
            'C-001',
            ContractStatusEnum::ACTIVE,
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
        );
        $resolvedCustomerContract = $this->createContract(
            $customerOrganization,
            $projectWithCustomer,
            $customerExecutor,
            'C-002',
            ContractStatusEnum::DRAFT,
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
        );
        $internalContract = $this->createContract(
            $ownerOrganization,
            $projectWithCustomer,
            $ownerContractor,
            'C-003',
            ContractStatusEnum::ACTIVE,
            ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR
        );
        $this->createContract(
            $unrelatedOrganization,
            $foreignProject,
            $unrelatedContractor,
            'C-004',
            ContractStatusEnum::ACTIVE,
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
        );

        $ownerContracts = $customerPortalService->getContracts($ownerOrganization->id, [
            'search' => 'C-00',
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->assertSame(1, $ownerContracts['meta']['total']);
        $this->assertSame('C-001', $ownerContracts['items'][0]['number']);
        $this->assertSame(ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value, $ownerContracts['items'][0]['contract_side']['type']);
        $this->assertSame('customer', $ownerContracts['items'][0]['current_organization_role']);
        $this->assertSame('C-00', $ownerContracts['meta']['filters']['search']);

        $customerContracts = $customerPortalService->getContracts($customerOrganization->id, [
            'project_id' => $projectWithCustomer->id,
            'contractor_id' => $customerExecutor->id,
            'status' => ContractStatusEnum::DRAFT->value,
            'search' => 'C-002',
            'per_page' => 5,
            'page' => 1,
        ]);

        $this->assertSame(1, $customerContracts['meta']['total']);
        $this->assertSame($projectWithCustomer->id, $customerContracts['meta']['filters']['project_id']);
        $this->assertSame($customerExecutor->id, $customerContracts['meta']['filters']['contractor_id']);
        $this->assertSame(5, $customerContracts['meta']['per_page']);
        $this->assertSame($resolvedCustomerContract->id, $customerContracts['items'][0]['id']);
        $this->assertSame(ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value, $customerContracts['items'][0]['contract_side']['type']);
        $this->assertSame('customer', $customerContracts['items'][0]['current_organization_role']);

        $projectContracts = $customerPortalService->getContracts($customerOrganization->id, [
            'page' => 1,
            'per_page' => 5,
        ], $projectWithCustomer);

        $this->assertSame(1, $projectContracts['meta']['total']);
        $this->assertSame($resolvedCustomerContract->id, $projectContracts['items'][0]['id']);
        $this->assertNotSame($internalContract->id, $projectContracts['items'][0]['id']);

        $fallbackContract->refresh();
        $resolvedCustomerContract->refresh();
    }

    private function createContractor(Organization $organization, string $name): Contractor
    {
        return Contractor::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
    }

    private function createContract(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        string $number,
        ContractStatusEnum $status,
        ContractSideTypeEnum $contractSideType
    ): Contract {
        return Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => $contractSideType->value,
            'number' => $number,
            'date' => now()->toDateString(),
            'subject' => 'Тестовый договор ' . $number,
            'total_amount' => 100000,
            'status' => $status->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);
    }
}
