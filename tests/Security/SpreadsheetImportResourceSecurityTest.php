<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Http\Controllers\Api\V1\Admin\EstimatePositionImportExportController;
use App\Http\Requests\Api\V1\Admin\EstimatePosition\ImportPositionsRequest;
use App\Models\EstimatePositionCatalog;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\EstimatePositionCatalog\ImportExportService;
use App\Services\Import\SpreadsheetImportException;
use App\Services\Material\MaterialService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class SpreadsheetImportResourceSecurityTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_both_service_entrypoints_reject_sparse_workbook_without_writing_rows(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A10001', 'untrusted');
        $file = $this->upload($book);
        $catalogCount = EstimatePositionCatalog::query()->count();
        $materialCount = Material::query()->count();
        try {
            (new ImportExportService)->importFromExcel($organization->id, $file, $user->id);
            self::fail('Catalog import must reject the oversized worksheet.');
        } catch (SpreadsheetImportException $exception) {
            self::assertSame('spreadsheet_import_rejected', $exception->getMessage());
        }
        $result = $this->app->make(MaterialService::class)->importMaterialsFromFile($file, options: ['organization_id' => $organization->id]);
        self::assertFalse($result['success']);
        self::assertSame(422, $result['code']);
        self::assertSame(trans_message('catalog.import.file_rejected'), $result['message']);
        self::assertSame([$result['message']], $result['errors']);
        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['updated']);
        self::assertSame($catalogCount, EstimatePositionCatalog::query()->count());
        self::assertSame($materialCount, Material::query()->count());

        $request = ImportPositionsRequest::create('/import', 'POST', files: ['file' => $file]);
        $request->setUserResolver(static fn (): User => $user);
        $response = $this->app->make(EstimatePositionImportExportController::class)->import($request);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(trans_message('catalog.import.file_rejected'), json_encode($response->getData(), JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString($file->getPathname(), $response->getContent());
    }

    public function test_catalog_can_import_its_twelve_column_export_and_update_existing_position(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $unit = $this->unit($organization);
        $position = EstimatePositionCatalog::query()->create([
            'organization_id' => $organization->id, 'created_by_user_id' => $user->id,
            'measurement_unit_id' => $unit->id, 'code' => '00123', 'name' => 'Материал',
            'description' => 'Описание', 'item_type' => 'material', 'unit_price' => 12.5,
        ]);
        $service = new ImportExportService;
        $path = $service->exportToExcel($organization->id);
        $this->paths[] = $path;
        $result = $service->importFromExcel($organization->id, new UploadedFile($path, 'catalog.xlsx', test: true), $user->id);
        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $result);
        self::assertSame(1, EstimatePositionCatalog::query()->where('organization_id', $organization->id)->count());
        self::assertSame('00123', $position->fresh()->code);
        self::assertEquals(12.5, $position->fresh()->unit_price);
    }

    public function test_material_import_keeps_calculated_values_dry_run_and_upsert(): void
    {
        $organization = Organization::factory()->create();
        $unit = $this->unit($organization);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['name', 'measurement_unit', 'default_price', 'external_code'],
            ['Сталь', $unit->name, '=2+3', 'import-resource-1'],
        ]);
        $file = $this->upload($book);
        $service = $this->app->make(MaterialService::class);
        $options = ['organization_id' => $organization->id];
        $result = $service->importMaterialsFromFile($file, options: $options + ['dry_run' => true]);
        self::assertTrue($result['success']);
        self::assertSame(0, Material::query()->where('organization_id', $organization->id)->count());
        $result = $service->importMaterialsFromFile($file, options: $options);
        self::assertTrue($result['success']);
        self::assertSame(1, $result['imported']);
        self::assertEquals(5, Material::query()->where('organization_id', $organization->id)->firstOrFail()->default_price);
        $result = $service->importMaterialsFromFile($file, options: $options);
        self::assertTrue($result['success']);
        self::assertSame(1, $result['updated']);
        self::assertSame(1, Material::query()->where('organization_id', $organization->id)->count());
    }

    private function unit(Organization $organization): MeasurementUnit
    {
        $suffix = uniqid();

        return MeasurementUnit::query()->create([
            'organization_id' => $organization->id, 'name' => 'Штука '.$suffix,
            'short_name' => 'imp-'.$suffix, 'type' => 'material',
        ]);
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'most-import-security-');
        $this->paths[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'import.xlsx', test: true);
    }
}
