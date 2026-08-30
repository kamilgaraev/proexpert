<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseMovementDocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_movement_forms_reject_incompatible_and_foreign_operations(): void
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        [$warehouse, $material] = $this->warehouseMaterial($context->organization->id);

        $receipt = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_RECEIPT,
        );
        $ordinaryWriteOff = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_WRITE_OFF,
            WarehouseMovement::CATEGORY_DAMAGE,
        );
        $internalTransfer = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_TRANSFER_OUT,
        );

        foreach ([
            "/api/v1/admin/warehouses/movements/{$ordinaryWriteOff->id}/export-m4",
            "/api/v1/admin/warehouses/movements/{$ordinaryWriteOff->id}/export-m7",
            "/api/v1/admin/warehouses/movements/{$ordinaryWriteOff->id}/export-m11",
            "/api/v1/admin/warehouses/movements/{$receipt->id}/export-m15",
            "/api/v1/admin/warehouses/movements/{$internalTransfer->id}/export-m15",
        ] as $uri) {
            $this->withHeaders($context->authHeaders())
                ->getJson($uri)
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', trans_message('warehouse_basic.document_form_unavailable'));
        }

        $foreign = AdminApiTestContext::create();
        [$foreignWarehouse, $foreignMaterial] = $this->warehouseMaterial($foreign->organization->id);
        $foreignReceipt = $this->movement(
            $foreign->organization->id,
            $foreignWarehouse,
            $foreignMaterial,
            WarehouseMovement::TYPE_RECEIPT,
        );

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/movements/{$foreignReceipt->id}/export-m4")
            ->assertNotFound()
            ->assertJsonPath('message', trans_message('warehouse_basic.movement_not_found'));
    }

    public function test_movement_forms_accept_only_their_business_operations(): void
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        [$warehouse, $material] = $this->warehouseMaterial($context->organization->id);

        $receipt = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_RECEIPT,
        );
        $transfer = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_TRANSFER_OUT,
        );
        $contractorTransfer = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_TRANSFER_OUT,
            null,
            ['is_contractor_transfer' => true, 'contractor_name' => 'ООО Монтаж'],
        );
        $productionIssue = $this->movement(
            $context->organization->id,
            $warehouse,
            $material,
            WarehouseMovement::TYPE_WRITE_OFF,
            WarehouseMovement::CATEGORY_PRODUCTION_USAGE,
        );

        foreach ([
            "/api/v1/admin/warehouses/movements/{$receipt->id}/export-m4",
            "/api/v1/admin/warehouses/movements/{$receipt->id}/export-m7",
            "/api/v1/admin/warehouses/movements/{$transfer->id}/export-m11",
            "/api/v1/admin/warehouses/movements/{$productionIssue->id}/export-m11",
            "/api/v1/admin/warehouses/movements/{$contractorTransfer->id}/export-m15",
        ] as $uri) {
            $this->withHeaders($context->authHeaders())
                ->getJson($uri)
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $m15Response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/movements/{$contractorTransfer->id}/export-m15")
            ->assertOk();
        $path = ltrim((string) parse_url((string) $m15Response->json('data.url'), PHP_URL_PATH), '/');
        $temporaryPath = tempnam(sys_get_temp_dir(), 'm15_policy_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get(rawurldecode($path)));
        $sheet = IOFactory::load($temporaryPath)->getActiveSheet();

        self::assertSame('Кому: ООО Монтаж', $sheet->getCell('A15')->getValue());
        @unlink($temporaryPath);
    }

    public function test_export_routes_require_the_corresponding_warehouse_permissions(): void
    {
        $expected = [
            'admin.warehouses.movements.export-m4' => 'authorize:warehouse.receipts',
            'admin.warehouses.movements.export-m7' => 'authorize:warehouse.receipts',
            'admin.warehouses.movements.export-m11' => 'authorize:warehouse.manage_stock',
            'admin.warehouses.movements.export-m15' => 'authorize:warehouse.transfers',
            'admin.warehouses.materials.export-m17' => 'authorize:warehouse.reports',
            'admin.advanced-warehouse.reservations.export-m8' => 'authorize:warehouse.advanced.reservations',
        ];

        foreach ($expected as $routeName => $middleware) {
            $route = app('router')->getRoutes()->getByName($routeName);

            self::assertInstanceOf(Route::class, $route, $routeName);
            self::assertContains($middleware, $route->gatherMiddleware(), $routeName);
        }
    }

    private function movement(
        int $organizationId,
        OrganizationWarehouse $warehouse,
        Material $material,
        string $type,
        ?string $category = null,
        array $metadata = [],
    ): WarehouseMovement {
        return WarehouseMovement::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => $type,
            'quantity' => 1,
            'price' => 100,
            'document_number' => 'ДОК-'.bin2hex(random_bytes(3)),
            'reason' => 'Проверка складского документа',
            'operation_category' => $category,
            'metadata' => $metadata,
            'movement_date' => '2026-08-30 12:00:00',
        ]);
    }

    private function warehouseMaterial(int $organizationId): array
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Штука',
            'short_name' => 'шт-'.bin2hex(random_bytes(3)),
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Крепёж',
            'code' => 'КРЕП-'.bin2hex(random_bytes(3)),
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Основной склад',
            'code' => 'СКЛ-'.bin2hex(random_bytes(3)),
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);

        return [$warehouse, $material];
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
