<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\NormativeRate;
use App\Models\NormativeCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для поиска нормативов по кодам
 * 
 * Логика поиска:
 * 1. Прямой поиск по коду (100% совпадение)
 * 2. Поиск по коду без префикса
 * 3. Поиск с вариациями (точки/дефисы)
 * 4. Fallback на поиск по названию
 */
class NormativeMatchingService
{
    private const CACHE_TTL = 3600; // 1 час
    private const CACHE_PREFIX = 'normative_match:';

    public function __construct(
        private readonly NormativeCodeService $codeService
    ) {}

    /**
     * Найти норматив по коду
     * 
     * @param string $code Код норматива из импортируемого файла
     * @param array $options Опции поиска
     * @return array|null ['normative' => NormativeRate, 'confidence' => int, 'method' => string]
     */
    public function findByCode(string $code, array $options = []): ?array
    {
        if (empty(trim($code))) {
            return null;
        }

        // Проверяем кеш
        $cacheKey = self::CACHE_PREFIX . md5($code);
        
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $result = null;

        // 1. Прямой поиск по коду (100% совпадение)
        $result = $this->exactCodeMatch($code);
        
        if ($result) {
            Cache::put($cacheKey, $result, self::CACHE_TTL);
            return $result;
        }

        // 2. Поиск с вариациями кода
        $result = $this->fuzzyCodeMatch($code);
        
        if ($result) {
            Cache::put($cacheKey, $result, self::CACHE_TTL);
            return $result;
        }

        // 3. Поиск по коду без учета регистра и пробелов
        $result = $this->normalizedCodeMatch($code);
        
        if ($result) {
            Cache::put($cacheKey, $result, self::CACHE_TTL);
            return $result;
        }

        Log::info('normative.code_not_found', [
            'code' => $code,
            'variations_tried' => $this->codeService->getCodeVariations($code),
        ]);

        return null;
    }

    /**
     * Найти нормативы по названию (fallback)
     * 
     * @param string $name Название работы
     * @param int $limit Максимум результатов
     * @return Collection
     */
    public function findByName(string $name, int $limit = 5): Collection
    {
        $name = trim($name);
        
        if (empty($name)) {
            return collect();
        }

        // Используем полнотекстовый поиск PostgreSQL
        $results = NormativeRate::search($name)
            ->with('collection')
            ->limit($limit)
            ->get();

        if ($results->isEmpty()) {
            // Fallback на fuzzy поиск через similarity
            $results = NormativeRate::fuzzySearch($name)
                ->with('collection')
                ->limit($limit)
                ->get();
        }

        return $results->map(function ($normative) use ($name) {
            return [
                'normative' => $normative,
                'confidence' => $this->calculateNameSimilarity($name, $normative->name),
                'method' => 'name_match',
            ];
        })->filter(function ($item) {
            return $item['confidence'] >= 60; // Минимальная уверенность
        });
    }

    /**
     * Получить полные данные норматива с ресурсами
     * 
     * @param NormativeRate $normative Норматив
     * @return array Полные данные для импорта
     */
    public function getNormativeData(NormativeRate $normative): array
    {
        return [
            'normative_rate_id' => $normative->id,
            'normative_rate_code' => $normative->code,
            'name' => $normative->name,
            'description' => $normative->description,
            'measurement_unit' => $normative->measurement_unit,
            'base_unit_price' => $normative->base_price,
            'materials_cost' => $normative->materials_cost,
            'machinery_cost' => $normative->machinery_cost,
            'labor_cost' => $normative->labor_cost,
            'labor_hours' => $normative->labor_hours,
            'machinery_hours' => $normative->machinery_hours,
            'base_price_year' => $normative->base_price_year,
            'collection_code' => $normative->collection->code ?? null,
            'collection_name' => $normative->collection->name ?? null,
            'has_resources' => $normative->hasResources(),
        ];
    }

