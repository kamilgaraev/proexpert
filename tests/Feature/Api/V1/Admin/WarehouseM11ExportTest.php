<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M11\M11ExportStrategy;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class WarehouseM11ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_m11_export_contains_business_data_and_is_ready_for_a4_printing(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create(
            userAttributes: [
                'name' => 'owner_technical_123',
                'email' => 'owner-technical@example.test',
            ],
            organizationAttributes: [
                'name' => 'Тестовая организация',
                'legal_name' => 'ООО «Тестовая организация»',
                'okpo' => '12345678',
            ],
        );
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Килограмм',
            'short_name' => 'кг-м11',
            'type' => 'material',
            'is_default' => false,
            'is_system' => false,
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Арматура А500С, диаметр 12 мм',
            'code' => 'M11-MATERIAL-001',
            'measurement_unit_id' => $unit->id,
            'additional_properties' => ['asset_type' => Asset::TYPE_MATERIAL],
            'is_active' => true,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Тестовый объект',
        ]);
        $sourceWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'responsible_user_id' => $context->user->id,
            'name' => 'Ответственное хранение: Тестовый объект, owner_technical_123',
            'code' => 'M11-SOURCE',
            'warehouse_type' => OrganizationWarehouse::TYPE_CUSTODY,
            'is_main' => false,
            'is_active' => true,
        ]);
        $recipientWarehouse = OrganizationWarehouse::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Ответственное хранение',
            'code' => 'M11-RECIPIENT',
            'warehouse_type' => OrganizationWarehouse::TYPE_CENTRAL,
            'is_main' => false,
            'is_active' => true,
        ]);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $context->organization->id,
            'warehouse_id' => $sourceWarehouse->id,
            'to_warehouse_id' => $recipientWarehouse->id,
            'user_id' => $context->user->id,
            'related_user_id' => $context->user->id,
            'material_id' => $material->id,
            'movement_type' => WarehouseMovement::TYPE_TRANSFER_OUT,
            'operation_category' => WarehouseMovement::CATEGORY_RESPONSIBLE_RETURN,
            'quantity' => 2.5,
            'price' => 112,
            'document_number' => 'QA-M11-RETURN-POSTRELEASE-20260902-001',
            'reason' => 'Возврат от ответственного лица',
            'metadata' => [],
            'movement_date' => '2026-09-02 10:15:00',
        ]);

        $path = app(M11ExportStrategy::class)->export($movement);

        Storage::disk('s3')->assertExists($path);
        $this->assertSame(
            "org-{$context->organization->id}/exports/warehouse/m11/M11_qa-m11-return-postrelease-20260902-001.xlsx",
            $path,
        );

        $temporaryPath = tempnam(sys_get_temp_dir(), 'm11_');
        $this->assertNotFalse($temporaryPath);

        try {
            file_put_contents($temporaryPath, Storage::disk('s3')->get($path));
            $spreadsheet = IOFactory::load($temporaryPath);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Унифицированная форма № М-11', $sheet->getCell('J1')->getValue());
            $this->assertSame('ООО «Тестовая организация»', $sheet->getCell('A5')->getValue());
            $this->assertSame('12345678', (string) $sheet->getCell('I7')->getValue());
            $this->assertSame('QA-M11-RETURN-POSTRELEASE-20260902-001', $sheet->getCell('H11')->getValue());
            $this->assertSame('02.09.2026', $sheet->getCell('I11')->getValue());
            $this->assertSame(
                'Отправитель: Ответственное хранение: Тестовый объект, Владелец организации',
                $sheet->getCell('A13')->getValue(),
            );
            $this->assertStringNotContainsString(
                'owner_technical_123',
                (string) $sheet->getCell('A13')->getValue(),
            );
            $this->assertSame('Получатель: Ответственное хранение', $sheet->getCell('A14')->getValue());
            $this->assertSame('Арматура А500С, диаметр 12 мм', $sheet->getCell('A17')->getValue());
            $this->assertSame('Килограмм', $sheet->getCell('E17')->getValue());
            $this->assertSame(2.5, (float) $sheet->getCell('F17')->getValue());
            $this->assertSame('112,00', $sheet->getCell('H17')->getValue());
            $this->assertSame('280,00', $sheet->getCell('I17')->getValue());

            $personLine = (string) $sheet->getCell('A19')->getValue();
            $this->assertStringContainsString('Владелец организации', $personLine);
            $this->assertStringNotContainsString('owner_technical_123', $personLine);
            $this->assertStringNotContainsString('owner-technical@example.test', $personLine);

            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
            $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
            $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            $this->assertSame('A1:L20', $sheet->getPageSetup()->getPrintArea());
            $this->assertTrue($sheet->getStyle('H11')->getAlignment()->getShrinkToFit());
        } finally {
            @unlink($temporaryPath);
        }
    }
}
