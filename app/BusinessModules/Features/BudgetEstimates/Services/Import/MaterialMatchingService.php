<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\Material;
use App\Models\MeasurementUnit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для поиска и создания материалов/механизмов в справочнике
 * 
 * Логика:
 * 1. Ищем материал по коду в справочнике materials
 * 2. Если не найден - создаем новый материал
 * 3. Возвращаем ID материала для связи с позицией сметы
 */
class MaterialMatchingService
{
    private NormativeCodeService $codeService;

    public function __construct(NormativeCodeService $codeService)
    {
        $this->codeService = $codeService;
    }

    /**
     * Найти или создать материал по коду
     * 
     * @param string $code Код материала (01.7.03.04-0001)
     * @param string $name Название материала
     * @param string|null $unit Единица измерения
     * @param float|null $price Цена
     * @param int $organizationId ID организации
     * @param string $itemType Тип позиции (material, equipment)
     * @return Material
     */
    public function findOrCreate(
        string $code,
        string $name,
        ?string $unit,
        ?float $price,
        int $organizationId,
        string $itemType = 'material'
    ): Material {
        // 1. Поиск по коду в справочнике
        $material = $this->findByCode($code, $organizationId);
        
        if ($material) {
            Log::debug('material.found_in_catalog', [
                'code' => $code,
                'material_id' => $material->id,
                'name' => $material->name,
            ]);
            
            return $material;
        }

        // 2. Материал не найден - создаем новый
        return $this->createMaterial($code, $name, $unit, $price, $organizationId, $itemType);
    }

    /**
     * Найти материал по коду
     * 
     * @param string $code Код материала
     * @param int $organizationId ID организации
     * @return Material|null
     */
    public function findByCode(string $code, int $organizationId): ?Material
    {
        // Прямой поиск по коду
        $material = Material::where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        if ($material) {
            return $material;
        }

        // Поиск с нормализацией кода
        $normalized = $this->codeService->normalizeCode($code);
        
        $allMaterials = Material::where('organization_id', $organizationId)
            ->whereNotNull('code')
            ->get();

        foreach ($allMaterials as $mat) {
            if ($this->codeService->normalizeCode($mat->code) === $normalized) {
                return $mat;
            }
        }

        return null;
    }

    /**
     * Создать новый материал в справочнике
     * 
     * @param string $code Код
     * @param string $name Название
     * @param string|null $unit Единица измерения
     * @param float|null $price Цена
     * @param int $organizationId ID организации
     * @param string $itemType Тип позиции
     * @return Material
     */
    private function createMaterial(
        string $code,
        string $name,
        ?string $unit,
        ?float $price,
        int $organizationId,
        string $itemType
    ): Material {
        DB::beginTransaction();
        
        try {
            // Определяем категорию по коду
            $category = $this->detectCategory($code);
            
            // Находим или создаем единицу измерения
            $measurementUnitId = null;
            if ($unit) {
                $measurementUnit = $this->findOrCreateUnit($unit, $organizationId);
                $measurementUnitId = $measurementUnit->id;
            }

            // Создаем материал
            $material = Material::create([
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'measurement_unit_id' => $measurementUnitId,
                'category' => $category,
                'default_price' => $price,
                'is_active' => true,
                'additional_properties' => [
                    'source' => 'estimate_import',
                    'imported_at' => now()->toIso8601String(),
                    'item_type' => $itemType,
                ],
            ]);

            DB::commit();

            Log::info('material.created_from_estimate', [
                'code' => $code,
                'material_id' => $material->id,
                'name' => $name,
                'category' => $category,
            ]);

            return $material;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('material.create_failed', [
                'code' => $code,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Определить категорию материала по коду
     * 
     * @param string $code Код материала
     * @return string
     */
    private function detectCategory(string $code): string
    {
        // Определяем по префиксу кода
        if (preg_match('/^91\./', $code)) {
            return 'механизмы';
        }
        
        if (preg_match('/^08\./', $code)) {
            return 'оборудование';
        }
        
        if (preg_match('/^01\.7\.03/', $code)) {
            return 'электроэнергия';
        }
        
        if (preg_match('/^01\.3/', $code)) {
            return 'топливо';
        }
        
        if (preg_match('/^01\.7/', $code)) {
            return 'материалы';
        }
        
        if (preg_match('/^14\./', $code)) {
            return 'химические материалы';
        }
        
        return 'прочие';
    }

    /**
     * Найти или создать единицу измерения
     * 
     * @param string $unitName Название единицы
     * @param int $organizationId ID организации
     * @return MeasurementUnit
     */
    private function findOrCreateUnit(string $unitName, int $organizationId): MeasurementUnit
    {
        $normalized = mb_strtolower(trim($unitName));

        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if ($unit === null) {
            $shortName = mb_strlen($unitName) > 10
                ? mb_substr($unitName, 0, 10)
                : $unitName;

            $unit = MeasurementUnit::create([
                'organization_id' => $organizationId,
                'name' => $unitName,
                'short_name' => $shortName,
                'type' => 'material',
            ]);
        }

        return $unit;
    }

    /**
     * Получить статистику по созданным материалам
     * 
     * @param int $organizationId ID организации
     * @param \DateTimeInterface $since С какой даты
     * @return array
     */
    public function getImportStatistics(int $organizationId, \DateTimeInterface $since): array
    {
        $materials = Material::where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->whereJsonContains('additional_properties->source', 'estimate_import')
            ->get();

        $byCategory = $materials->groupBy('category')->map->count();

        return [
            'total_imported' => $materials->count(),
            'by_category' => $byCategory->toArray(),
            'last_import' => $materials->max('created_at'),
        ];
    }
}

