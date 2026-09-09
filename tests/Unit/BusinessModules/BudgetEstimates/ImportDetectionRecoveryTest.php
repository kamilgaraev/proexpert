<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetSampleLoader;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\ImportSession;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class ImportDetectionRecoveryTest extends TestCase
{
    public function test_certain_match_does_not_run_expensive_fallback(): void
    {
        $first = $this->handler('grandsmeta', 1.0);
        $fallback = $this->createMock(RuntimeImportFormatHandlerInterface::class);
        $fallback->method('slug')->willReturn('fallback');
        $fallback->method('supportedExtensions')->willReturn(['xlsx']);
        $fallback->expects(self::never())->method('detect');

        $result = (new ImportFormatDetector(new ImportFormatRegistry([$first, $fallback])))
            ->detect(new ImportSession, 'estimate.xlsx');

        self::assertSame('grandsmeta', $result?->formatSlug);
    }

    public function test_uncertain_match_still_checks_other_formats(): void
    {
        $result = (new ImportFormatDetector(new ImportFormatRegistry([
            $this->handler('first', 0.4),
            $this->handler('second', 0.93),
            $this->handler('third', 0.85),
        ])))->detect(new ImportSession, 'estimate.xlsx');

        self::assertSame('second', $result?->formatSlug);
    }

    public function test_sample_loads_only_requested_rows_and_preserves_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'estimate-sample-');
        self::assertIsString($path);
        $book = new Spreadsheet;
        $book->getProperties()->setDescription('MOST_TEMPLATE');
        $book->getActiveSheet()->setCellValue('A1', 'Header');
        $book->getActiveSheet()->setCellValue('A1000', 'Not needed');
        $book->createSheet()->setCellValue('B2', 'Other sheet');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        try {
            $sample = (new SpreadsheetSampleLoader)->load($path, 15);
            self::assertSame('Header', $sample->getSheet(0)->getCell('A1')->getValue());
            self::assertFalse($sample->getSheet(0)->cellExists('A1000'));
            self::assertSame('Other sheet', $sample->getSheet(1)->getCell('B2')->getValue());
            self::assertSame('MOST_TEMPLATE', $sample->getProperties()->getDescription());
            $sample->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_sampling_preserves_cached_formulas_and_full_read_keeps_all_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'estimate-formula-');
        self::assertIsString($path);
        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', '=A1000+5');
        $book->getActiveSheet()->setCellValue('A1000', 10);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        try {
            $reader = new SpreadsheetTableReader();
            $sample = $reader->readWorksheets($path, 15);
            self::assertEquals(15, $sample[0]['rows'][1][0]);
            self::assertLessThanOrEqual(15, count($sample[0]['rows']));
            $full = $reader->readRows($path);
            self::assertEquals(15, $full[1][0]);
            self::assertEquals(10, $full[1000][0]);
        } finally {
            unlink($path);
        }
    }

    private function handler(string $slug, float $confidence): RuntimeImportFormatHandlerInterface
    {
        $handler = $this->createMock(RuntimeImportFormatHandlerInterface::class);
        $handler->method('slug')->willReturn($slug);
        $handler->method('supportedExtensions')->willReturn(['xlsx']);
        $handler->expects(self::once())->method('detect')->willReturn(
            new ImportDetectionResult($slug, $slug, $slug, $confidence),
        );

        return $handler;
    }
}
