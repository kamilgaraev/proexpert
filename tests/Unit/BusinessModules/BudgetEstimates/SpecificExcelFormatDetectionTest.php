<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Fer\FerHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Rik\RikHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\SmartSmeta\SmartSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\ImportSession;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class SpecificExcelFormatDetectionTest extends TestCase
{
    public function test_specific_excel_handlers_win_over_custom_excel(): void
    {
        $cases = [
            'rik' => ['РИК', 'rik'],
            'smartsmeta' => ['SmartSmeta', 'smartsmeta'],
            'fer' => ['ФЕР', 'fer'],
        ];

        foreach ($cases as [$marker, $expectedSlug]) {
            $filePath = $this->createWorkbookWithMarkerAndTable($marker);

            try {
                $detection = $this->detector()->detect(new ImportSession(), $filePath);

                self::assertNotNull($detection);
                self::assertSame($expectedSlug, $detection->formatSlug);
                self::assertGreaterThan(0.85, $detection->confidence);
            } finally {
                @unlink($filePath);
            }
        }
    }

    private function detector(): ImportFormatDetector
    {
        $reader = new SpreadsheetTableReader();
        $headerDetector = new SpreadsheetHeaderDetector();

        return new ImportFormatDetector(new ImportFormatRegistry([
            new RikHandler($reader, $headerDetector),
            new FerHandler($reader, $headerDetector),
            new SmartSmetaHandler($reader, $headerDetector),
            new CustomExcelHandler($reader, $headerDetector),
        ]));
    }

    private function createWorkbookWithMarkerAndTable(string $marker): string
    {
        $spreadsheet = new Spreadsheet();
        $cover = $spreadsheet->getActiveSheet();
        $cover->setTitle('Cover');
        $cover->setCellValue('A1', 'Estimate export');

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Works');
        $sheet->setCellValue('A1', $marker);
        $sheet->fromArray([
            'номер',
            'позиция',
            'ед. изм.',
            'кол-во',
            'цена, руб.',
            'стоимость, руб.',
        ], null, 'A3');
        $sheet->fromArray([
            1,
            'Монтаж оборудования',
            'шт',
            2,
            100,
            200,
        ], null, 'A4');

        $spreadsheet->setActiveSheetIndex(0);

        $filePath = tempnam(sys_get_temp_dir(), 'specific-excel-format-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($filePath);
        $spreadsheet->disconnectWorksheets();

        return $filePath;
    }
}
