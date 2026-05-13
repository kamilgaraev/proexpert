<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ContractorType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ContractorControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_tenant_scoped_and_tolerates_admin_registry_filters(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = $this->createContractor($context->organization->id, [
            'name' => 'Alpha Build',
            'inn' => '7701000001',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignContractor = $this->createContractor($foreignOrganization->id, [
            'name' => 'Foreign Build',
            'inn' => '7701000002',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/contractors?name=&inn=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $contractor->id);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignContractor->id, $ids);
    }

    public function test_store_update_show_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignContractor = $this->createContractor($foreignOrganization->id, [
            'name' => 'Foreign contractor',
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/contractors', [
                'name' => 'Current contractor',
                'contact_person' => 'Ivan',
                'phone' => '+79990000000',
                'email' => 'current@example.test',
                'inn' => '7701000003',
                'organization_id_for_creation' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $contractorId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractors/{$contractorId}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $contractorId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/contractors/{$contractorId}", [
                'name' => 'Updated contractor',
                'email' => 'updated@example.test',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.name', 'Updated contractor');

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractors/{$foreignContractor->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/contractors/{$foreignContractor->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/contractors/{$foreignContractor->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/contractors/{$contractorId}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('contractors', ['id' => $contractorId]);
        $this->assertDatabaseHas('contractors', [
            'id' => $foreignContractor->id,
            'name' => 'Foreign contractor',
        ]);
    }

    public function test_duplicate_contacts_are_rejected_only_inside_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();

        $this->createContractor($context->organization->id, [
            'name' => 'Existing contractor',
            'email' => 'duplicate@example.test',
            'inn' => '7701000004',
        ]);
        $foreignContractor = $this->createContractor($foreignOrganization->id, [
            'name' => 'Foreign duplicate contractor',
            'email' => 'shared@example.test',
            'inn' => '7701000005',
        ]);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/contractors', [
                'name' => 'Duplicate contractor',
                'email' => 'duplicate@example.test',
                'inn' => '7701000004',
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);

        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/contractors', [
                'name' => 'Allowed contractor',
                'email' => $foreignContractor->email,
                'inn' => $foreignContractor->inn,
            ]);

        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    public function test_contractors_with_active_contracts_cannot_be_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $contractor = $this->createContractor($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'CONTRACTOR-ACTIVE-1',
            'date' => '2026-05-01',
            'subject' => 'Works',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/contractors/{$contractor->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('contractors', [
            'id' => $contractor->id,
            'deleted_at' => null,
        ]);
    }

    private function createContractor(int $organizationId, array $overrides = []): Contractor
    {
        return Contractor::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Contractor',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'legal_address' => null,
            'inn' => null,
            'kpp' => null,
            'bank_details' => null,
            'notes' => null,
            'contractor_type' => ContractorType::MANUAL->value,
        ], $overrides));
    }
}
