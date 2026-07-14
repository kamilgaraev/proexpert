<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpreadsheetDocumentExtractorTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([
            'estimate-generation' => [
                'ocr' => [
                    'max_spreadsheet_rows' => 2000,
                    'max_spreadsheet_columns' => 80,
                    'languages' => ['ru', 'en'],
                ],
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    public function extracts_a_spooled_file_without_a_filename_extension(): void
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('A1', 'Фундамент');
        $xlsxPath = tempnam(sys_get_temp_dir(), 'estimate-generation-xlsx-');
        $source = tmpfile();

        try {
            self::assertIsString($xlsxPath);
            self::assertIsResource($source);
            (new Xlsx($workbook))->save($xlsxPath);
            $input = fopen($xlsxPath, 'rb');
            self::assertIsResource($input);
            stream_copy_to_stream($input, $source);
            fclose($input);
            fflush($source);
            $path = stream_get_meta_data($source)['uri'] ?? null;
            self::assertIsString($path);

            $document = new EstimateGenerationDocument([
                'filename' => 'смета.xlsx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'meta' => ['original_extension' => 'xlsx'],
            ]);
            $result = (new SpreadsheetDocumentExtractor)->extractFile($document, $path);

            self::assertStringContainsString('Фундамент', $result->pages[0]->text);
        } finally {
            $workbook->disconnectWorksheets();
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_string($xlsxPath) && is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }
}
