<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class MeasurementUnitControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_tenant_scoped_and_tolerates_admin_registry_filters(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id, [
            'name' => 'Current kilogram',
            'short_name' => 'kg-current',
            'type' => 'material',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->createUnit($foreignOrganization->id, [
            'name' => 'Foreign kilogram',
            'short_name' => 'kg-foreign',
            'type' => 'material',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/measurement-units?type=&per_page=-1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'meta.total',
            MeasurementUnit::query()->where('organization_id', $context->organization->id)->count()
        );

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unit->id, $ids);
        $this->assertNotContains($foreignUnit->id, $ids);
    }

    public function test_store_show_update_material_units_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->createUnit($foreignOrganization->id, [
            'name' => 'Foreign unit',
            'short_name' => 'foreign-unit',
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/measurement-units', [
                'name' => 'Current unit',
                'code' => 'cur',
                'short_name' => 'cur',
                'type' => 'material',
                'organization_id' => $foreignOrganization->id,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.short_name', 'cur');

        $unitId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/measurement-units/{$unitId}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('success', true);
        $showResponse->assertJsonPath('data.id', $unitId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/measurement-units/{$unitId}", [
                'name' => 'Updated current unit',
            ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.name', 'Updated current unit');
        $this->assertDatabaseHas('measurement_units', [
            'id' => $unitId,
            'short_name' => 'cur',
        ]);

        $materialUnitsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/measurement-units/material-units');
        $materialUnitsResponse->assertOk();
        $materialUnitsResponse->assertJsonPath('success', true);
        $materialUnitIds = collect($materialUnitsResponse->json('data'))->pluck('id')->all();
        $this->assertContains($unitId, $materialUnitIds);
        $this->assertNotContains($foreignUnit->id, $materialUnitIds);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/measurement-units/{$foreignUnit->id}");
        $foreignUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/measurement-units/{$foreignUnit->id}", ['name' => 'Leaked update']);
        $foreignDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/measurement-units/{$foreignUnit->id}");

        $foreignShowResponse->assertNotFound();
        $foreignUpdateResponse->assertNotFound();
        $foreignDeleteResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/measurement-units/{$unitId}");
        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('measurement_units', ['id' => $unitId]);
    }

    public function test_measurement_unit_name_and_short_name_must_be_unique_only_inside_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $foreignOrganization = Organization::factory()->verified()->create();

        $this->createUnit($context->organization->id, [
            'name' => 'Duplicate unit',
            'short_name' => 'dup',
        ]);
        $this->createUnit($foreignOrganization->id, [
            'name' => 'Foreign shared unit',
            'short_name' => 'foreign-shared',
        ]);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/measurement-units', [
                'name' => 'Duplicate unit',
                'short_name' => 'dup-new',
            ]);
        $duplicateShortNameResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/measurement-units', [
                'name' => 'Unique unit',
                'short_name' => 'DUP',
            ]);
        $allowedResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/measurement-units', [
                'name' => 'Foreign shared unit',
                'short_name' => 'allowed',
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);
        $duplicateShortNameResponse->assertStatus(422);
        $duplicateShortNameResponse->assertJsonPath('success', false);
        $allowedResponse->assertCreated();
        $allowedResponse->assertJsonPath('success', true);
        $allowedResponse->assertJsonPath('data.organization_id', $context->organization->id);
    }

    public function test_measurement_units_in_use_or_system_cannot_be_changed_unsafely(): void
    {
        $context = AdminApiTestContext::create();
        $systemUnit = $this->createUnit($context->organization->id, [
            'name' => 'System unit',
            'short_name' => 'sys-current',
            'is_system' => true,
        ]);
        $usedUnit = $this->createUnit($context->organization->id, [
            'name' => 'Used unit',
            'short_name' => 'used-current',
            'type' => 'material',
        ]);

        Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Used material',
            'measurement_unit_id' => $usedUnit->id,
            'category' => 'building',
            'is_active' => true,
        ]);

        $systemUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/measurement-units/{$systemUnit->id}", ['name' => 'Changed system']);
        $systemDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/measurement-units/{$systemUnit->id}");
        $usedTypeResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/measurement-units/{$usedUnit->id}", ['type' => 'work']);
        $usedDeleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/measurement-units/{$usedUnit->id}");

        $systemUpdateResponse->assertStatus(422);
        $systemUpdateResponse->assertJsonPath('success', false);
        $systemDeleteResponse->assertStatus(422);
        $systemDeleteResponse->assertJsonPath('success', false);
        $usedTypeResponse->assertStatus(422);
        $usedTypeResponse->assertJsonPath('success', false);
        $usedDeleteResponse->assertStatus(422);
        $usedDeleteResponse->assertJsonPath('success', false);
    }

    private function createUnit(int $organizationId, array $overrides = []): MeasurementUnit
    {
        return MeasurementUnit::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Unit',
            'short_name' => 'unit',
            'type' => 'material',
            'description' => null,
            'is_default' => false,
            'is_system' => false,
        ], $overrides));
    }
}
