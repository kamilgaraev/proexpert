<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative\Import;

class CsvNormativeParser
{
    public function parse(string $filePath): array
    {
        $data = [
            'collections' => [],
        ];

        $currentCollection = null;
        $currentSection = null;

        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = fgetcsv($handle, 0, ';');
            
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                $rowData = array_combine($header, $row);

                if (empty($rowData['type']) && empty($rowData['code'])) {
                    continue;
                }

                switch (strtolower($rowData['type'] ?? '')) {
                    case 'collection':
                    case 'сборник':
                        $currentCollection = [
                            'code' => $rowData['code'] ?? '',
                            'name' => $rowData['name'] ?? '',
                            'sections' => [],
                            'rates' => [],
                        ];
                        $data['collections'][] = &$currentCollection;
                        $currentSection = null;
                        break;

                    case 'section':
                    case 'раздел':
                        if ($currentCollection) {
                            $currentSection = [
                                'code' => $rowData['code'] ?? '',
                                'name' => $rowData['name'] ?? '',
                                'rates' => [],
                            ];
                            $currentCollection['sections'][] = &$currentSection;
                        }
                        break;

                    case 'rate':
                    case 'расценка':
                        $rate = [
                            'code' => $rowData['code'] ?? '',
                            'name' => $rowData['name'] ?? '',
                            'measurement_unit' => $rowData['measurement_unit'] ?? null,
                            'base_price' => (float)($rowData['base_price'] ?? 0),
                            'materials_cost' => (float)($rowData['materials_cost'] ?? 0),
                            'machinery_cost' => (float)($rowData['machinery_cost'] ?? 0),
                            'labor_cost' => (float)($rowData['labor_cost'] ?? 0),
                            'labor_hours' => (float)($rowData['labor_hours'] ?? 0),
                            'machinery_hours' => (float)($rowData['machinery_hours'] ?? 0),
                            'resources' => [],
                        ];

                        if ($currentSection) {
                            $currentSection['rates'][] = $rate;
                        } elseif ($currentCollection) {
                            $currentCollection['rates'][] = $rate;
                        }
                        break;
                }
            }
            fclose($handle);
        }

        return $data;
    }
}

