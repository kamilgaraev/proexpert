<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Estimate;
use App\Models\Machinery;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class MachineryControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_helpers_and_autocomplete_are_tenant_scoped_and_tolerate_admin_filters(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $machine = $this->createMachinery($context->organization->id, [
            'code' => 'MCH-CURRENT',
            'name' => 'Current crane',
            'category' => 'lifting',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignMachine = $this->createMachinery($foreignOrganization->id, [
            'code' => 'MCH-FOREIGN',
            'name' => 'Foreign crane',
            'category' => 'foreign',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery?search=&category=&is_active=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');
        $categoriesResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery/categories');
        $statisticsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery/statistics');
        $autocompleteResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/machinery/autocomplete?q=cra&limit=100');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($machine->id, $ids);
        $this->assertNotContains($foreignMachine->id, $ids);

        $categoriesResponse->assertOk();
        $categoriesResponse->assertJsonPath('data.0.name', 'lifting');
        $statisticsResponse->assertOk();
        $statisticsResponse->assertJsonPath('data.total', 1);
        $autocompleteResponse->assertOk();
        $autocompleteResponse->assertJsonPath('data.0.id', $machine->id);
    }

    public function test_store_show_update_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $unit = $this->unitFor($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);
        $foreignMachine = $this->createMachinery($foreignOrganization->id, [
            'code' => 'FOREIGN-MACHINE',
            'name' => 'Foreign machine',
            'measurement_unit_id' => $foreignUnit->id,
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery', [
                'name' => 'Current machine',
                'category' => 'lifting',
                'measurement_unit_id' => $unit->id,
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.code', null);

        $machineryId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/machinery/{$machineryId}");
        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/machinery/{$machineryId}", ['name' => 'Updated machine']);

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $machineryId);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Updated machine');
        $this->assertDatabaseHas('machinery', [
            'id' => $machineryId,
            'category' => 'lifting',
        ]);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/machinery/{$foreignMachine->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/machinery/{$foreignMachine->id}", ['name' => 'Leaked machine']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/machinery/{$foreignMachine->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/machinery/{$machineryId}");
        $deleteResponse->assertOk();
        $this->assertSoftDeleted('machinery', ['id' => $machineryId]);
    }

    public function test_code_uniqueness_and_measurement_unit_scope_are_enforced(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->unitFor($foreignOrganization->id);

        $this->createMachinery($context->organization->id, ['code' => 'DUP-MCH']);
        $this->createMachinery($foreignOrganization->id, ['code' => 'SHARED-MCH']);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery', [
                'name' => 'Duplicate machinery',
                'code' => 'DUP-MCH',
            ]);
        $foreignUnitResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery', [
                'name' => 'Foreign unit machinery',
                'measurement_unit_id' => $foreignUnit->id,
            ]);
        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/machinery', [
                'name' => 'Allowed machinery',
                'code' => 'SHARED-MCH',
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);
        $foreignUnitResponse->assertStatus(422);
        $foreignUnitResponse->assertJsonPath('success', false);
        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    public function test_machinery_used_in_estimates_cannot_be_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $this->activateBudgetEstimates($context->organization->id);
        $machine = $this->createMachinery($context->organization->id);
        $this->attachEstimateItem($context->organization->id, ['machinery_id' => $machine->id]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/machinery/{$machine->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('machinery', [
            'id' => $machine->id,
            'deleted_at' => null,
        ]);
    }

    private function createMachinery(int $organizationId, array $overrides = []): Machinery
    {
        return Machinery::query()->create(array_merge([
            'organization_id' => $organizationId,
            'code' => 'MCH',
            'name' => 'Machinery',
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
            'number' => 'EST-MCH',
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
