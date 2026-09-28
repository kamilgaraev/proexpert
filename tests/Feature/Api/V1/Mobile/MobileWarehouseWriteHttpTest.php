<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileWarehouseWriteHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_receipt_issue_return_and_write_off_persist_inventory(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->registerWarehouseEntitlement($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);
        $context->organization->users()->attach($responsibleUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $project->users()->attach($responsibleUser->id, [
            'role' => 'foreman',
            'assigned_by_user_id' => $context->user->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Project warehouse',
            'code' => 'MOB-WH-'.$project->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'is_main' => false,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Mobile test material',
            'code' => 'MOB-MAT-'.$project->id,
            'default_price' => 10,
            'is_active' => true,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 50,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);
        $headers = $context->mobileAuthHeaders();

        $receipt = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/receipt', [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'quantity' => 10,
            'price' => 10,
        ]);
        $this->assertMobileResponseStatus($receipt, 200);
        $this->assertResponsePathNotNull($receipt, 'data.movement_id');
        $this->assertResponsePathNotNull($receipt, 'data.photo_gallery');
        $this->assertWarehouseQuantity($warehouse, $material, 60);
        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $receipt->json('data.movement_id'),
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 10,
        ]);

        $issue = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/custody/issue', [
            'idempotency_key' => (string) Str::uuid(),
            'project_id' => $project->id,
            'project_warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'responsible_user_id' => $responsibleUser->id,
            'quantity' => 20,
        ]);
        $this->assertMobileResponseStatus($issue, 200);
        $custodyWarehouse = OrganizationWarehouse::query()
            ->where('organization_id', $context->organization->id)
            ->where('project_id', $project->id)
            ->where('responsible_user_id', $responsibleUser->id)
            ->where('warehouse_type', OrganizationWarehouse::TYPE_CUSTODY)
            ->firstOrFail();
        $this->assertWarehouseQuantity($warehouse, $material, 40);
        $this->assertWarehouseQuantity($custodyWarehouse, $material, 20);
        $this->assertDatabaseHas('warehouse_movements', [
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
            'project_id' => $project->id,
            'related_user_id' => $responsibleUser->id,
            'quantity' => 20,
        ]);

        $return = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/custody/return', [
            'idempotency_key' => (string) Str::uuid(),
            'custody_warehouse_id' => $custodyWarehouse->id,
            'material_id' => $material->id,
            'quantity' => 5,
        ]);
        $this->assertMobileResponseStatus($return, 200);
        $this->assertWarehouseQuantity($warehouse, $material, 45);
        $this->assertWarehouseQuantity($custodyWarehouse, $material, 15);
        $this->assertDatabaseHas('warehouse_movements', [
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN,
            'project_id' => $project->id,
            'related_user_id' => $responsibleUser->id,
            'quantity' => 5,
        ]);

        $writeOff = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/write-off', [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'quantity' => 3,
            'reason' => 'Damaged during transport',
            'operation_category' => WarehouseMovement::CATEGORY_LOSS,
        ]);
        $this->assertMobileResponseStatus($writeOff, 200);
        $this->assertResponsePathNotNull($writeOff, 'data.movement_id');
        $this->assertResponsePathNotNull($writeOff, 'data.remaining_total_quantity');
        $this->assertWarehouseQuantity($warehouse, $material, 42);
        $this->assertDatabaseHas('warehouse_movements', [
            'id' => $writeOff->json('data.movement_id'),
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            'quantity' => 3,
        ]);

        $destinationWarehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_PROJECT);
        $transfer = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/transfer', [
            'idempotency_key' => (string) Str::uuid(),
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $destinationWarehouse->id,
            'material_id' => $material->id,
            'quantity' => 2,
        ]);
        $this->assertMobileResponseStatus($transfer, 200);
        $this->assertWarehouseQuantity($warehouse, $material, 40);
        $this->assertWarehouseQuantity($destinationWarehouse, $material, 2);
    }

    public function test_storekeeper_can_issue_to_responsible_without_manage_stock(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'storekeeper');
        $this->registerWarehouseEntitlement($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        $warehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_PROJECT);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Storekeeper issue material',
            'code' => 'MOB-ISSUE-'.$project->id,
            'default_price' => 10,
            'is_active' => true,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 8,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);
        $responsibleUser = User::factory()->create([
            'current_organization_id' => $context->organization->id,
        ]);
        $context->organization->users()->attach($responsibleUser->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);

        $authorization = app(AuthorizationService::class);
        $projectContext = [
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'strict_project_scope' => true,
        ];
        self::assertTrue($authorization->can($context->user, 'warehouse.issue_to_responsible', $projectContext));
        self::assertTrue($authorization->can($context->user, 'warehouse.view_custody', $projectContext));
        self::assertFalse($authorization->can($context->user, 'warehouse.manage_stock', $projectContext));

        $response = $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/warehouse/custody/issue',
            [
                'idempotency_key' => (string) Str::uuid(),
                'project_id' => $project->id,
                'project_warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'responsible_user_id' => $responsibleUser->id,
                'quantity' => 2,
            ]
        );

        $this->assertMobileResponseStatus($response, 200);
        $this->assertWarehouseQuantity($warehouse, $material, 6);
        $this->assertDatabaseHas('warehouse_movements', [
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_ISSUE,
            'project_id' => $project->id,
            'related_user_id' => $responsibleUser->id,
            'quantity' => 2,
        ]);
        $balances = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/warehouse/custody/balances?project_id='.$project->id);
        $this->assertMobileResponseStatus($balances, 200);
    }

    public function test_foreman_can_return_custody_and_write_off_with_granular_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $this->registerWarehouseEntitlement($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, [
            'role' => 'foreman',
            'is_active' => true,
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            $context->user,
            'foreman',
            AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id),
        );
        $projectWarehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_PROJECT);
        $custodyWarehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_CUSTODY, [
            'responsible_user_id' => $context->user->id,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Foreman custody material',
            'code' => 'MOB-RETURN-'.$project->id,
            'default_price' => 10,
            'is_active' => true,
        ]);
        foreach ([[$projectWarehouse, 0], [$custodyWarehouse, 5]] as [$warehouse, $quantity]) {
            WarehouseBalance::query()->create([
                'organization_id' => $context->organization->id,
                'warehouse_id' => $warehouse->id,
                'material_id' => $material->id,
                'available_quantity' => $quantity,
                'reserved_quantity' => 0,
                'unit_price' => 10,
            ]);
        }

        $authorization = app(AuthorizationService::class);
        $projectContext = [
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'strict_project_scope' => true,
        ];
        self::assertTrue($authorization->can($context->user, 'warehouse.return_from_responsible', $projectContext));
        self::assertTrue($authorization->can($context->user, 'warehouse.write_offs', $projectContext));
        self::assertTrue($authorization->can($context->user, 'warehouse.receipts', $projectContext));
        self::assertTrue($authorization->can($context->user, 'warehouse.transfers', $projectContext));
        self::assertFalse($authorization->can($context->user, 'warehouse.manage_stock', $projectContext));

        $headers = $context->mobileAuthHeaders();
        $return = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/custody/return', [
            'idempotency_key' => (string) Str::uuid(),
            'custody_warehouse_id' => $custodyWarehouse->id,
            'material_id' => $material->id,
            'quantity' => 2,
        ]);
        $this->assertMobileResponseStatus($return, 200);
        $this->assertWarehouseQuantity($projectWarehouse, $material, 2);
        $this->assertWarehouseQuantity($custodyWarehouse, $material, 3);

        $receipt = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/receipt', [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $projectWarehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'quantity' => 2,
            'price' => 10,
        ]);
        $this->assertMobileResponseStatus($receipt, 200);
        $this->assertWarehouseQuantity($projectWarehouse, $material, 4);

        $destinationWarehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_PROJECT);
        $transfer = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/transfer', [
            'idempotency_key' => (string) Str::uuid(),
            'from_warehouse_id' => $projectWarehouse->id,
            'to_warehouse_id' => $destinationWarehouse->id,
            'material_id' => $material->id,
            'quantity' => 1,
        ]);
        $this->assertMobileResponseStatus($transfer, 200);
        $this->assertWarehouseQuantity($projectWarehouse, $material, 3);
        $this->assertWarehouseQuantity($destinationWarehouse, $material, 1);

        $writeOff = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/write-off', [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $projectWarehouse->id,
            'material_id' => $material->id,
            'quantity' => 1,
            'reason' => 'Damaged on site',
            'operation_category' => WarehouseMovement::CATEGORY_LOSS,
        ]);
        $this->assertMobileResponseStatus($writeOff, 200);
        $this->assertWarehouseQuantity($projectWarehouse, $material, 2);
        $this->assertDatabaseHas('warehouse_movements', [
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            'quantity' => 1,
        ]);
    }

    public function test_project_grant_cannot_mutate_warehouses_outside_its_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $this->registerWarehouseEntitlement($context);
        $assignedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $assignedProject->users()->attach($context->user->id, [
            'role' => 'foreman',
            'is_active' => true,
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            $context->user,
            'foreman',
            AuthorizationContext::getProjectContext((int) $assignedProject->id, (int) $context->organization->id),
        );
        $assignedWarehouse = $this->createWarehouse($context, $assignedProject, OrganizationWarehouse::TYPE_PROJECT);
        $foreignWarehouse = $this->createWarehouse($context, $foreignProject, OrganizationWarehouse::TYPE_PROJECT);
        $centralWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => null,
            'name' => 'Central warehouse',
            'code' => 'MOB-CENTRAL-SCOPE-'.$context->organization->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Foreign project material',
            'code' => 'MOB-FOREIGN-'.$foreignProject->id,
            'default_price' => 10,
            'is_active' => true,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $assignedWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);

        $response = $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/warehouse/operations/receipt',
            [
                'idempotency_key' => (string) Str::uuid(),
                'warehouse_id' => $foreignWarehouse->id,
                'project_id' => $assignedProject->id,
                'material_id' => $material->id,
                'quantity' => 3,
                'price' => 10,
            ]
        );
        $transfer = $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/warehouse/operations/transfer',
            [
                'idempotency_key' => (string) Str::uuid(),
                'from_warehouse_id' => $assignedWarehouse->id,
                'to_warehouse_id' => $foreignWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
            ]
        );
        $centralReceipt = $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/warehouse/operations/receipt',
            [
                'idempotency_key' => (string) Str::uuid(),
                'warehouse_id' => $centralWarehouse->id,
                'project_id' => $assignedProject->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'price' => 10,
            ]
        );

        $this->assertMobileResponseStatus($response, 403);
        $this->assertMobileResponseStatus($transfer, 403);
        $this->assertMobileResponseStatus($centralReceipt, 403);
        $this->assertWarehouseQuantity($assignedWarehouse, $material, 10);
        $this->assertWarehouseQuantity($foreignWarehouse, $material, 0);
        self::assertSame(0, WarehouseMovement::query()
            ->where('organization_id', $context->organization->id)
            ->where('material_id', $material->id)
            ->count());
    }

    public function test_project_worker_without_warehouse_grants_cannot_mutate_mobile_stock(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $this->registerWarehouseEntitlement($context);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $project->users()->attach($context->user->id, [
            'role' => 'worker',
            'is_active' => true,
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            $context->user,
            'worker',
            AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id),
        );
        $projectWarehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_PROJECT);
        $destinationWarehouse = $this->createWarehouse($context, $project, OrganizationWarehouse::TYPE_PROJECT);
        $centralWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => null,
            'name' => 'Central warehouse',
            'code' => 'MOB-CENTRAL-'.$project->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => true,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Worker denied material',
            'code' => 'MOB-DENY-'.$project->id,
            'default_price' => 10,
            'is_active' => true,
        ]);
        WarehouseBalance::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $projectWarehouse->id,
            'material_id' => $material->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'unit_price' => 10,
        ]);
        $delivery = ProjectMaterialDelivery::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'material_id' => $material->id,
            'warehouse_id' => $centralWarehouse->id,
            'source_type' => 'warehouse',
            'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value,
            'requested_quantity' => 4,
            'reserved_quantity' => 4,
            'shipped_quantity' => 4,
            'accepted_quantity' => 0,
            'planned_delivery_date' => now()->toDateString(),
        ]);

        $headers = $context->mobileAuthHeaders();
        $receipt = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/receipt', [
            'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $projectWarehouse->id,
            'material_id' => $material->id,
            'project_id' => $project->id,
            'quantity' => 1,
            'price' => 10,
        ]);
        $transfer = $this->withHeaders($headers)->postJson('/api/v1/mobile/warehouse/operations/transfer', [
            'idempotency_key' => (string) Str::uuid(),
            'from_warehouse_id' => $projectWarehouse->id,
            'to_warehouse_id' => $destinationWarehouse->id,
            'material_id' => $material->id,
            'quantity' => 1,
        ]);
        $receive = $this->withHeaders($headers)->postJson(
            "/api/v1/mobile/warehouse/project-material-deliveries/{$delivery->id}/receive",
            [
                'idempotency_key' => (string) Str::uuid(),
                'quantity' => 1,
            ]
        );

        $this->assertMobileResponseStatus($receipt, 403);
        $this->assertMobileResponseStatus($transfer, 403);
        $this->assertMobileResponseStatus($receive, 403);
        $this->assertWarehouseQuantity($projectWarehouse, $material, 10);
        $this->assertWarehouseQuantity($destinationWarehouse, $material, 0);
        self::assertSame(ProjectMaterialDeliveryStatusEnum::IN_TRANSIT, $delivery->refresh()->status);
        self::assertDatabaseMissing('project_material_delivery_events', [
            'project_material_delivery_id' => $delivery->id,
            'event_type' => 'received',
        ]);
    }

    public function test_foreign_organization_warehouse_cannot_be_used_for_mobile_receipt(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'storekeeper');
        $this->registerWarehouseEntitlement($context);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $foreignOrganization->id,
            'project_id' => $foreignProject->id,
            'name' => 'Foreign warehouse',
            'code' => 'MOB-FOREIGN-WH-'.$foreignProject->id,
            'warehouse_type' => OrganizationWarehouse::TYPE_PROJECT,
            'is_main' => false,
            'is_active' => true,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Org isolation material',
            'code' => 'MOB-ORG-'.$context->organization->id,
            'default_price' => 10,
            'is_active' => true,
        ]);

        $response = $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/warehouse/operations/receipt',
            [
                'idempotency_key' => (string) Str::uuid(),
                'warehouse_id' => $foreignWarehouse->id,
                'material_id' => $material->id,
                'quantity' => 1,
                'price' => 10,
            ]
        );

        $this->assertMobileResponseStatus($response, 403);
        self::assertDatabaseMissing('warehouse_movements', [
            'organization_id' => $foreignOrganization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'material_id' => $material->id,
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

    private function assertWarehouseQuantity(
        OrganizationWarehouse $warehouse,
        Material $material,
        float $expected
    ): void {
        $quantity = WarehouseBalance::query()
            ->where('organization_id', $warehouse->organization_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('material_id', $material->id)
            ->sum('available_quantity');

        self::assertSame(
            $expected,
            (float) $quantity,
            "Unexpected warehouse quantity; warehouse={$warehouse->id}, material={$material->id}, actual={$quantity}"
        );
    }

    private function createWarehouse(
        AdminApiTestContext $context,
        Project $project,
        string $type,
        array $attributes = [],
    ): OrganizationWarehouse {
        return OrganizationWarehouse::query()->create(array_merge([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => $type === OrganizationWarehouse::TYPE_CUSTODY ? 'Custody warehouse' : 'Project warehouse',
            'code' => 'MOB-WH-'.$project->id.'-'.Str::lower(Str::random(5)),
            'warehouse_type' => $type,
            'is_main' => false,
            'is_active' => true,
        ], $attributes));
    }

    private function assertMobileResponseStatus(TestResponse $response, int $expectedStatus): void
    {
        self::assertSame(
            $expectedStatus,
            $response->status(),
            "Unexpected mobile HTTP response: status={$response->status()}, body={$response->getContent()}"
        );
    }

    private function assertResponsePathNotNull(TestResponse $response, string $path): void
    {
        self::assertNotNull(
            data_get($response->json(), $path),
            "Missing response path {$path}; body={$response->getContent()}"
        );
    }
}
