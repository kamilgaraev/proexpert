<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProjectMaterialDeliveryMobileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_receive_project_delivery_is_visible_in_admin_delivery_and_stock(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->syncWithoutDetaching([
            $context->user->id => ['role' => 'foreman'],
        ]);
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Cubic meter',
            'short_name' => 'm3',
            'type' => 'material',
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete M350',
            'code' => 'CONC-M350',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Central warehouse',
            'code' => 'WH-MOB-SYNC',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $warehouse->id,
            'source_type' => 'warehouse',
            'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value,
            'requested_quantity' => 5,
            'reserved_quantity' => 5,
            'shipped_quantity' => 5,
            'accepted_quantity' => 0,
            'planned_delivery_date' => now()->toDateString(),
        ]);
        $this->allowAccess();

        $mobileListResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/mobile/warehouse/project-material-deliveries?project_id={$project->id}");

        $mobileListResponse->assertOk()
            ->assertJsonPath('data.items.0.id', $delivery->id)
            ->assertJsonPath('data.items.0.status', ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value);

        $mobileReceiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/warehouse/project-material-deliveries/{$delivery->id}/receive", [
                'quantity' => 3,
                'notes' => 'Accepted on site by mobile',
            ]);

        $mobileReceiveResponse->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.status', ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value)
            ->assertJsonPath('data.accepted_quantity', 3);

        $this->assertDatabaseHas('project_material_deliveries', [
            'id' => $delivery->id,
            'status' => ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value,
            'accepted_quantity' => 3,
            'receiver_user_id' => $context->user->id,
            'notes' => 'Accepted on site by mobile',
        ]);
        $this->assertDatabaseHas('project_material_delivery_events', [
            'project_material_delivery_id' => $delivery->id,
            'event_type' => 'received',
            'from_status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value,
            'to_status' => ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value,
            'quantity' => 3,
        ]);

        $adminShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/project-material-deliveries/{$delivery->id}");

        $adminShowResponse->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.status', ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value)
            ->assertJsonPath('data.accepted_quantity', 3)
            ->assertJsonPath('data.latest_event.event_type', 'received');

        $adminStockResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/project-material-deliveries/project-stock?project_id={$project->id}");

        $adminStockResponse->assertOk()
            ->assertJsonPath('data.summary.materials_count', 0)
            ->assertJsonPath('data.summary.accepted_quantity', 0);

        $completeReceiveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/mobile/warehouse/project-material-deliveries/{$delivery->id}/receive", [
                'quantity' => 2,
                'notes' => 'Remaining quantity accepted',
            ]);

        $completeReceiveResponse->assertOk()
            ->assertJsonPath('data.status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)
            ->assertJsonPath('data.accepted_quantity', 5);

        $adminAcceptedStockResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/project-material-deliveries/project-stock?project_id={$project->id}");

        $adminAcceptedStockResponse->assertOk()
            ->assertJsonPath('data.summary.materials_count', 1)
            ->assertJsonPath('data.summary.deliveries_count', 1)
            ->assertJsonPath('data.summary.accepted_quantity', 5)
            ->assertJsonPath('data.items.0.material.id', $material->id)
            ->assertJsonPath('data.items.0.deliveries.0.id', $delivery->id);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'basic-warehouse',
                    'project-management',
                ], true)
            );
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'modules' => [
                    'basic-warehouse' => [
                        'warehouse.view',
                        'warehouse.receipts',
                        'warehouse.manage_stock',
                    ],
                ],
            ]);
        });
    }
}
