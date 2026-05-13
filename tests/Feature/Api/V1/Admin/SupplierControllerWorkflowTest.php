<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class SupplierControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_tenant_scoped_and_tolerates_admin_registry_filters(): void
    {
        $context = AdminApiTestContext::create();
        $supplier = $this->createSupplier($context->organization->id, [
            'name' => 'Alpha Supply',
            'email' => 'alpha@example.test',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignSupplier = $this->createSupplier($foreignOrganization->id, [
            'name' => 'Foreign Supply',
            'email' => 'foreign@example.test',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/suppliers?name=&is_active=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $supplier->id);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignSupplier->id, $ids);
    }

    public function test_store_update_show_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignSupplier = $this->createSupplier($foreignOrganization->id, ['name' => 'Foreign supplier']);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/suppliers', [
                'name' => 'Current supplier',
                'contact_person' => 'Ivan',
                'phone' => '+79990000000',
                'email' => 'current-supplier@example.test',
                'address' => 'Kazan',
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $supplierId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/suppliers/{$supplierId}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $supplierId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/suppliers/{$supplierId}", [
                'name' => 'Updated supplier',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.name', 'Updated supplier');
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplierId,
            'email' => 'current-supplier@example.test',
        ]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/suppliers/{$foreignSupplier->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/suppliers/{$foreignSupplier->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/suppliers/{$foreignSupplier->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/suppliers/{$supplierId}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('suppliers', ['id' => $supplierId]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $foreignSupplier->id,
            'name' => 'Foreign supplier',
        ]);
    }

    public function test_supplier_name_must_be_unique_only_inside_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();

        $this->createSupplier($context->organization->id, ['name' => 'Duplicate Supply']);
        $this->createSupplier($foreignOrganization->id, ['name' => 'Foreign Shared Supply']);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/suppliers', ['name' => 'Duplicate Supply']);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);

        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/suppliers', ['name' => 'Foreign Shared Supply']);

        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    public function test_suppliers_used_by_contracts_cannot_be_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $supplier = $this->createSupplier($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'number' => 'SUPPLIER-ACTIVE-1',
            'date' => '2026-05-01',
            'subject' => 'Supply',
            'total_amount' => 100000,
            'status' => 'active',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/suppliers/{$supplier->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'deleted_at' => null,
        ]);
    }

    private function createSupplier(int $organizationId, array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Supplier',
            'contact_person' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'is_active' => true,
        ], $overrides));
    }
}
