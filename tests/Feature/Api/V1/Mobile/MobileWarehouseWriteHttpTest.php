<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\BasicWarehouseModule;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\Material;
use App\Models\Module;
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