    /**
     * Заполнить данные позиции из норматива
     * 
     * @param NormativeRate $normative Найденный норматив
     * @param array $itemData Данные позиции из импорта
     * @return array Обогащенные данные позиции
     */
    public function fillFromNormative(NormativeRate $normative, array $itemData): array
    {
        $normativeData = $this->getNormativeData($normative);
        
        return array_merge($itemData, [
            'normative_rate_id' => $normativeData['normative_rate_id'],
            'normative_rate_code' => $normativeData['normative_rate_code'],
            
            // Если название не задано или совпадает с кодом - берем из норматива
            'name' => $this->shouldUseNormativeName($itemData['name'] ?? '', $normativeData['normative_rate_code'])
                ? $normativeData['name']
                : ($itemData['name'] ?? $normativeData['name']),
            
            // Единица измерения из норматива (если не указана)
            'unit' => $itemData['unit'] ?? $normativeData['measurement_unit'],
            
            // Базовые цены из норматива (если не указаны)
            'base_unit_price' => $itemData['base_unit_price'] ?? $normativeData['base_unit_price'],
            
            // Ресурсные составляющие
            'base_materials_cost' => $normativeData['materials_cost'],
            'base_machinery_cost' => $normativeData['machinery_cost'],
            'base_labor_cost' => $normativeData['labor_cost'],
            
            // Трудозатраты
            'labor_hours' => $normativeData['labor_hours'],
            'machinery_hours' => $normativeData['machinery_hours'],
            
            // Метаданные
            'metadata' => array_merge($itemData['metadata'] ?? [], [
                'normative_source' => 'auto_filled',
                'normative_collection' => $normativeData['collection_name'],
                'base_price_year' => $normativeData['base_price_year'],
            ]),
        ]);
    }

    /**
     * Инвалидация кеша
     * 
     * @param string|null $code Конкретный код или null для всего кеша
     */
    public function invalidateCache(?string $code = null): void
    {
        if ($code) {
            $cacheKey = self::CACHE_PREFIX . md5($code);
            Cache::forget($cacheKey);
        } else {
            // Инвалидация всего кеша поиска нормативов
            Cache::tags(['normative_matching'])->flush();
        }
    }

    /**
     * Прямой поиск по коду (точное совпадение)
     * 
     * @param string $code Код норматива
     * @return array|null
     */
    private function exactCodeMatch(string $code): ?array
    {
        $normative = NormativeRate::where('code', $code)
            ->with('collection')
            ->first();

        if ($normative) {
            return [
                'normative' => $normative,
                'confidence' => 100,
                'method' => 'exact_code',
            ];
        }

        return null;
    }

    /**
     * Поиск с вариациями кода
     * 
     * @param string $code Код норматива
     * @return array|null
     */
    private function fuzzyCodeMatch(string $code): ?array
    {
        $variations = $this->codeService->getCodeVariations($code);
        
        foreach ($variations as $index => $variation) {
            $normative = NormativeRate::where('code', $variation)
                ->with('collection')
                ->first();

            if ($normative) {
                // Уверенность снижается в зависимости от порядка вариации
                $confidence = max(85, 100 - ($index * 5));
                
                return [
                    'normative' => $normative,
                    'confidence' => $confidence,
                    'method' => 'fuzzy_code',
                    'matched_variation' => $variation,
                ];
            }
        }

        return null;
    }

    /**
     * Поиск по нормализованному коду
     * 
     * @param string $code Код норматива
     * @return array|null
     */
    private function normalizedCodeMatch(string $code): ?array
    {
        $normalized = $this->codeService->normalizeCode($code);
        
        // Поиск по нормализованному коду в БД
        $normatives = NormativeRate::all(); // TODO: оптимизировать через индекс
        
        foreach ($normatives as $normative) {
            $dbNormalized = $this->codeService->normalizeCode($normative->code);
            
            if ($dbNormalized === $normalized) {
                return [
                    'normative' => $normative,
                    'confidence' => 95,
                    'method' => 'normalized_code',
                ];
            }
        }

        return null;
    }

    /**
     * Вычислить схожесть названий
     * 
     * @param string $name1 Первое название
     * @param string $name2 Второе название
     * @return float Процент схожести (0-100)
     */
    private function calculateNameSimilarity(string $name1, string $name2): float
    {
        $name1 = mb_strtolower(trim($name1));
        $name2 = mb_strtolower(trim($name2));
        
        similar_text($name1, $name2, $percent);
        
        return round($percent, 2);
    }

    /**
     * Проверить, нужно ли использовать название из норматива
     * 
     * @param string $importedName Название из импорта
     * @param string $code Код позиции
     * @return bool
     */
    private function shouldUseNormativeName(string $importedName, string $code): bool
    {
        // Если название пустое или совпадает с кодом - используем из норматива
        if (empty($importedName)) {
            return true;
        }
        
        $normalized = mb_strtolower(trim($importedName));
        $codeNormalized = mb_strtolower(trim($code));
        
        // Если название это просто код - заменяем
        if ($normalized === $codeNormalized) {
            return true;
        }
        
        // Если название очень короткое (< 10 символов) - вероятно это код
        if (mb_strlen($normalized) < 10) {
            return true;
        }
        
        return false;
    }
}

