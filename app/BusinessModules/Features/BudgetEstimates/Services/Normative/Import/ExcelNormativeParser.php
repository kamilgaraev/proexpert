<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ExcelNormativeParser
{
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        
        $data = [
            'collections' => [],
        ];

        $currentCollection = null;
        $currentSection = null;
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = [
                'type' => $this->getCellValue($sheet, 'A', $row),
                'code' => $this->getCellValue($sheet, 'B', $row),
                'name' => $this->getCellValue($sheet, 'C', $row),
                'measurement_unit' => $this->getCellValue($sheet, 'D', $row),
                'base_price' => $this->getNumericValue($sheet, 'E', $row),
                'materials_cost' => $this->getNumericValue($sheet, 'F', $row),
                'machinery_cost' => $this->getNumericValue($sheet, 'G', $row),
                'labor_cost' => $this->getNumericValue($sheet, 'H', $row),
                'labor_hours' => $this->getNumericValue($sheet, 'I', $row),
                'machinery_hours' => $this->getNumericValue($sheet, 'J', $row),
            ];

            if (empty($rowData['type']) && empty($rowData['code'])) {
                continue;
            }

            switch (strtolower($rowData['type'])) {
                case 'collection':
                case 'сборник':
                    $currentCollection = $this->createCollectionData($rowData);
                    $data['collections'][] = &$currentCollection;
                    $currentSection = null;
                    break;

                case 'section':
                case 'раздел':
                    if ($currentCollection) {
                        $currentSection = $this->createSectionData($rowData);
                        $currentCollection['sections'][] = &$currentSection;
                    }
                    break;

                case 'rate':
                case 'расценка':
                    if ($currentSection) {
                        $rate = $this->createRateData($rowData);
                        $currentSection['rates'][] = $rate;
                    } elseif ($currentCollection) {
                        $rate = $this->createRateData($rowData);
                        if (!isset($currentCollection['rates'])) {
                            $currentCollection['rates'] = [];
                        }
                        $currentCollection['rates'][] = $rate;
                    }
                    break;
            }
        }

        return $data;
    }

    protected function createCollectionData(array $rowData): array
    {
        return [
            'code' => $rowData['code'],
            'name' => $rowData['name'],
            'description' => null,
            'sort_order' => 0,
            'sections' => [],
            'rates' => [],
        ];
    }

    protected function createSectionData(array $rowData): array
    {
        return [
            'code' => $rowData['code'],
            'name' => $rowData['name'],
            'description' => null,
            'sort_order' => 0,
            'rates' => [],
        ];
    }

    protected function createRateData(array $rowData): array
    {
        return [
            'code' => $rowData['code'],
            'name' => $rowData['name'],
            'measurement_unit' => $rowData['measurement_unit'],
            'base_price' => $rowData['base_price'],
            'materials_cost' => $rowData['materials_cost'],
            'machinery_cost' => $rowData['machinery_cost'],
            'labor_cost' => $rowData['labor_cost'],
            'labor_hours' => $rowData['labor_hours'],
            'machinery_hours' => $rowData['machinery_hours'],
            'base_price_year' => '2000',
            'resources' => [],
        ];
    }

    protected function getCellValue($sheet, string $column, int $row): ?string
    {
        $value = $sheet->getCell($column . $row)->getValue();
        return $value !== null ? trim((string)$value) : null;
    }

    protected function getNumericValue($sheet, string $column, int $row): float
    {
        $value = $sheet->getCell($column . $row)->getValue();
        return $value !== null ? (float)$value : 0.0;
    }
}
