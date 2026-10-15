<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Services\Export\ExcelExporterService;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use App\Services\Storage\PersonalFileService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

final class ExcelExportSecurityTest extends TestCase
{
    public function test_streamed_export_preserves_decimal_numbers_and_literal_user_text(): void
    {
        $response = $this->exporter()->streamDownload('security.xlsx', ['=1+1', 'Сумма', 'Код', 'Примечание'], [
            ['=HYPERLINK("https://attacker.test","x")', '12.50', '00123', '+123'],
            ['Обычный текст', '-45.67', '12,50', '@name'],
        ]);
        self::assertInstanceOf(StreamedResponse::class, $response);
        $spreadsheet = $this->readResponse($response);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
            self::assertSame('=1+1', $sheet->getCell('A1')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
            self::assertSame(12.5, $sheet->getCell('B2')->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B3')->getDataType());
            self::assertSame(-45.67, $sheet->getCell('B3')->getValue());
            self::assertSame('00123', $sheet->getCell('C2')->getValue());
            self::assertSame('+123', $sheet->getCell('D2')->getValue());
            self::assertSame('12,50', $sheet->getCell('C3')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_official_material_export_keeps_formatted_decimal_columns_numeric(): void
    {
        $response = $this->exporter()->generateOfficialMaterialReport([
            'header' => [], 'organizations' => [],
            'materials' => [[
                'work_name' => 'Работа', 'material_name' => '=1+1', 'unit' => 'кг',
                'received_from_customer' => ['volume' => 12.5, 'document' => '00123'],
                'usage' => ['production_norm' => 10.1, 'fact_used' => 9.9, 'for_next_month' => 2, 'balance' => 1],
                'economy_percentage' => 3.4567, 'economy_overrun' => -4.1234,
            ]],
        ], 'materials.xlsx');
        self::assertInstanceOf(StreamedResponse::class, $response);
        $spreadsheet = $this->readResponse($response);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $materialRow = null;
            for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
                if ($sheet->getCell('C'.$row)->getValue() === '=1+1') { $materialRow = $row; break; }
            }
            self::assertNotNull($materialRow);
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell('C'.$materialRow)->getDataType());
            self::assertSame('00123', $sheet->getCell('F'.$materialRow)->getValue());
            foreach (['E' => 12.5, 'G' => 10.1, 'H' => 9.9, 'I' => 0, 'J' => 2, 'K' => 1, 'M' => 3.4567, 'N' => -4.1234] as $column => $number) {
                $cell = $sheet->getCell($column.$materialRow);
                self::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
                self::assertEquals($number, $cell->getValue());
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function exporter(): ExcelExporterService
    {
        $logging = $this->mock(LoggingService::class, function ($mock): void {
            $mock->shouldReceive('business', 'technical')->zeroOrMoreTimes();
        });

        $files = $this->createMock(FileService::class);

        return new ExcelExporterService($logging, new PersonalFileService($files), $files);
    }

    private function readResponse(StreamedResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'most-export-regression-');
        ob_start();
        try {
            $response->sendContent();
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        try {
            file_put_contents($path, $content);

            return (new Xlsx)->load($path);
        } finally {
            unlink($path);
        }
    }
}
