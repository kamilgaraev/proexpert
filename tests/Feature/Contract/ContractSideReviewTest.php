<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Contract\ContractSideMutationService;
use App\Services\Project\ProjectParticipantService;
use Tests\TestCase;

class ContractSideReviewTest extends TestCase
{
    public function test_review_resolution_clears_flags_and_rebinds_customer_side_contract_to_project_customer(): void
    {
        $generalContractorOrganization = Organization::factory()->create();
        $customerOrganization = Organization::factory()->create();

        $participantService = app(ProjectParticipantService::class);
        $mutationService = app(ContractSideMutationService::class);

        $project = Project::factory()->create([
            'organization_id' => $generalContractorOrganization->id,
        ]);

        $participantService->attach($project, $customerOrganization->id, ProjectOrganizationRole::CUSTOMER);

        $contractor = Contractor::create([
            'organization_id' => $customerOrganization->id,
            'source_organization_id' => $generalContractorOrganization->id,
            'name' => 'ООО Генподрядчик',
            'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
        ]);

        $contract = Contract::create([
            'organization_id' => $generalContractorOrganization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'requires_contract_side_review' => true,
            'contract_side_review_reason' => 'ambiguous_backfill',
            'number' => 'CR-101',
            'date' => now()->toDateString(),
            'subject' => 'Тестовый договор',
            'total_amount' => 150000,
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        $resolved = $mutationService->resolveReview(
            $contract->id,
            $generalContractorOrganization->id,
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
        );

        $this->assertSame($customerOrganization->id, $resolved->organization_id);
        $this->assertSame(ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR, $resolved->contract_side_type);
        $this->assertFalse((bool) $resolved->requires_contract_side_review);
        $this->assertNull($resolved->contract_side_review_reason);
    }
}
