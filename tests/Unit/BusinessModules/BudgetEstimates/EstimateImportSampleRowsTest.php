<?php

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EstimateImportSampleRowsTest extends TestCase
{
    public function test_sample_rows_are_indexed_arrays_and_handle_columns_beyond_z()
    {
        // 1. Create a temporary Excel file with data spanning beyond column Z
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Header row
        // Header row - using standard keywords to satisfy KeywordBasedDetector
        $sheet->setCellValue('A1', 'Наименование работ');
        $sheet->setCellValue('Z1', 'Ед.изм.');
        $sheet->setCellValue('AA1', 'Кол-во');
        $sheet->setCellValue('AB1', 'Цена');
        
        // Data row 1
        $sheet->setCellValue('A2', 'Data A2');
        $sheet->setCellValue('Z2', 'Data Z2');
        $sheet->setCellValue('AA2', 'Data AA2');
        $sheet->setCellValue('AB2', 'Data AB2');
        
        // Data row 2
        $sheet->setCellValue('A3', 'Data A3');
        $sheet->setCellValue('Z3', 'Data Z3');
        $sheet->setCellValue('AA3', 'Data AA3');
        $sheet->setCellValue('AB3', 'Data AB3');

        $fileName = 'test_sample_rows_' . uniqid() . '.xlsx';
        $filePath = storage_path('app/temp/' . $fileName);
        
        // Ensure directory exists
        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0777, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        // 2. Mock Cache to return file data
        $fileId = 'test-file-id';
        Cache::shouldReceive('get')
            ->with("estimate_import_file:{$fileId}")
            ->andReturn([
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => filesize($filePath),
                'user_id' => 1, // Dummy
                'organization_id' => 1, // Dummy
            ]);

        // Mock other cache calls that detectFormat might make
        Cache::shouldReceive('put')->andReturn(true);

        // 3. Resolve Service
        /** @var EstimateImportService $service */
        $service = app(EstimateImportService::class);

        // 4. Call detectFormat
        try {
            $result = $service->detectFormat($fileId);
            
            // 5. Assertions
            $this->assertArrayHasKey('sample_rows', $result);
            $sampleRows = $result['sample_rows'];
            
            $this->assertCount(2, $sampleRows, 'Should have 2 sample rows');
            
            // Check Row 1 (Index 0)
            $row1 = $sampleRows[0];
            $this->assertIsArray($row1, 'Row should be an array');
            $this->assertTrue(array_is_list($row1), 'Row should be a list (indexed array), not associative');
            
            // Verify column values
            // Index 0 -> Col A
            // Index 25 -> Col Z
            // Index 26 -> Col AA
            // Index 27 -> Col AB
            
            $this->assertEquals('Data A2', $row1[0]);
            $this->assertEquals('Data Z2', $row1[25]);
            $this->assertEquals('Data AA2', $row1[26]);
            $this->assertEquals('Data AB2', $row1[27]);

            // Check Row 2 (Index 1)
            $row2 = $sampleRows[1];
            $this->assertEquals('Data A3', $row2[0]);
            $this->assertEquals('Data AA3', $row2[26]);

        } finally {
            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
}
