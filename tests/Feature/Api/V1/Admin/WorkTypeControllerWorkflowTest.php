<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Module;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WorkTypeControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_tenant_scoped_and_tolerates_admin_registry_filters(): void
    {
        $context = $this->createContextWithCatalogModule();
        $unit = $this->unitFor($context->organization->id);
        $workType = $this->createWorkType($context->organization->id, $unit->id, [
            'name' => 'Concrete works',
            'category' => 'smr',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);
        $foreignWorkType = $this->createWorkType($foreignOrganization->id, $foreignUnit->id, [
            'name' => 'Foreign works',
            'category' => 'smr',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/work-types?name=&category=&is_active=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $workType->id);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreignWorkType->id, $ids);
    }

    public function test_index_allows_work_type_view_without_catalog_manage_permission(): void
    {
        $context = $this->createContextWithCatalogModule('foreman');
        $unit = $this->unitFor($context->organization->id);
        $workType = $this->createWorkType($context->organization->id, $unit->id, [
            'name' => 'Masonry works',
            'category' => 'masonry',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/work-types?per_page=10&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $workType->id);
    }

    public function test_store_update_show_and_delete_are_scoped_to_current_organization(): void
    {
        $context = $this->createContextWithCatalogModule();
        $unit = $this->unitFor($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);
        $foreignWorkType = $this->createWorkType($foreignOrganization->id, $foreignUnit->id, [
            'name' => 'Foreign work type',
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/work-types', [
                'name' => 'Current work type',
                'measurement_unit_id' => $unit->id,
                'category' => 'smr',
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organizationId', $context->organization->id);

        $workTypeId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/work-types/{$workTypeId}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $workTypeId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/work-types/{$workTypeId}", [
                'name' => 'Updated work type',
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.name', 'Updated work type');
        $this->assertDatabaseHas('work_types', [
            'id' => $workTypeId,
            'category' => 'smr',
        ]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/work-types/{$foreignWorkType->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/work-types/{$foreignWorkType->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/work-types/{$foreignWorkType->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/work-types/{$workTypeId}");

        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('work_types', ['id' => $workTypeId]);
        $this->assertDatabaseHas('work_types', [
            'id' => $foreignWorkType->id,
            'name' => 'Foreign work type',
        ]);
    }

    public function test_work_type_name_must_be_unique_only_inside_current_organization(): void
    {
        $context = $this->createContextWithCatalogModule();
        $unit = $this->unitFor($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);

        $this->createWorkType($context->organization->id, $unit->id, ['name' => 'Duplicate work']);
        $this->createWorkType($foreignOrganization->id, $foreignUnit->id, ['name' => 'Foreign shared work']);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/work-types', [
                'name' => 'Duplicate work',
                'measurement_unit_id' => $unit->id,
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);

        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/work-types', [
                'name' => 'Foreign shared work',
                'measurement_unit_id' => $unit->id,
            ]);

        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
        $allowedResponse->assertJsonPath('data.organizationId', $context->organization->id);
    }

    public function test_work_types_used_by_materials_cannot_be_deleted(): void
    {
        $context = $this->createContextWithCatalogModule();
        $unit = $this->unitFor($context->organization->id);
        $workType = $this->createWorkType($context->organization->id, $unit->id);

        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete',
            'measurement_unit_id' => $unit->id,
            'category' => 'building',
            'is_active' => true,
        ]);
        $workType->materials()->attach($material->id, [
            'organization_id' => $context->organization->id,
            'default_quantity' => 1,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/work-types/{$workType->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('work_types', [
            'id' => $workType->id,
            'deleted_at' => null,
        ]);
    }

    private function createContextWithCatalogModule(string $roleSlug = 'web_admin'): AdminApiTestContext
    {
        $context = AdminApiTestContext::create(roleSlug: $roleSlug);
        $this->activateCatalogManagementModule($context->organization->id);

        return $context;
    }

    private function activateCatalogManagementModule(int $organizationId): void
    {
        $module = Module::query()->updateOrCreate(
            ['slug' => 'catalog-management'],
            [
                'name' => 'Catalog management',
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'category' => 'catalog',
                'description' => 'Catalog management',
                'pricing_config' => ['base_price' => 0, 'currency' => 'RUB'],
                'features' => [],
                'permissions' => ['*'],
                'dependencies' => [],
                'conflicts' => [],
                'limits' => [],
                'display_order' => 1,
                'is_active' => true,
                'is_system_module' => false,
                'can_deactivate' => true,
            ],
        );

        OrganizationModuleActivation::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'module_id' => $module->id,
            ],
            [
                'status' => 'active',
                'activated_at' => now(),
                'expires_at' => null,
                'trial_ends_at' => null,
                'last_used_at' => now(),
                'paid_amount' => 0,
                'payment_details' => [],
                'module_settings' => [],
                'usage_stats' => [],
                'is_bundled_with_plan' => true,
                'is_auto_renew_enabled' => false,
            ],
        );
    }

    private function unitFor(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()
            ->where('organization_id', $organizationId)
            ->firstOrFail();
    }

    private function createWorkType(int $organizationId, int $measurementUnitId, array $overrides = []): WorkType
    {
        return WorkType::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Work type',
            'measurement_unit_id' => $measurementUnitId,
            'category' => null,
            'is_active' => true,
        ], $overrides));
    }
}
