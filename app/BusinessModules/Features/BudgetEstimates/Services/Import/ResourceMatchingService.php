<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\Material;
use App\Models\Machinery;
use App\Models\LaborResource;
use App\Models\MeasurementUnit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Универсальный сервис для поиска и создания ресурсов в справочниках
 * 
 * Поддержка типов:
 * - material (материалы)
 * - equipment (оборудование - тоже materials)
 * - machinery (механизмы/техника)
 * - labor (трудозатраты)
 */
class ResourceMatchingService
{
    public function __construct(
        private readonly NormativeCodeService $codeService
    ) {}

    /**
     * Найти или создать ресурс в справочнике
     * 
     * @param string $type Тип ресурса (material, machinery, labor, equipment)
     * @param string $code Код ресурса
     * @param string $name Название
     * @param string|null $unit Единица измерения
     * @param float|null $price Цена
     * @param int $organizationId ID организации
     * @param array $additionalData Дополнительные данные
     * @return array ['type' => 'material', 'resource' => Material, 'created' => bool]
     */
    public function findOrCreate(
        string $type,
        string $code,
        string $name,
        ?string $unit,
        ?float $price,
        int $organizationId,
        array $additionalData = []
    ): array {
        return match ($type) {
            'material', 'equipment' => $this->findOrCreateMaterial($code, $name, $unit, $price, $organizationId, $type, $additionalData),
            'machinery' => $this->findOrCreateMachinery($code, $name, $unit, $price, $organizationId, $additionalData),
            'labor' => $this->findOrCreateLabor($code, $name, $unit, $price, $organizationId, $additionalData),
            default => throw new \InvalidArgumentException("Unknown resource type: {$type}"),
        };
    }

