<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ProjectMaterialDeliveryMovementTest extends TestCase
{
    public function test_project_delivery_can_store_project_warehouse_and_movement_links(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_warehouses', 'project_id'));
        $this->assertTrue(Schema::hasColumn('organization_warehouses', 'responsible_user_id'));
        $this->assertTrue(Schema::hasColumn('project_material_deliveries', 'project_warehouse_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'related_user_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'operation_category'));
        $this->assertTrue(Schema::hasColumn('warehouse_movements', 'project_material_delivery_id'));
        $this->assertTrue(Schema::hasColumn('journal_materials', 'warehouse_movement_id'));
        $this->assertTrue(Schema::hasColumn('journal_materials', 'custody_warehouse_id'));
    }

    public function test_custody_warehouse_can_be_linked_to_project_and_responsible_user(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $responsibleUser->id,
            'name' => 'Ответственный: ' . $responsibleUser->name,
            'code' => 'CUST-' . $responsibleUser->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_active' => true,
            'is_main' => false,
        ]);

        $this->assertSame(OrganizationWarehouse::TYPE_CUSTODY, $warehouse->warehouse_type);
        $this->assertSame($project->id, $warehouse->project?->id);
        $this->assertSame($responsibleUser->id, $warehouse->responsibleUser?->id);
    }

    public function test_shipping_delivery_creates_outbound_movement_and_decreases_source_stock(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 30,
                'responsible_user_id' => $context->user->id,
                'notes' => 'Отправка на объект',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $setup['sourceWarehouse']->id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 70,
        ]);

        $delivery = $setup['delivery']->fresh();

        $this->assertNotNull($delivery->outbound_movement_id);
        $this->assertNotNull($delivery->project_warehouse_id);
        $this->assertSame('in_transit', $delivery->status->value);

        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $delivery->outbound_movement_id,
            'warehouse_id' => $setup['sourceWarehouse']->id,
            'to_warehouse_id' => $delivery->project_warehouse_id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'quantity' => 30,
            'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
            'project_material_delivery_id' => $delivery->id,
            'related_user_id' => $context->user->id,
        ]);

        $this->assertDatabaseMissing('warehouse_balances', [
            'warehouse_id' => $delivery->project_warehouse_id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 30,
        ]);
    }

    public function test_admin_receiving_delivery_creates_inbound_movement_and_increases_project_stock(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 30,
                'responsible_user_id' => $context->user->id,
            ])
            ->assertOk();

        $delivery = $setup['delivery']->fresh();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$delivery->id}/receive", [
                'quantity' => 25,
                'notes' => 'Принято в админке',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $delivery = $delivery->fresh();

        $this->assertSame('partially_delivered', $delivery->status->value);
        $this->assertSame(25.0, (float) $delivery->accepted_quantity);
        $this->assertNotNull($delivery->inbound_movement_id);

        $this->assertDatabaseHas('warehouse_balances', [
            'warehouse_id' => $delivery->project_warehouse_id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 25,
        ]);

        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $delivery->inbound_movement_id,
            'warehouse_id' => $delivery->project_warehouse_id,
            'from_warehouse_id' => $setup['sourceWarehouse']->id,
            'material_id' => $setup['material']->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_IN,
            'quantity' => 25,
            'operation_category' => WarehouseMovement::CATEGORY_PROJECT_DELIVERY,
            'project_material_delivery_id' => $delivery->id,
        ]);
    }

    public function test_admin_delivery_cannot_receive_more_than_shipped(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $setup = $this->createDeliveryContext($context);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$setup['delivery']->id}/ship", [
                'quantity' => 10,
            ])
            ->assertOk();

        $delivery = $setup['delivery']->fresh();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/project-material-deliveries/{$delivery->id}/receive", [
                'quantity' => 11,
            ]);

        $response->assertStatus(422);

        $this->assertSame(0.0, (float) $delivery->fresh()->accepted_quantity);
        $this->assertDatabaseMissing('warehouse_balances', [
            'warehouse_id' => $delivery->project_warehouse_id,
            'material_id' => $setup['material']->id,
            'available_quantity' => 11,
        ]);
    }

    private function createDeliveryContext(AdminApiTestContext $context): array
    {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $sourceWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Центральный склад',
            'code' => 'MAIN-' . $project->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Гвозди строительные',
            'code' => 'NAILS-' . $project->id,
            'default_price' => 12.5,
            'is_active' => true,
        ]);

        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 100,
            'reserved_quantity' => 0,
            'unit_price' => 12.5,
        ]);

        $allocation = WarehouseProjectAllocation::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'allocated_quantity' => 30,
            'allocated_by_user_id' => $context->user->id,
            'allocated_at' => now(),
        ]);

        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $sourceWarehouse->id,
            'warehouse_project_allocation_id' => $allocation->id,
            'source_type' => 'warehouse',
            'status' => 'reserved',
            'requested_quantity' => 30,
            'reserved_quantity' => 30,
        ]);

        return [
            'project' => $project,
            'sourceWarehouse' => $sourceWarehouse,
            'material' => $material,
            'allocation' => $allocation,
            'delivery' => $delivery,
        ];
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
