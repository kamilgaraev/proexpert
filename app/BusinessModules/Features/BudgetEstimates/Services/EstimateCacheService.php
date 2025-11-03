<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use Illuminate\Support\Facades\Cache;

class EstimateCacheService
{
    private const TTL = 3600; // 1 час
    private const TAG_PREFIX = 'budget_estimates';

    /**
     * Кешировать итоговые суммы сметы
     */
    public function rememberTotals(Estimate $estimate, callable $callback): array
    {
        // Кешируем только утвержденные сметы
        if (!$estimate->isApproved()) {
            return $callback();
        }

        return $this->remember(
            "estimate_totals_{$estimate->id}",
            $estimate,
            $callback
        );
    }

    /**
     * Кешировать структуру сметы
     */
    public function rememberStructure(Estimate $estimate, callable $callback): array
    {
        return $this->remember(
            "estimate_structure_{$estimate->id}",
            $estimate,
            $callback
        );
    }

    /**
     * Кешировать историю версий
     */
    public function rememberVersions(Estimate $estimate, callable $callback): array
    {
        return $this->remember(
            "estimate_versions_{$estimate->id}",
            $estimate,
            $callback,
            7200 // 2 часа для версий
        );
    }

    /**
     * Кешировать список смет организации
     */
    public function rememberOrganizationList(int $organizationId, array $filters, callable $callback)
    {
        $filterKey = md5(json_encode($filters));
        
        return Cache::tags($this->getOrganizationTags($organizationId))
            ->remember(
                "estimates_list_{$organizationId}_{$filterKey}",
                600, // 10 минут для списков
                $callback
            );
    }

    /**
     * Универсальный метод кеширования с тегами
     */
    private function remember(string $key, Estimate $estimate, callable $callback, ?int $ttl = null)
    {
        $driver = config('cache.default');
        
        // Если Redis поддерживает теги
        if ($driver === 'redis') {
            return Cache::tags($this->getTags($estimate))
                ->remember($key, $ttl ?? self::TTL, $callback);
        }
        
        // Fallback для драйверов без тегов
        return Cache::remember($key, $ttl ?? self::TTL, $callback);
    }

    /**
     * Инвалидировать все кеши сметы
     */
    public function invalidateEstimate(Estimate $estimate): void
    {
        $driver = config('cache.default');
        
        if ($driver === 'redis') {
            Cache::tags($this->getTags($estimate))->flush();
        } else {
            // Удалить конкретные ключи
            $this->flushEstimateKeys($estimate);
        }
    }

    /**
     * Инвалидировать кеши организации
     */
    public function invalidateOrganization(int $organizationId): void
    {
        $driver = config('cache.default');
        
        if ($driver === 'redis') {
            Cache::tags($this->getOrganizationTags($organizationId))->flush();
        } else {
            // Для драйверов без тегов - очистить по паттерну
            $this->flushOrganizationKeys($organizationId);
        }
    }

    /**
     * Инвалидировать кеш итоговых сумм
     */
    public function invalidateTotals(Estimate $estimate): void
    {
        Cache::forget("estimate_totals_{$estimate->id}");
        $this->invalidateOrganization($estimate->organization_id);
    }

    /**
     * Инвалидировать кеш структуры
     */
    public function invalidateStructure(Estimate $estimate): void
    {
        Cache::forget("estimate_structure_{$estimate->id}");
    }

    /**
     * Инвалидировать кеш версий
     */
    public function invalidateVersions(Estimate $estimate): void
    {
        Cache::forget("estimate_versions_{$estimate->id}");
    }

    /**
     * Получить теги для сметы
     */
    private function getTags(Estimate $estimate): array
    {
        return [
            self::TAG_PREFIX,
            self::TAG_PREFIX . "_org_{$estimate->organization_id}",
            self::TAG_PREFIX . "_estimate_{$estimate->id}",
        ];
    }

    /**
     * Получить теги для организации
     */
    private function getOrganizationTags(int $organizationId): array
    {
        return [
            self::TAG_PREFIX,
            self::TAG_PREFIX . "_org_{$organizationId}",
        ];
    }

    /**
     * Fallback для удаления ключей сметы без тегов
     */
    private function flushEstimateKeys(Estimate $estimate): void
    {
        $patterns = [
            "estimate_totals_{$estimate->id}",
            "estimate_structure_{$estimate->id}",
            "estimate_versions_{$estimate->id}",
        ];

        foreach ($patterns as $key) {
            Cache::forget($key);
        }
        
        $this->invalidateOrganization($estimate->organization_id);
    }

    /**
     * Fallback для удаления ключей организации без тегов
     */
    private function flushOrganizationKeys(int $organizationId): void
    {
        // Для драйверов без поддержки паттернов - просто забываем известные ключи
        // В идеале нужно хранить список ключей в отдельном ключе
        Cache::forget("estimates_list_{$organizationId}_*");
    }

    /**
     * Получить статистику кеша
     */
    public function getStats(): array
    {
        // Для Redis можно получить статистику
        $driver = config('cache.default');
        
        if ($driver === 'redis') {
            try {
                $redis = Cache::getRedis();
                $keys = $redis->keys(self::TAG_PREFIX . '*');
                
                return [
                    'driver' => $driver,
                    'total_keys' => count($keys),
                    'supports_tags' => true,
                ];
            } catch (\Exception $e) {
                return [
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'driver' => $driver,
            'supports_tags' => false,
        ];
    }
}