    /**
     * Найти или создать материал
     */
    private function findOrCreateMaterial(
        string $code,
        string $name,
        ?string $unit,
        ?float $price,
        int $organizationId,
        string $itemType,
        array $additionalData
    ): array {
        $material = $this->findByCode(Material::class, $code, $organizationId);
        
        if ($material) {
            return ['type' => 'material', 'resource' => $material, 'created' => false];
        }

        DB::beginTransaction();
        try {
            $category = $this->detectMaterialCategory($code);
            $measurementUnitId = $unit ? $this->findOrCreateUnit($unit, $organizationId, 'material')->id : null;

            $material = Material::create([
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'measurement_unit_id' => $measurementUnitId,
                'category' => $category,
                'default_price' => $price,
                'is_active' => true,
                'additional_properties' => array_merge([
                    'source' => 'estimate_import',
                    'imported_at' => now()->toIso8601String(),
                    'item_type' => $itemType,
                    'is_not_accounted' => $additionalData['is_not_accounted'] ?? false, // ⭐ Флаг "Н"
                ], $additionalData),
            ]);

            DB::commit();

            Log::info('resource.material.created', [
                'code' => $code,
                'material_id' => $material->id,
                'name' => $name,
                'category' => $category,
            ]);

            return ['type' => 'material', 'resource' => $material, 'created' => true];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('resource.material.create_failed', ['code' => $code, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Найти или создать механизм
     */
    private function findOrCreateMachinery(
        string $code,
        string $name,
        ?string $unit,
        ?float $price,
        int $organizationId,
        array $additionalData
    ): array {
        $machinery = $this->findByCode(Machinery::class, $code, $organizationId);
        
        if ($machinery) {
            return ['type' => 'machinery', 'resource' => $machinery, 'created' => false];
        }

        DB::beginTransaction();
        try {
            $category = $this->detectMachineryCategory($code, $name);
            $measurementUnitId = $unit ? $this->findOrCreateUnit($unit, $organizationId, 'machinery')->id : null;

            $machinery = Machinery::create([
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'measurement_unit_id' => $measurementUnitId,
                'category' => $category,
                'hourly_rate' => $price, // Цена как стоимость маш.-час
                'is_active' => true,
                'metadata' => array_merge([
                    'source' => 'estimate_import',
                    'imported_at' => now()->toIso8601String(),
                    'is_not_accounted' => $additionalData['is_not_accounted'] ?? false, // ⭐ Флаг "Н"
                ], $additionalData),
            ]);

            DB::commit();

            Log::info('resource.machinery.created', [
                'code' => $code,
                'machinery_id' => $machinery->id,
                'name' => $name,
                'category' => $category,
            ]);

            return ['type' => 'machinery', 'resource' => $machinery, 'created' => true];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('resource.machinery.create_failed', ['code' => $code, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Найти или создать трудовой ресурс
     */
    private function findOrCreateLabor(
        string $code,
        string $name,
        ?string $unit,
        ?float $price,
        int $organizationId,
        array $additionalData
    ): array {
        $labor = $this->findByCode(LaborResource::class, $code, $organizationId);
        
        if ($labor) {
            return ['type' => 'labor', 'resource' => $labor, 'created' => false];
        }

        DB::beginTransaction();
        try {
            $category = $this->detectLaborCategory($name);
            $profession = $this->extractProfession($name);
            $skillLevel = $this->extractSkillLevel($name);
            $measurementUnitId = $unit ? $this->findOrCreateUnit($unit, $organizationId, 'labor')->id : null;

            $labor = LaborResource::create([
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'measurement_unit_id' => $measurementUnitId,
                'category' => $category,
                'profession' => $profession,
                'skill_level' => $skillLevel,
                'hourly_rate' => $price, // Цена как стоимость чел.-час
                'is_active' => true,
                'metadata' => array_merge([
                    'source' => 'estimate_import',
                    'imported_at' => now()->toIso8601String(),
                    'is_not_accounted' => $additionalData['is_not_accounted'] ?? false, // ⭐ Флаг "Н"
                ], $additionalData),
            ]);

            DB::commit();

            Log::info('resource.labor.created', [
                'code' => $code,
                'labor_id' => $labor->id,
                'name' => $name,
                'profession' => $profession,
                'skill_level' => $skillLevel,
            ]);

            return ['type' => 'labor', 'resource' => $labor, 'created' => true];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('resource.labor.create_failed', ['code' => $code, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Найти ресурс по коду
     */
    private function findByCode(string $modelClass, string $code, int $organizationId)
    {
        $resource = $modelClass::where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        if ($resource) {
            return $resource;
        }

        // Поиск с нормализацией
        $normalized = $this->codeService->normalizeCode($code);
        $all = $modelClass::where('organization_id', $organizationId)->whereNotNull('code')->get();

        foreach ($all as $item) {
            if ($this->codeService->normalizeCode($item->code) === $normalized) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Найти или создать единицу измерения
     */
    private function findOrCreateUnit(string $unitName, int $organizationId, string $type): MeasurementUnit
    {
        $normalized = mb_strtolower(trim($unitName));

        $unit = MeasurementUnit::where('organization_id', $organizationId)
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if (!$unit) {
            $shortName = mb_strlen($unitName) > 10 ? mb_substr($unitName, 0, 10) : $unitName;

            $unit = MeasurementUnit::create([
                'organization_id' => $organizationId,
                'name' => $unitName,
                'short_name' => $shortName,
                'type' => $type,
            ]);
        }

        return $unit;
    }

    /**
     * Определить категорию материала по коду
     */
    private function detectMaterialCategory(string $code): string
    {
        if (preg_match('/^01\.7\.03/', $code)) return 'Электроэнергия';
        if (preg_match('/^01\.3/', $code)) return 'Топливо';
        if (preg_match('/^01\.7/', $code)) return 'Материалы';
        if (preg_match('/^14\./', $code)) return 'Химические материалы';
        if (preg_match('/^08\./', $code)) return 'Оборудование';
        return 'Прочие материалы';
    }

    /**
     * Определить категорию механизма по коду и названию
     */
    private function detectMachineryCategory(string $code, string $name): string
    {
        $name = mb_strtolower($name);
        
        if (str_contains($name, 'экскаватор')) return 'Экскаваторы';
        if (str_contains($name, 'кран')) return 'Краны';
        if (str_contains($name, 'бульдозер')) return 'Бульдозеры';
        if (str_contains($name, 'автомобил')) return 'Автотранспорт';
        if (str_contains($name, 'самосвал')) return 'Автотранспорт';
        if (str_contains($name, 'трактор')) return 'Тракторы';
        if (str_contains($name, 'компрессор')) return 'Компрессоры';
        if (str_contains($name, 'горелк')) return 'Инструмент';
        if (str_contains($name, 'пистолет')) return 'Инструмент';
        
        return 'Прочие механизмы';
    }

    /**
     * Определить категорию трудового ресурса
     */
    private function detectLaborCategory(string $name): string
    {
        $name = mb_strtolower($name);
        
        if (preg_match('/монтажник|электромонтаж/ui', $name)) return 'Монтажники';
        if (preg_match('/электрик|электросвар/ui', $name)) return 'Электрики';
        if (preg_match('/сварщик|газосвар/ui', $name)) return 'Сварщики';
        if (preg_match('/каменщик/ui', $name)) return 'Каменщики';
        if (preg_match('/бетонщик/ui', $name)) return 'Бетонщики';
        if (preg_match('/плотник|столяр/ui', $name)) return 'Плотники';
        if (preg_match('/маляр|штукатур/ui', $name)) return 'Отделочники';
        if (preg_match('/машинист/ui', $name)) return 'Машинисты';
        
        return 'Прочие рабочие';
    }

    /**
     * Извлечь профессию из названия
     */
    private function extractProfession(string $name): string
    {
        if (preg_match('/^([А-Яа-яA-Za-z\-]+)/', $name, $matches)) {
            return ucfirst(mb_strtolower(trim($matches[1])));
        }
        return 'Рабочий';
    }

    /**
     * Извлечь разряд из названия
     */
    private function extractSkillLevel(string $name): ?int
    {
        if (preg_match('/(\d)\s*разр/ui', $name, $matches)) {
            return (int) $matches[1];
        }
        if (preg_match('/[^\d](\d)\s*р\./ui', $name, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Получить статистику импорта
     */
    public function getImportStatistics(int $organizationId, \DateTimeInterface $since): array
    {
        $materials = Material::where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->whereJsonContains('additional_properties->source', 'estimate_import')
            ->count();

        $machinery = Machinery::where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->whereJsonContains('metadata->source', 'estimate_import')
            ->count();

        $labor = LaborResource::where('organization_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->whereJsonContains('metadata->source', 'estimate_import')
            ->count();

        return [
            'total' => $materials + $machinery + $labor,
            'materials' => $materials,
            'machinery' => $machinery,
            'labor' => $labor,
            'since' => $since->format('Y-m-d H:i:s'),
        ];
    }
}

