<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative\Import;

class DbfNormativeParser
{
    public function parse(string $filePath): array
    {
        if (!function_exists('dbase_open')) {
            throw new \RuntimeException('Расширение dbase не установлено. Установите: pecl install dbase');
        }

        $data = [
            'collections' => [],
        ];

        $db = dbase_open($filePath, 0);
        
        if (!$db) {
            throw new \RuntimeException('Не удалось открыть DBF файл');
        }

        $recordCount = dbase_numrecords($db);
        $currentCollection = null;
        $currentSection = null;

        for ($i = 1; $i <= $recordCount; $i++) {
            $record = dbase_get_record_with_names($db, $i);
            
            if (!$record) {
                continue;
            }

            $type = trim($record['TYPE'] ?? $record['TYP'] ?? '');
            $code = trim($record['CODE'] ?? $record['KOD'] ?? '');
            $name = trim($record['NAME'] ?? $record['NAIM'] ?? '');

            if (empty($type) && empty($code)) {
                continue;
            }

            switch (strtolower($type)) {
                case 'c':
                case 'collection':
                case 'сборник':
                    $currentCollection = [
                        'code' => $code,
                        'name' => $name,
                        'sections' => [],
                        'rates' => [],
                    ];
                    $data['collections'][] = &$currentCollection;
                    $currentSection = null;
                    break;

                case 's':
                case 'section':
                case 'раздел':
                    if ($currentCollection) {
                        $currentSection = [
                            'code' => $code,
                            'name' => $name,
                            'rates' => [],
                        ];
                        $currentCollection['sections'][] = &$currentSection;
                    }
                    break;

                case 'r':
                case 'rate':
                case 'расценка':
                    $rate = [
                        'code' => $code,
                        'name' => $name,
                        'measurement_unit' => trim($record['ED_IZM'] ?? $record['UNIT'] ?? ''),
                        'base_price' => (float)($record['STOIMOST'] ?? $record['PRICE'] ?? 0),
                        'materials_cost' => (float)($record['MAT'] ?? 0),
                        'machinery_cost' => (float)($record['MASH'] ?? 0),
                        'labor_cost' => (float)($record['ZP'] ?? 0),
                        'labor_hours' => (float)($record['TRUDOZAT'] ?? 0),
                        'machinery_hours' => (float)($record['MASHINOH'] ?? 0),
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

        dbase_close($db);

        return $data;
    }
}

