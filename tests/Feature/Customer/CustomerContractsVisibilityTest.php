<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

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
    public function test_customer_contracts_are_filtered_by_resolved_customer_and_return_meta_filters(): void
    {
        $ownerOrganization = Organization::factory()->create();
        $customerOrganization = Organization::factory()->create();
        $unrelatedOrganization = Organization::factory()->create();

        $participantService = app(ProjectParticipantService::class);
        $customerPortalService = app(CustomerPortalService::class);

        $ownerContractor = $this->createContractor($ownerOrganization, 'Генподрядчик owner');
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
            ContractStatusEnum::ACTIVE
        );
        $resolvedCustomerContract = $this->createContract(
            $ownerOrganization,
            $projectWithCustomer,
            $ownerContractor,
            'C-002',
            ContractStatusEnum::DRAFT
        );
        $this->createContract(
            $unrelatedOrganization,
            $foreignProject,
            $unrelatedContractor,
            'C-003',
            ContractStatusEnum::ACTIVE
        );

        $ownerContracts = $customerPortalService->getContracts($ownerOrganization->id, [
            'search' => 'C-00',
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->assertSame(1, $ownerContracts['meta']['total']);
        $this->assertSame('C-001', $ownerContracts['items'][0]['number']);
        $this->assertSame('project_owner', $ownerContracts['items'][0]['customer']['source']);
        $this->assertTrue($ownerContracts['items'][0]['customer']['is_fallback_owner']);
        $this->assertSame('C-00', $ownerContracts['meta']['filters']['search']);

        $customerContracts = $customerPortalService->getContracts($customerOrganization->id, [
            'project_id' => $projectWithCustomer->id,
            'contractor_id' => $ownerContractor->id,
            'status' => ContractStatusEnum::DRAFT->value,
            'search' => 'C-002',
            'per_page' => 5,
            'page' => 1,
        ]);

        $this->assertSame(1, $customerContracts['meta']['total']);
        $this->assertSame($projectWithCustomer->id, $customerContracts['meta']['filters']['project_id']);
        $this->assertSame($ownerContractor->id, $customerContracts['meta']['filters']['contractor_id']);
        $this->assertSame(5, $customerContracts['meta']['per_page']);
        $this->assertSame($resolvedCustomerContract->id, $customerContracts['items'][0]['id']);
        $this->assertSame('project_participant', $customerContracts['items'][0]['customer']['source']);
        $this->assertFalse($customerContracts['items'][0]['customer']['is_fallback_owner']);

        $projectContracts = $customerPortalService->getContracts($customerOrganization->id, [
            'page' => 1,
            'per_page' => 5,
        ], $projectWithCustomer);

        $this->assertSame(1, $projectContracts['meta']['total']);
        $this->assertSame($resolvedCustomerContract->id, $projectContracts['items'][0]['id']);

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
        ContractStatusEnum $status
    ): Contract {
        return Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
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
