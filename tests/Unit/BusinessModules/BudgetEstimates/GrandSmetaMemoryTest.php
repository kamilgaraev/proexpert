<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaSpreadsheetLoader;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\StatefulGrandSmetaProcessor;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GrandSmetaMemoryTest extends TestCase
{
    public function test_formatted_empty_grid_is_not_imported_and_formulas_keep_their_totals(): void
    {
        Log::swap(new NullLogger);
        $path = tempnam(sys_get_temp_dir(), 'grand-memory-');
        self::assertIsString($path);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'ГРАНД-Смета');
        foreach (['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'G' => 7, 'J' => 10, 'L' => 12] as $column => $value) {
            $sheet->setCellValue($column.'3', $value);
        }
        $sheet->setCellValue('A4', 'Раздел 1. Работы');
        foreach (['A' => 1, 'B' => 'ФЕР01-01-001-01', 'C' => 'Монтаж', 'D' => 'шт', 'G' => 2, 'J' => 100, 'L' => '=G5*J5'] as $column => $value) {
            $sheet->setCellValue($column.'5', $value);
        }
        $sheet->getStyle('XFD300')->getFont()->setBold(true);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        try {
            $loaded = GrandSmetaSpreadsheetLoader::load($path);
            self::assertFalse($loaded->getActiveSheet()->cellExists('XFD300'));
            self::assertLessThan(25, count($loaded->getActiveSheet()->getCellCollection()->getCoordinates()));
            $mapping = (new GrandSmetaHandler)->findHeaderAndMapping($loaded->getActiveSheet());
            self::assertSame(3, $mapping['header_row']);
            $loaded->disconnectWorksheets();

            $parser = new GrandSmetaParser(new StatefulGrandSmetaProcessor);
            $options = ['header_row' => 3, 'column_mapping' => $mapping['mapping']];
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $rows = iterator_to_array($parser->getStream($path, $options));
                $items = array_values(array_filter($rows, static fn ($row) => ! $row->isSection));
                self::assertCount(1, $items);
                self::assertEquals(200, $items[0]->currentTotalAmount);
                self::assertEquals(2, $items[0]->quantity);
                $sample = $parser->getRawSampleRows($path, $options);
                self::assertCount(2, $sample);
                self::assertEquals(200, $sample[1][11]);
            }
        } finally {
            unlink($path);
            Log::clearResolvedInstance('log');
        }
    }
}
