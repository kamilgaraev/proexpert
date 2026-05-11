<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class MaterialControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_materials_without_organization_leaks(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createUnit($context->organization->id, 'Piece', 'pcs');
        $foreignContext = AdminApiTestContext::create();
        $foreignUnit = $this->createUnit($foreignContext->organization->id, 'Foreign piece', 'fpcs');
        $foreignMaterial = Material::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Shared sand',
            'code' => 'SAND-F',
            'measurement_unit_id' => $foreignUnit->id,
            'category' => 'Foreign',
            'default_price' => 10,
            'is_active' => true,
        ]);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/materials', [
                'name' => 'Shared sand',
                'code' => 'SAND-1',
                'measurement_unit_id' => $unit->id,
                'category' => 'Bulk',
                'default_price' => 125.50,
                'is_active' => true,
        ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.name', 'Shared sand');
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);

        $material = Material::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $material->organization_id);
        $this->assertSame($unit->id, $material->measurement_unit_id);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/materials', [
                'name' => 'Shared sand',
                'measurement_unit_id' => $unit->id,
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonPath('success', false);
        $this->assertSame(1, Material::query()
            ->where('organization_id', $context->organization->id)
            ->where('name', 'Shared sand')
            ->count());

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/materials?per_page=20&name=Shared');

        $indexResponse->assertOk();
        $materialIds = collect($indexResponse->json('data'))->pluck('id')->all();
        $this->assertContains($material->id, $materialIds);
        $this->assertNotContains($foreignMaterial->id, $materialIds);

        $foreignUnitResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/materials/{$material->id}", [
                'measurement_unit_id' => $foreignUnit->id,
            ]);

        $foreignUnitResponse->assertStatus(422);
        $this->assertSame($unit->id, $material->fresh()->measurement_unit_id);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/materials/{$material->id}", [
                'name' => 'Washed sand',
                'default_price' => 140,
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Washed sand');
        $this->assertSame('Washed sand', $material->fresh()->name);

        $foreignShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/materials/{$foreignMaterial->id}");

        $foreignShowResponse->assertNotFound();

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/materials/{$material->id}");

        $deleteResponse->assertOk();
        $this->assertSoftDeleted('materials', ['id' => $material->id]);
    }

    private function createUnit(int $organizationId, string $name, string $shortName): MeasurementUnit
    {
        return MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'short_name' => $shortName,
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
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
