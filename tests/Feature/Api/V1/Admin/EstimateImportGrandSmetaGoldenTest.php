<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class EstimateImportGrandSmetaGoldenTest extends TestCase
{
    use RefreshDatabase;

    public function test_grand_smeta_detection_and_preview_stay_stable(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $filePath = $this->createGrandSmetaSpreadsheet();

        $uploadedFile = new UploadedFile(
            $filePath,
            'grand-smeta-golden.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $service = app(EstimateImportService::class);
        $sessionId = $service->uploadFile($uploadedFile, $user->id, $organization->id);

        $type = $service->detectEstimateType($sessionId);
        $session = ImportSession::query()->findOrFail($sessionId);

        self::assertSame('grandsmeta', $type->detectedType);
        self::assertGreaterThanOrEqual(0.9, $type->confidence);
        self::assertSame('grandsmeta', $session->options['format_handler'] ?? null);

        $format = $service->detectFormat($sessionId);
        $session->refresh();

        self::assertSame('grandsmeta', $format['format']);
        self::assertSame('grandsmeta', $session->options['format_handler'] ?? null);
        self::assertSame(3, $session->options['structure']['header_row'] ?? null);
        self::assertNotEmpty($session->options['structure']['column_mapping'] ?? []);

        $preview = $service->preview($sessionId);

        self::assertSame('grandsmeta', $preview->metadata['handler'] ?? null);
        self::assertNotEmpty($preview->sections);
        self::assertNotEmpty($preview->items);
        self::assertGreaterThanOrEqual(200.0, $preview->getTotalAmount());
    }

    private function createGrandSmetaSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'ГРАНД-Смета');
        $sheet->setCellValue('A3', '1');
        $sheet->setCellValue('B3', '2');
        $sheet->setCellValue('C3', '3');
        $sheet->setCellValue('D3', '4');
        $sheet->setCellValue('G3', '7');
        $sheet->setCellValue('J3', '10');
        $sheet->setCellValue('L3', '12');

        $sheet->setCellValue('A4', 'Раздел 1. Монтажные работы');
        $sheet->setCellValue('A5', '1');
        $sheet->setCellValue('B5', 'ФЕР01-01-001-01');
        $sheet->setCellValue('C5', 'Монтаж оборудования');
        $sheet->setCellValue('D5', 'шт');
        $sheet->setCellValue('G5', 2);
        $sheet->setCellValue('J5', 100);
        $sheet->setCellValue('L5', 200);

        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'grand-smeta-golden-' . Str::uuid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        return $filePath;
    }
}
