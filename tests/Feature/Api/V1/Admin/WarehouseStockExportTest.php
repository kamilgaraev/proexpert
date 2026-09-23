<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseStockExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_excel_and_pdf_exports_include_all_batches_and_exclude_other_positions(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $files = [];
        $this->mock(FileService::class, function (MockInterface $mock) use (&$files): void {
            $mock->shouldReceive('putPrivate')->andReturnUsing(
                static function (string $key, string $content, string $mime, string $sha256) use (&$files): CurrentStoredFile {
                    $files[$key] = $content;

                    return new CurrentStoredFile($key, 'etag', strlen($content), $sha256, $mime);
                }
            );
            $mock->shouldReceive('temporaryDownloadUrl')->andReturnUsing(
                static fn (string $key, int $ttl): string => 'https://files.example.test/'.$key
            );
        });

        $warehouse = $this->warehouse($context->organization->id, 'Основной склад');
        $material = $this->material($context->organization->id, 'Крепёж');
        $otherMaterial = $this->material($context->organization->id, 'Краска');
        $this->balance($context->organization->id, $warehouse->id, $material->id, 2, 0, 10, 6);
        $this->balance($context->organization->id, $warehouse->id, $material->id, 3, 1, 20, 6);
        $this->balance($context->organization->id, $warehouse->id, $otherMaterial->id, 9, 0, 30, 2);

        $url = "/api/v1/admin/warehouses/{$warehouse->id}/balances/export";
        $excelResponse = $this->withHeaders($context->authHeaders())
            ->getJson("{$url}/xlsx?low_stock=true")
            ->assertOk()
            ->assertJsonPath('success', true);
        self::assertStringEndsWith('.xlsx', (string) $excelResponse->json('data.filename'));
        $excel = end($files);
        self::assertStringStartsWith('PK', $excel);
        $temporary = tempnam(sys_get_temp_dir(), 'stock_export_');
        try {
            file_put_contents($temporary, $excel);
            $sheet = IOFactory::load($temporary)->getActiveSheet();
            self::assertSame('Крепёж', $sheet->getCell('A5')->getValue());
            self::assertSame(5, (int) $sheet->getCell('D5')->getValue());
            self::assertSame(1, (int) $sheet->getCell('E5')->getValue());
            self::assertSame(100, (int) $sheet->getCell('H5')->getValue());
            self::assertNull($sheet->getCell('A6')->getValue());
        } finally {
            @unlink($temporary);
        }

        $pdfResponse = $this->withHeaders($context->authHeaders())
            ->getJson("{$url}/pdf?search=Креп")
            ->assertOk()
            ->assertJsonPath('success', true);
        self::assertStringEndsWith('.pdf', (string) $pdfResponse->json('data.filename'));
        self::assertStringStartsWith('%PDF', end($files));

        $foreignContext = AdminApiTestContext::create();
        $foreignWarehouse = $this->warehouse($foreignContext->organization->id, 'Чужой склад');
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/warehouses/{$foreignWarehouse->id}/balances/export/xlsx")
            ->assertNotFound();
    }

    public function test_export_route_requires_report_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.warehouses.balances.export');

        self::assertInstanceOf(Route::class, $route);
        self::assertContains('authorize:warehouse.reports', $route->gatherMiddleware());
    }

    private function warehouse(int $organizationId, string $name): OrganizationWarehouse
    {
        return OrganizationWarehouse::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => 'СКЛ-'.bin2hex(random_bytes(3)),
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);
    }

    private function material(int $organizationId, string $name): Material
    {
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Штука',
            'short_name' => 'шт-'.bin2hex(random_bytes(3)),
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);

        return Material::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => 'МАТ-'.bin2hex(random_bytes(3)),
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => 'material'],
            'is_active' => true,
        ]);
    }

    private function balance(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        int $available,
        int $reserved,
        int $price,
        int $minimum,
    ): void {
        WarehouseBalance::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'available_quantity' => $available,
            'reserved_quantity' => $reserved,
            'unit_price' => $price,
            'min_stock_level' => $minimum,
        ]);
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturn(collect());
        });
    }
}
