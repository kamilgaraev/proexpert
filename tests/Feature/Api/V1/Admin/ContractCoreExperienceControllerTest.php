<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\ContractorType;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ContractCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_list_and_delete_contract_inside_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Contract Owner Project',
        ]);
        $anotherProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Another Owner Project',
        ]);
        $contractor = $this->createContractor($context->organization, 'Owner Contractor');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts", [
                'project_id' => $project->id,
                'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
                'contractor_id' => $contractor->id,
                'number' => 'CON-001',
                'date' => '2026-06-01',
                'subject' => 'Engineering systems installation',
                'work_type_category' => ContractWorkTypeCategoryEnum::INSTALLATION->value,
                'is_fixed_amount' => true,
                'base_amount' => 500000,
                'total_amount' => 500000,
                'gp_percentage' => 5,
                'planned_advance_amount' => 100000,
                'actual_advance_amount' => 25000,
                'status' => ContractStatusEnum::ACTIVE->value,
                'start_date' => '2026-06-05',
                'end_date' => '2026-08-20',
                'notes' => 'Owner contract notes',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.number', 'CON-001');
        $createResponse->assertJsonPath('data.contractor_id', $contractor->id);

        $contract = Contract::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $contract->organization_id);
        $this->assertSame($project->id, $contract->project_id);
        $this->assertSame(500000.0, (float) $contract->base_amount);
        $this->assertSame(500000.0, (float) $contract->total_amount);
        $this->assertSame(100000.0, (float) $contract->planned_advance_amount);
        $this->assertSame(25000.0, (float) $contract->actual_advance_amount);

        $otherProjectContract = $this->createContract($context->organization, $anotherProject, $contractor, [
            'number' => 'CON-OTHER-PROJECT',
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts?per_page=20&sort_by=number&sort_direction=asc");

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $contractIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($contract->id, $contractIds);
        $this->assertNotContains($otherProjectContract->id, $contractIds);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}", [
                'number' => 'CON-001-UPD',
                'subject' => 'Updated contract subject',
                'base_amount' => 650000,
                'total_amount' => 650000,
                'planned_advance_amount' => 150000,
                'actual_advance_amount' => 75000,
                'status' => ContractStatusEnum::ON_HOLD->value,
                'notes' => null,
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.number', 'CON-001-UPD');
        $contract->refresh();
        $this->assertSame(650000.0, (float) $contract->total_amount);
        $this->assertSame(75000.0, (float) $contract->actual_advance_amount);
        $this->assertNull($contract->notes);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}");

        $deleteResponse->assertNoContent();
        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);
    }

    public function test_contract_update_and_delete_are_hidden_when_contract_belongs_to_another_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Project Scope Contractor');
        $contract = $this->createContract($context->organization, $anotherProject, $contractor, [
            'number' => 'OUT-OF-PROJECT',
        ]);
        $this->allowAdminAccess();

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}", [
                'number' => 'SHOULD-NOT-CHANGE',
            ]);

        $updateResponse->assertNotFound();
        $this->assertSame('OUT-OF-PROJECT', $contract->fresh()->number);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}");

        $deleteResponse->assertNotFound();
        $this->assertNotSoftDeleted('contracts', ['id' => $contract->id]);
    }

    public function test_contractor_participant_sees_only_own_contracts_and_cannot_mutate_other_contracts(): void
    {
        $participantContext = AdminApiTestContext::create();
        $otherParticipantOrganization = Organization::factory()->verified()->create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Participant Contract Project',
        ]);
        $this->attachProjectParticipant($project, $participantContext->organization, ProjectOrganizationRole::CONTRACTOR);
        $this->attachProjectParticipant($project, $otherParticipantOrganization, ProjectOrganizationRole::CONTRACTOR);

        $ownContractor = $this->createContractor($ownerOrganization, 'Own Participant Contractor', $participantContext->organization);
        $otherContractor = $this->createContractor($ownerOrganization, 'Other Participant Contractor', $otherParticipantOrganization);
        $ownContract = $this->createContract($ownerOrganization, $project, $ownContractor, [
            'number' => 'OWN-CONTRACT',
        ]);
        $otherContract = $this->createContract($ownerOrganization, $project, $otherContractor, [
            'number' => 'OTHER-CONTRACT',
        ]);
        $this->allowAdminAccess();

        $indexResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts?per_page=20");

        $indexResponse->assertOk();
        $indexIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($ownContract->id, $indexIds);
        $this->assertNotContains($otherContract->id, $indexIds);

        $ownShowResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts/{$ownContract->id}");
        $ownShowResponse->assertOk();
        $ownShowResponse->assertJsonPath('data.id', $ownContract->id);

        $otherShowResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts/{$otherContract->id}");
        $otherShowResponse->assertNotFound();

        $otherUpdateResponse = $this->withHeaders($participantContext->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$otherContract->id}", [
                'number' => 'LEAKED-UPDATE',
            ]);
        $otherUpdateResponse->assertNotFound();
        $this->assertSame('OTHER-CONTRACT', $otherContract->fresh()->number);

        $otherDeleteResponse = $this->withHeaders($participantContext->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/contracts/{$otherContract->id}");
        $otherDeleteResponse->assertNotFound();
        $this->assertNotSoftDeleted('contracts', ['id' => $otherContract->id]);
    }

    private function createContractor(
        Organization $organization,
        string $name,
        ?Organization $sourceOrganization = null
    ): Contractor {
        return Contractor::query()->create([
            'organization_id' => $organization->id,
            'source_organization_id' => $sourceOrganization?->id,
            'name' => $name,
            'contact_person' => $name . ' Manager',
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'inn' => (string) random_int(1000000000, 9999999999),
            'contractor_type' => $sourceOrganization
                ? ContractorType::INVITED_ORGANIZATION->value
                : ContractorType::MANUAL->value,
            'connected_at' => $sourceOrganization ? now() : null,
        ]);
    }

    private function createContract(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        array $overrides = []
    ): Contract {
        return Contract::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'number' => 'CON-' . random_int(10000, 99999),
            'date' => '2026-06-01',
            'subject' => 'Contract subject',
            'work_type_category' => ContractWorkTypeCategoryEnum::SMR->value,
            'base_amount' => 300000,
            'total_amount' => 300000,
            'gp_percentage' => 0,
            'planned_advance_amount' => 0,
            'actual_advance_amount' => 0,
            'status' => ContractStatusEnum::ACTIVE->value,
            'start_date' => '2026-06-01',
            'end_date' => '2026-09-01',
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ], $overrides));
    }

    private function attachProjectParticipant(
        Project $project,
        Organization $organization,
        ProjectOrganizationRole $role
    ): void {
        $project->organizations()->attach($organization->id, [
            'role' => $role->value,
            'role_new' => $role->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
