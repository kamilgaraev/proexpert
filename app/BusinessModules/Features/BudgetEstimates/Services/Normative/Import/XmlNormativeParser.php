<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative\Import;

class XmlNormativeParser
{
    public function parse(string $filePath): array
    {
        $xml = simplexml_load_file($filePath);
        
        if (!$xml) {
            throw new \RuntimeException('Не удалось разобрать XML файл');
        }

        $data = [
            'collections' => [],
        ];

        foreach ($xml->Collection ?? $xml->Sbornik ?? [] as $collectionXml) {
            $collection = [
                'code' => (string)$collectionXml['code'],
                'name' => (string)$collectionXml['name'],
                'description' => (string)($collectionXml['description'] ?? ''),
                'sections' => [],
                'rates' => [],
            ];

            foreach ($collectionXml->Section ?? $collectionXml->Razdel ?? [] as $sectionXml) {
                $section = [
                    'code' => (string)$sectionXml['code'],
                    'name' => (string)$sectionXml['name'],
                    'description' => (string)($sectionXml['description'] ?? ''),
                    'rates' => [],
                ];

                foreach ($sectionXml->Rate ?? $sectionXml->Rascenka ?? [] as $rateXml) {
                    $rate = [
                        'code' => (string)$rateXml['code'],
                        'name' => (string)$rateXml['name'],
                        'measurement_unit' => (string)($rateXml['unit'] ?? ''),
                        'base_price' => (float)($rateXml['price'] ?? 0),
                        'materials_cost' => (float)($rateXml['materials'] ?? 0),
                        'machinery_cost' => (float)($rateXml['machinery'] ?? 0),
                        'labor_cost' => (float)($rateXml['labor'] ?? 0),
                        'labor_hours' => (float)($rateXml['labor_hours'] ?? 0),
                        'machinery_hours' => (float)($rateXml['machinery_hours'] ?? 0),
                        'resources' => [],
                    ];

                    foreach ($rateXml->Resource ?? [] as $resourceXml) {
                        $rate['resources'][] = [
                            'resource_type' => (string)($resourceXml['type'] ?? 'other'),
                            'code' => (string)($resourceXml['code'] ?? ''),
                            'name' => (string)$resourceXml['name'],
                            'measurement_unit' => (string)($resourceXml['unit'] ?? ''),
                            'consumption' => (float)($resourceXml['consumption'] ?? 0),
                            'unit_price' => (float)($resourceXml['unit_price'] ?? 0),
                        ];
                    }

                    $section['rates'][] = $rate;
                }

                $collection['sections'][] = $section;
            }

            foreach ($collectionXml->Rate ?? $collectionXml->Rascenka ?? [] as $rateXml) {
                $collection['rates'][] = [
                    'code' => (string)$rateXml['code'],
                    'name' => (string)$rateXml['name'],
                    'measurement_unit' => (string)($rateXml['unit'] ?? ''),
                    'base_price' => (float)($rateXml['price'] ?? 0),
                    'materials_cost' => (float)($rateXml['materials'] ?? 0),
                    'machinery_cost' => (float)($rateXml['machinery'] ?? 0),
                    'labor_cost' => (float)($rateXml['labor'] ?? 0),
                    'resources' => [],
                ];
            }

            $data['collections'][] = $collection;
        }

        return $data;
    }
}
