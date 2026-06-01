<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_custom_excel_keeps_worksheet_sections_and_title_extra_costs(): void
    {
        $filePath = $this->createWorkbookWithTitleTotalsAndExtraCosts();

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

            $service->detectEstimateType($session->id);
            $service->detectFormat($session->id);

            $preview = $service->preview($session->id);

            self::assertSame(10800.0, $preview->getTotalAmount());
            self::assertSame(5, $preview->getItemsCount());

            $itemNames = array_column($preview->items, 'item_name');
            self::assertContains('Delivery', $itemNames);
            self::assertContains('Cleaning', $itemNames);

            $sectionPaths = array_column($preview->sections, 'section_path');
            self::assertContains('Rough finish/1', $sectionPaths);
            self::assertContains('Rough finish/2', $sectionPaths);
            self::assertContains('Clean finish/1', $sectionPaths);

            $itemsByName = [];
            foreach ($preview->items as $item) {
                $itemsByName[$item['item_name']] = $item;
            }

            $additionalCostsSection = trans_message('estimate.import_additional_costs_section');

            self::assertSame('Rough finish/1', $itemsByName['Ceiling primer']['section_path'] ?? null);
            self::assertSame('Rough finish/2', $itemsByName['Floor screed']['section_path'] ?? null);
            self::assertSame('Clean finish/1', $itemsByName['Stretch ceiling']['section_path'] ?? null);
            self::assertSame($additionalCostsSection, $itemsByName['Delivery']['section_path'] ?? null);
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    public function test_custom_excel_import_keeps_sheet_titles_as_parents_and_extra_costs_standalone(): void
    {
        $filePath = $this->createWorkbookWithTitleTotalsAndExtraCosts();

        try {
            Queue::fake();
            Storage::fake('s3');

            $organization = Organization::factory()->create();
            $user = User::factory()->create();
            $project = Project::factory()->create([
                'organization_id' => $organization->id,
            ]);
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
            $service->detectEstimateType($session->id);
            $service->detectFormat($session->id);

            $session = $session->fresh();
            $options = $session->options ?? [];
            $options['estimate_settings'] = [
                'name' => 'Imported custom estimate',
                'type' => 'local',
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'financial_mode' => 'plain',
            ];
            $options['validate_only'] = false;
            $session->update([
                'options' => $options,
            ]);

            app(ImportPipelineService::class)->run($session->fresh());

            $estimateId = (int) ($session->fresh()->stats['estimate_id'] ?? 0);
            self::assertGreaterThan(0, $estimateId);

            /** @var Estimate $estimate */
            $estimate = Estimate::query()->findOrFail($estimateId);

            self::assertSame(10800.0, (float) $estimate->total_amount);

            $roughRoot = EstimateSection::query()
                ->where('estimate_id', $estimateId)
                ->where('name', 'Rough finish')
                ->whereNull('parent_section_id')
                ->firstOrFail();
            $cleanRoot = EstimateSection::query()
                ->where('estimate_id', $estimateId)
                ->where('name', 'Clean finish')
                ->whereNull('parent_section_id')
                ->firstOrFail();

            self::assertSame(3000.0, (float) $roughRoot->fresh()->section_total_amount);
            self::assertSame(7000.0, (float) $cleanRoot->fresh()->section_total_amount);
            self::assertSame(2, EstimateSection::query()->where('parent_section_id', $roughRoot->id)->count());
            self::assertSame(1, EstimateSection::query()->where('parent_section_id', $cleanRoot->id)->count());
            self::assertSame(0, EstimateItem::query()->where('estimate_section_id', $roughRoot->id)->count());

            $extraRows = EstimateItem::query()
                ->where('estimate_id', $estimateId)
                ->whereIn('name', ['Delivery', 'Cleaning'])
                ->get()
                ->keyBy('name');

            self::assertSame(500.0, (float) $extraRows->get('Delivery')->total_amount);
            self::assertSame(300.0, (float) $extraRows->get('Cleaning')->total_amount);
            self::assertNull($extraRows->get('Delivery')->parent_work_id);
            self::assertNull($extraRows->get('Cleaning')->parent_work_id);
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

    private function createWorkbookWithTitleTotalsAndExtraCosts(): string
    {
        $spreadsheet = new Spreadsheet();
        $titleSheet = $spreadsheet->getActiveSheet();
        $titleSheet->setTitle('Title');
        $titleSheet->setCellValue('A1', 'Estimate');
        $titleSheet->setCellValue('A7', 'Cost rough finish');
        $titleSheet->setCellValue('C7', 3000);
        $titleSheet->setCellValue('A10', 'Cost clean finish');
        $titleSheet->setCellValue('C10', 7000);
        $titleSheet->setCellValue('A13', 'Delivery');
        $titleSheet->setCellValue('C13', 500);
        $titleSheet->setCellValue('A14', 'Cleaning');
        $titleSheet->setCellValue('C14', 300);
        $titleSheet->setCellValue('A16', 'Total');
        $titleSheet->setCellValue('C16', 10800);

        $roughSheet = $spreadsheet->createSheet();
        $roughSheet->setTitle('Rough finish');
        $roughSheet->setCellValue('A1', 'Rough finish');
        $roughSheet->setCellValue('A3', '1. Ceilings');
        $roughSheet->fromArray([
            'number',
            'type',
            'description',
            'unit',
            'qty',
            'price',
            'total',
        ], null, 'A5');
        $roughSheet->fromArray([1, 'work', 'Ceiling primer', 'm2', 10, 100, 1000], null, 'A6');
        $roughSheet->setCellValue('A8', '2. Floors');
        $roughSheet->fromArray([2, 'work', 'Floor screed', 'm2', 10, 200, 2000], null, 'A9');

        $cleanSheet = $spreadsheet->createSheet();
        $cleanSheet->setTitle('Clean finish');
        $cleanSheet->setCellValue('A1', 'Clean finish');
        $cleanSheet->setCellValue('A3', '1. Ceilings');
        $cleanSheet->fromArray([
            'number',
            'type',
            'description',
            'unit',
            'qty',
            'price',
            'total',
        ], null, 'A5');
        $cleanSheet->fromArray([1, 'work', 'Stretch ceiling', 'm2', 10, 700, 7000], null, 'A6');

        $spreadsheet->setActiveSheetIndex(0);

        $directory = storage_path('app/temp');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $filePath = $directory . '/custom_excel_title_extra_costs_' . uniqid('', true) . '.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        return $filePath;
    }
}
