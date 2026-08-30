<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\WriteOffAct\WriteOffActExportStrategy;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseWriteOffActExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_exports_a_complete_write_off_act_for_own_organization(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        [$warehouse, $material] = $this->createWarehouseMaterial($context->organization->id);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 2.5,
            'price' => 125.4,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'document_number' => 'СП-42',
            'reason' => 'Повреждение при разгрузке',
            'operation_category' => WarehouseMovement::CATEGORY_DAMAGE,
            'metadata' => [],
            'movement_date' => '2026-08-30 10:00:00',
        ]);
        WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 9,
            'price' => 125.4,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'document_number' => 'СП-42',
            'reason' => 'Другое основание',
            'operation_category' => WarehouseMovement::CATEGORY_DAMAGE,
            'metadata' => [],
            'movement_date' => '2026-08-30 10:00:00',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/movements/{$movement->id}/export-write-off-act")
            ->assertOk()
            ->assertJsonPath('success', true);

        $url = (string) $response->json('data.url');
        $path = $this->pathFromTemporaryUrl($url);
        Storage::disk('s3')->assertExists($path);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'write_off_act_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));
        $sheet = IOFactory::load($temporaryPath)->getActiveSheet();

        self::assertSame('АКТ № СП-42', $sheet->getCell('A4')->getValue());
        self::assertSame('о списании материальных ценностей', $sheet->getCell('A5')->getValue());
        self::assertStringContainsString($warehouse->name, (string) $sheet->getCell('A8')->getValue());
        self::assertStringContainsString($project->name, (string) $sheet->getCell('A9')->getValue());
        self::assertStringContainsString('Повреждение при разгрузке', (string) $sheet->getCell('A10')->getValue());
        self::assertSame($material->name, $sheet->getCell('B14')->getValue());
        self::assertSame(2.5, (float) $sheet->getCell('D14')->getValue());
        self::assertSame(313.5, (float) $sheet->getCell('F14')->getValue());
        self::assertSame('Итого', $sheet->getCell('A15')->getValue());
        self::assertStringContainsString('Порча', (string) $sheet->getCell('G14')->getValue());
        self::assertStringContainsString($context->user->name, (string) $sheet->getCell('A24')->getValue());

        @unlink($temporaryPath);
    }

    public function test_act_without_business_number_does_not_expose_the_internal_movement_id(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        [$warehouse, $material] = $this->createWarehouseMaterial($context->organization->id);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 1,
            'price' => 10,
            'user_id' => $context->user->id,
            'reason' => 'Утрата',
            'operation_category' => WarehouseMovement::CATEGORY_LOSS,
            'metadata' => [],
            'movement_date' => '2026-08-30 11:00:00',
        ]);
        $movement->load(['organization', 'warehouse', 'project', 'user', 'material.measurementUnit']);

        $path = app(WriteOffActExportStrategy::class)->export($movement);
        self::assertStringContainsString('akt-spisaniya_20260830_110000.xlsx', $path);
        self::assertStringNotContainsString('write_off_act_'.$movement->id, $path);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'write_off_act_').'.xlsx';
        file_put_contents($temporaryPath, Storage::disk('s3')->get($path));
        $sheet = IOFactory::load($temporaryPath)->getActiveSheet();

        self::assertSame('АКТ № б/н', $sheet->getCell('A4')->getValue());
        self::assertStringNotContainsString((string) $movement->id, (string) $sheet->getCell('A4')->getValue());

        @unlink($temporaryPath);
    }

    public function test_write_off_act_rejects_incompatible_and_foreign_movements(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        [$warehouse, $material] = $this->createWarehouseMaterial($context->organization->id);
        $receipt = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $warehouse->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_RECEIPT,
            'quantity' => 1,
            'price' => 1,
            'metadata' => [],
            'movement_date' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/movements/{$receipt->id}/export-write-off-act")
            ->assertStatus(422);

        $foreignContext = AdminApiTestContext::create();
        [$foreignWarehouse, $foreignMaterial] = $this->createWarehouseMaterial($foreignContext->organization->id);
        $foreignMovement = WarehouseMovement::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'warehouse_id' => $foreignWarehouse->id,
            'material_id' => $foreignMaterial->id,
            'movement_type' => WarehouseMovement::TYPE_WRITE_OFF,
            'quantity' => 1,
            'price' => 1,
            'reason' => 'Утилизация',
            'operation_category' => WarehouseMovement::CATEGORY_DISPOSAL,
            'metadata' => [],
            'movement_date' => now(),
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/movements/{$foreignMovement->id}/export-write-off-act")
            ->assertNotFound();
    }

    private function createWarehouseMaterial(int $organizationId): array
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Килограмм',
            'short_name' => 'кг-'.bin2hex(random_bytes(3)),
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Сухая строительная смесь',
            'code' => 'MAT-'.bin2hex(random_bytes(3)),
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $warehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Основной склад',
            'code' => 'WH-'.bin2hex(random_bytes(3)),
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);

        return [$warehouse, $material];
    }

    private function pathFromTemporaryUrl(string $url): string
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return rawurldecode($path);
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
