<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class CustomExcelWorksheetDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_excel_detection_uses_estimate_worksheet_when_active_sheet_is_title(): void
    {
        $filePath = $this->createWorkbookWithTitleSheet();

        try {
            Storage::fake('s3');

            $organization = Organization::factory()->create();
            $user = User::factory()->create();
            $storedPath = "org-{$organization->id}/estimate-imports/" . basename($filePath);

            Storage::disk('s3')->put($storedPath, (string) file_get_contents($filePath));

            $session = ImportSession::create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'status' => 'uploading',
                'file_path' => $storedPath,
                'file_name' => 'estimate.xlsx',
                'file_size' => filesize($filePath),
                'file_format' => 'xlsx',
                'options' => [],
                'stats' => ['progress' => 0],
            ]);

            /** @var EstimateImportService $service */
            $service = app(EstimateImportService::class);

            $detection = $service->detectEstimateType($session->id);

            $this->assertSame('custom', $detection->detectedType);
            $this->assertSame('custom_excel', $detection->metadata['format_slug'] ?? null);
            $this->assertSame('Предчистовая отделка', $detection->metadata['worksheet_title'] ?? null);
            $this->assertSame([1, 2], $detection->metadata['worksheet_indices'] ?? null);
            $this->assertGreaterThan(0.5, $detection->confidence);

            $structure = $service->detectFormat($session->id);

            $this->assertSame(5, $structure['header_row']);
            $this->assertSame('Предчистовая отделка', $structure['metadata']['worksheet_title'] ?? null);
            $this->assertCount(2, $structure['metadata']['worksheets'] ?? []);
            $this->assertSame('A', $structure['column_mapping']['position_number'] ?? null);
            $this->assertSame('C', $structure['column_mapping']['name'] ?? null);
            $this->assertSame('D', $structure['column_mapping']['unit'] ?? null);
            $this->assertSame('E', $structure['column_mapping']['quantity'] ?? null);
            $this->assertSame('F', $structure['column_mapping']['unit_price'] ?? null);
            $this->assertSame('G', $structure['column_mapping']['total_price'] ?? null);

            $preview = $service->preview($session->id);

            $itemNames = array_column($preview->items, 'item_name');
            $sectionNames = array_column($preview->sections, 'item_name');

            $this->assertContains('Грунтовка потолка', $itemNames);
            $this->assertContains('Покраска стен', $itemNames);
            $this->assertContains('Потолки', $sectionNames);
            $this->assertContains('Стены', $sectionNames);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    private function createWorkbookWithTitleSheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $titleSheet = $spreadsheet->getActiveSheet();
        $titleSheet->setTitle('Титульный');
        $titleSheet->setCellValue('A1', 'СМЕТА');
        $titleSheet->setCellValue('A3', 'на работы по отделке квартиры');
        $titleSheet->setCellValue('A8', 'Стоимость предчистовой отделки');
        $titleSheet->setCellValue('B8', '=\'Предчистовая отделка\'!G6');

        $estimateSheet = $spreadsheet->createSheet();
        $estimateSheet->setTitle('Предчистовая отделка');
        $estimateSheet->setCellValue('A1', 'Предчистовая отделка');
        $estimateSheet->setCellValue('A3', '1. Потолки');
        $estimateSheet->fromArray([
            'номер',
            'тип',
            'позиция',
            'ед. изм.',
            'кол-во',
            'цена, руб.',
            'стоимость, руб.',
        ], null, 'A5');
        $estimateSheet->fromArray([
            1,
            'работы',
            'Грунтовка потолка',
            'м2',
            10,
            200,
            2000,
        ], null, 'A6');

        $cleanFinishSheet = $spreadsheet->createSheet();
        $cleanFinishSheet->setTitle('Чистовая отделка');
        $cleanFinishSheet->setCellValue('A1', 'Чистовая отделка');
        $cleanFinishSheet->setCellValue('A3', '1. Стены');
        $cleanFinishSheet->fromArray([
            'номер',
            'тип',
            'позиция',
            'ед. изм.',
            'кол-во',
            'цена, руб.',
            'стоимость, руб.',
        ], null, 'A5');
        $cleanFinishSheet->fromArray([
            1,
            'работы',
            'Покраска стен',
            'м2',
            25,
            300,
            7500,
        ], null, 'A6');

        $spreadsheet->setActiveSheetIndex(0);

        $directory = storage_path('app/temp');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filePath = $directory . '/custom_excel_title_sheet_' . uniqid('', true) . '.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        return $filePath;
    }
}
