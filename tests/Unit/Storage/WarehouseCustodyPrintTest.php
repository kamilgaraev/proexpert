<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyExportService;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WarehouseCustodyPrintTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public static function printCases(): array
    {
        return [
            'detail-empty' => ['detail', 0, false],
            'summary-empty' => ['summary', 0, false],
            'detail-long' => ['detail', 1, true],
            'summary-long' => ['summary', 1, true],
            'detail-many' => ['detail', 45, true],
            'summary-many' => ['summary', 45, true],
        ];
    }

    #[DataProvider('printCases')]
    public function test_stored_workbook_prints_all_seven_columns_and_preserves_values(string $mode, int $count, bool $long): void
    {
        $summary = $mode === 'summary';
        $headers = $summary
            ? ['Ответственный', 'Объектов', 'Материалов', 'Позиций', 'Количество', 'В резерве', 'Последнее движение']
            : ['Объект', 'Ответственный', 'Материал', 'Ед. изм.', 'Количество', 'В резерве', 'Последнее движение'];
        $project = $long ? str_repeat('Учебный объект ремонта офиса ', 4) : 'Учебный объект';
        $person = $long ? str_repeat('Учебный ответственный сотрудник ', 3) : 'Учебный ответственный';
        $material = $long ? str_repeat('Учебная грунтовка для отделки стен и потолка ', 4) : 'Учебная грунтовка';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$headers], null, 'A1');
        for ($row = 2; $row <= $count + 1; $row++) {
            $sheet->fromArray([
                $summary
                    ? [$person, 2, 3, 4, 12.5, 3.25, '22.10.2026 12:30']
                    : [$project, $person, $material, 'л', 12.5, 3.25, '22.10.2026 12:30'],
            ], null, 'A'.$row);
        }
        $bytes = '';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')->once()->andReturnUsing(
            static function (string $path, string $content, string $mime, string $sha256) use (&$bytes): CurrentStoredFile {
                $bytes = $content;

                return new CurrentStoredFile($path, 'etag', strlen($content), $sha256, $mime);
            },
        );
        $custody = (new ReflectionClass(WarehouseCustodyService::class))->newInstanceWithoutConstructor();
        $service = new WarehouseCustodyExportService($custody, $files);
        $lastRow = max(1, $count + 1);
        (new ReflectionMethod($service, 'styleSheet'))->invoke($service, $sheet, 'A', 'G', $lastRow, $summary);
        $stored = (new ReflectionMethod($service, 'saveSpreadsheet'))->invoke($service, $spreadsheet, 12, 34, $mode);
        self::assertSame(hash('sha256', $bytes), $stored->sha256);

        if ($directory = getenv('MOST_CUSTODY_PRINT_QA_DIR')) {
            file_put_contents($directory.'/custody-'.$mode.'-'.($count === 0 ? 'empty' : ($long && $count === 1 ? 'long' : 'many')).'.xlsx', $bytes);
        }
        $temporaryPath = tempnam(sys_get_temp_dir(), 'custody-print-');
        self::assertIsString($temporaryPath);
        try {
            file_put_contents($temporaryPath, $bytes);
            $result = IOFactory::load($temporaryPath)->getActiveSheet();

            self::assertSame(PageSetup::ORIENTATION_LANDSCAPE, $result->getPageSetup()->getOrientation());
            self::assertSame(PageSetup::PAPERSIZE_A4, $result->getPageSetup()->getPaperSize());
            self::assertSame(1, $result->getPageSetup()->getFitToWidth());
            self::assertSame(0, $result->getPageSetup()->getFitToHeight());
            self::assertSame('A1:G'.$lastRow, $result->getPageSetup()->getPrintArea());
            self::assertSame([1, 1], array_map('intval', $result->getPageSetup()->getRowsToRepeatAtTop()));
            self::assertSame('A2', $result->getFreezePane());
            self::assertGreaterThanOrEqual(0.15, $result->getPageMargins()->getBottom() - $result->getPageMargins()->getFooter());
            self::assertSame($headers, $result->rangeToArray('A1:G1')[0]);
            self::assertTrue($result->getStyle('A1:G'.$lastRow)->getAlignment()->getWrapText());
            foreach (range('A', 'G') as $column) {
                self::assertFalse($result->getColumnDimension($column)->getAutoSize());
                self::assertGreaterThanOrEqual(8, $result->getColumnDimension($column)->getWidth());
                self::assertLessThanOrEqual(40, $result->getColumnDimension($column)->getWidth());
            }
            if ($count > 0) {
                self::assertSame($summary ? $person : $project, $result->getCell('A'.$lastRow)->getValue());
                self::assertSame(12.5, (float) $result->getCell('E'.$lastRow)->getValue());
                self::assertSame(3.25, (float) $result->getCell('F'.$lastRow)->getValue());
                self::assertSame('22.10.2026 12:30', $result->getCell('G'.$lastRow)->getValue());
            }
        } finally {
            unlink($temporaryPath);
        }
    }
}
