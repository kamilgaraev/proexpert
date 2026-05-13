<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Estimate;
use App\Models\LaborResource;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class LaborResourceControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_helpers_and_autocomplete_are_tenant_scoped_and_tolerate_admin_filters(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $labor = $this->createLabor($context->organization->id, [
            'code' => 'LAB-CURRENT',
            'name' => 'Current mason',
            'profession' => 'Mason',
            'category' => 'construction',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignLabor = $this->createLabor($foreignOrganization->id, [
            'code' => 'LAB-FOREIGN',
            'name' => 'Foreign mason',
            'profession' => 'Mason',
            'category' => 'foreign',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/labor-resources?search=&profession=&category=&skill_level=&is_active=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');
        $professionsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/labor-resources/professions');
        $categoriesResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/labor-resources/categories');
        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/labor-resources/statistics');
        $autocompleteResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/labor-resources/autocomplete?q=mas&limit=100');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($labor->id, $ids);
        $this->assertNotContains($foreignLabor->id, $ids);

        $professionsResponse->assertOk();
        $professionsResponse->assertJsonPath('data.0.name', 'Mason');
        $categoriesResponse->assertOk();
        $categoriesResponse->assertJsonPath('data.0.name', 'construction');
        $statisticsResponse->assertOk();
        $statisticsResponse->assertJsonPath('data.total', 1);
        $autocompleteResponse->assertOk();
        $autocompleteResponse->assertJsonPath('data.0.id', $labor->id);
    }

    public function test_store_show_update_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $unit = $this->unitFor($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);
        $foreignLabor = $this->createLabor($foreignOrganization->id, [
            'code' => 'FOREIGN-LABOR',
            'name' => 'Foreign labor',
            'measurement_unit_id' => $foreignUnit->id,
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/labor-resources', [
                'name' => 'Current labor',
                'profession' => 'Painter',
                'measurement_unit_id' => $unit->id,
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.code', null);

        $laborId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/labor-resources/{$laborId}");
        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/labor-resources/{$laborId}", ['name' => 'Updated labor']);

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $laborId);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Updated labor');
        $this->assertDatabaseHas('labor_resources', [
            'id' => $laborId,
            'profession' => 'Painter',
        ]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/labor-resources/{$foreignLabor->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/labor-resources/{$foreignLabor->id}", ['name' => 'Leaked labor']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/labor-resources/{$foreignLabor->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/labor-resources/{$laborId}");
        $deleteResponse->assertOk();
        $this->assertSoftDeleted('labor_resources', ['id' => $laborId]);
    }

    public function test_code_uniqueness_and_measurement_unit_scope_are_enforced(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);

        $this->createLabor($context->organization->id, ['code' => 'DUP-LABOR']);
        $this->createLabor($foreignOrganization->id, ['code' => 'SHARED-LABOR']);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/labor-resources', [
                'name' => 'Duplicate labor',
                'code' => 'DUP-LABOR',
            ]);
        $foreignUnitResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/labor-resources', [
                'name' => 'Foreign unit labor',
                'measurement_unit_id' => $foreignUnit->id,
            ]);
        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/labor-resources', [
                'name' => 'Allowed labor',
                'code' => 'SHARED-LABOR',
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);
        $foreignUnitResponse->assertStatus(422);
        $foreignUnitResponse->assertJsonPath('success', false);
        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    public function test_labor_resource_used_in_estimates_cannot_be_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $labor = $this->createLabor($context->organization->id);
        $this->attachEstimateItem($context->organization->id, ['labor_resource_id' => $labor->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/labor-resources/{$labor->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('labor_resources', [
            'id' => $labor->id,
            'deleted_at' => null,
        ]);
    }

    private function createLabor(int $organizationId, array $overrides = []): LaborResource
    {
        return LaborResource::query()->create(array_merge([
            'organization_id' => $organizationId,
            'code' => 'LAB',
            'name' => 'Labor',
            'profession' => null,
            'skill_level' => null,
            'category' => null,
            'measurement_unit_id' => null,
            'hourly_rate' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function unitFor(int $organizationId): MeasurementUnit
    {
        return MeasurementUnit::query()->where('organization_id', $organizationId)->firstOrFail();
    }

    private function attachEstimateItem(int $organizationId, array $overrides): void
    {
        $estimate = Estimate::query()->create([
            'organization_id' => $organizationId,
            'number' => 'EST-LAB',
            'name' => 'Estimate',
            'estimate_date' => '2026-05-13',
        ]);

        DB::table('estimate_items')->insert(array_merge([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'name' => 'Resource row',
            'quantity' => 1,
            'unit_price' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function activateBudgetEstimates(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'budget-estimates'],
            [
                'name' => 'Budget estimates',
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'category' => 'estimates',
                'is_active' => true,
                'development_status' => 'stable',
            ]
        );

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
