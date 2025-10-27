<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Normative\Import;

use App\Models\NormativeBaseType;
use App\Models\NormativeCollection;
use App\Models\NormativeSection;
use App\Models\NormativeRate;
use App\Models\NormativeRateResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NormativeImportService
{
    protected array $stats = [
        'collections' => 0,
        'sections' => 0,
        'rates' => 0,
        'resources' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];

    protected array $errors = [];

    public function importFromFile(string $filePath, string $baseTypeCode): array
    {
        $this->resetStats();

        $baseType = NormativeBaseType::where('code', $baseTypeCode)->firstOrFail();
        
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $parser = match ($extension) {
            'xlsx', 'xls' => new ExcelNormativeParser(),
            'dbf' => new DbfNormativeParser(),
            'csv' => new CsvNormativeParser(),
            'xml' => new XmlNormativeParser(),
            default => throw new \InvalidArgumentException("Неподдерживаемый формат файла: {$extension}"),
        };

        try {
            $data = $parser->parse($filePath);
            
            DB::transaction(function () use ($baseType, $data) {
                $this->importData($baseType, $data);
            });

            Log::info('Импорт нормативной базы завершен', [
                'base_type' => $baseType->code,
                'stats' => $this->stats,
            ]);

            return [
                'success' => true,
                'stats' => $this->stats,
                'errors' => $this->errors,
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка импорта нормативной базы', [
                'base_type' => $baseType->code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats,
                'errors' => $this->errors,
            ];
        }
    }

    protected function importData(NormativeBaseType $baseType, array $data): void
    {
        foreach ($data['collections'] ?? [] as $collectionData) {
            try {
                $collection = $this->importCollection($baseType, $collectionData);
                
                foreach ($collectionData['sections'] ?? [] as $sectionData) {
                    $section = $this->importSection($collection, $sectionData);
                    
                    foreach ($sectionData['rates'] ?? [] as $rateData) {
                        $rate = $this->importRate($collection, $section, $rateData);
                        
                        foreach ($rateData['resources'] ?? [] as $resourceData) {
                            $this->importResource($rate, $resourceData);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->errors[] = [
                    'collection' => $collectionData['code'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
                Log::warning('Ошибка импорта сборника', [
                    'collection' => $collectionData['code'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function importCollection(NormativeBaseType $baseType, array $data): NormativeCollection
    {
        $collection = NormativeCollection::updateOrCreate(
            [
                'base_type_id' => $baseType->id,
                'code' => $data['code'],
            ],
            [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => $data['version'] ?? $baseType->version,
                'effective_date' => $data['effective_date'] ?? $baseType->effective_date,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
                'metadata' => $data['metadata'] ?? null,
            ]
        );

        $this->stats['collections']++;

        return $collection;
    }

    protected function importSection(NormativeCollection $collection, array $data, ?int $parentId = null): NormativeSection
    {
        $section = NormativeSection::updateOrCreate(
            [
                'collection_id' => $collection->id,
                'code' => $data['code'],
            ],
            [
                'parent_id' => $parentId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'level' => $data['level'] ?? 0,
                'sort_order' => $data['sort_order'] ?? 0,
                'metadata' => $data['metadata'] ?? null,
            ]
        );

        $section->updatePath();
        $this->stats['sections']++;

        if (!empty($data['subsections'])) {
            foreach ($data['subsections'] as $subsectionData) {
                $this->importSection($collection, $subsectionData, $section->id);
            }
        }

        return $section;
    }

    protected function importRate(NormativeCollection $collection, ?NormativeSection $section, array $data): NormativeRate
    {
        if (empty($data['code']) || empty($data['name'])) {
            $this->stats['skipped']++;
            throw new \InvalidArgumentException('Отсутствует код или название расценки');
        }

        $rate = NormativeRate::updateOrCreate(
            [
                'collection_id' => $collection->id,
                'code' => $data['code'],
            ],
            [
                'section_id' => $section?->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'measurement_unit' => $data['measurement_unit'] ?? null,
                'base_price' => $data['base_price'] ?? 0,
                'materials_cost' => $data['materials_cost'] ?? 0,
                'machinery_cost' => $data['machinery_cost'] ?? 0,
                'labor_cost' => $data['labor_cost'] ?? 0,
                'labor_hours' => $data['labor_hours'] ?? 0,
                'machinery_hours' => $data['machinery_hours'] ?? 0,
                'base_price_year' => $data['base_price_year'] ?? '2000',
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]
        );

        $this->stats['rates']++;

        return $rate;
    }

    protected function importResource(NormativeRate $rate, array $data): NormativeRateResource
    {
        if (empty($data['name'])) {
            $this->stats['skipped']++;
            throw new \InvalidArgumentException('Отсутствует название ресурса');
        }

        $resource = NormativeRateResource::updateOrCreate(
            [
                'rate_id' => $rate->id,
                'resource_type' => $data['resource_type'] ?? 'other',
                'code' => $data['code'] ?? null,
            ],
            [
                'name' => $data['name'],
                'measurement_unit' => $data['measurement_unit'] ?? null,
                'consumption' => $data['consumption'] ?? 0,
                'unit_price' => $data['unit_price'] ?? 0,
                'total_cost' => $data['total_cost'] ?? ($data['consumption'] ?? 0) * ($data['unit_price'] ?? 0),
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]
        );

        $this->stats['resources']++;

        return $resource;
    }

    protected function resetStats(): void
    {
        $this->stats = [
            'collections' => 0,
            'sections' => 0,
            'rates' => 0,
            'resources' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];
        $this->errors = [];
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
