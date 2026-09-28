<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectWarehouseService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ProjectMaterialDeliveryMobileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_foreman_can_receive_mobile_delivery_and_update_project_stock(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $mobileHeaders = $context->mobileAuthHeaders();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->syncWithoutDetaching([
            $context->user->id => [
                'role' => 'foreman',
                'is_active' => true,
                'assigned_by_user_id' => $context->user->id,
                'assigned_at' => now(),
            ],
        ]);
        UserRoleAssignment::assignRole(
            $context->user,
            'foreman',
            AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id),
        );
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
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'unit_price' => 100,
        ]);
        app(ProjectWarehouseService::class)->shipToProject(
            $delivery,
            $context->user,
            5,
            $context->user->id,
            'Shipped to project for mobile acceptance',
        );
        $this->registerWarehouseEntitlement($context);

        $mobileListResponse = $this->withHeaders($mobileHeaders)
            ->getJson("/api/v1/mobile/warehouse/project-material-deliveries?project_id={$project->id}");

        $mobileListResponse->assertOk()
            ->assertJsonPath('data.items.0.id', $delivery->id)
            ->assertJsonPath('data.items.0.status', ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value);

        $mobileReceiveResponse = $this->withHeaders($mobileHeaders)
            ->postJson(
                "/api/v1/mobile/warehouse/project-material-deliveries/{$delivery->id}/receive",
                [
                    'idempotency_key' => (string) Str::uuid(),
                    'quantity' => 3,
                    'notes' => 'Accepted on site by mobile',
                ]);

        self::assertSame(
            200,
            $mobileReceiveResponse->status(),
            'Unexpected mobile delivery response: '.$mobileReceiveResponse->getContent()
        );
        $mobileReceiveResponse
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

        $this->assertDatabaseHas('project_material_delivery_events', [
            'project_material_delivery_id' => $delivery->id,
            'event_type' => 'received',
            'from_status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value,
            'to_status' => ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value,
            'quantity' => 3,
        ]);

        $this->flushHeaders();
        $completeReceiveResponse = $this->withHeaders($mobileHeaders)
            ->postJson(
                "/api/v1/mobile/warehouse/project-material-deliveries/{$delivery->id}/receive",
                [
                    'idempotency_key' => (string) Str::uuid(),
                    'quantity' => 2,
                    'notes' => 'Remaining quantity accepted',
                ]);

        self::assertSame(200, $completeReceiveResponse->status(), $completeReceiveResponse->getContent());
        $completeReceiveResponse
            ->assertJsonPath('data.status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)
            ->assertJsonPath('data.accepted_quantity', 5);

        $this->assertDatabaseHas('project_material_deliveries', [
            'id' => $delivery->id,
            'status' => ProjectMaterialDeliveryStatusEnum::ACCEPTED->value,
            'accepted_quantity' => 5,
            'receiver_user_id' => $context->user->id,
        ]);
        $this->assertDatabaseHas('project_material_delivery_events', [
            'project_material_delivery_id' => $delivery->id,
            'event_type' => 'received',
            'from_status' => ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value,
            'to_status' => ProjectMaterialDeliveryStatusEnum::ACCEPTED->value,
            'quantity' => 2,
        ]);
    }

    private function registerWarehouseEntitlement(AdminApiTestContext $context): void
    {
        $module = new BasicWarehouseModule;
        $manifest = $module->getManifest();
        Module::query()->updateOrCreate(
            ['slug' => $module->getSlug()],
            [
                'name' => $module->getName(),
                'version' => $module->getVersion(),
                'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value,
                'category' => $manifest['category'] ?? 'warehouse',
                'description' => $module->getDescription(),
                'features' => $module->getFeatures(),
                'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(),
                'conflicts' => $module->getConflicts(),
                'limits' => $module->getLimits(),
                'class_name' => $module::class,
                'config_file' => 'ModuleList/features/basic-warehouse.json',
                'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id,
            'responsible_user_id' => $context->user->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'supply-warehouse',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 9900,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        $access = $this->app->make(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        self::assertTrue($access->hasModuleAccess((int) $context->organization->id, 'basic-warehouse'));
    }
}
