<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Сервис управления кешем дашбордов
 * 
 * Централизованное управление кешированием данных дашборда:
 * - Tagged cache для селективной инвалидации
 * - TTL управление
 * - Массовая очистка по тегам
 */
class DashboardCacheService
{
    protected int $defaultTTL = 300; // 5 минут

    /**
     * Кешировать данные виджета
     * 
     * @param string $key Ключ кеша
     * @param mixed $data Данные для кеширования
     * @param int|null $ttl TTL в секундах (null = default)
     * @param array $tags Теги для группировки
     * @return mixed
     */
    public function cacheWidget(string $key, $data, ?int $ttl = null, array $tags = [])
    {
        $ttl = $ttl ?? $this->defaultTTL;
        
        if (empty($tags)) {
            return Cache::put($key, $data, $ttl);
        }
        
        return Cache::tags($tags)->put($key, $data, $ttl);
    }

    /**
     * Получить кешированные данные виджета
     * 
     * @param string $key Ключ кеша
     * @param array $tags Теги
     * @return mixed|null
     */
    public function getCachedWidget(string $key, array $tags = [])
    {
        if (empty($tags)) {
            return Cache::get($key);
        }
        
        return Cache::tags($tags)->get($key);
    }

    /**
     * Кешировать с автоматическим вычислением (remember pattern)
     * 
     * @param string $key Ключ кеша
     * @param callable $callback Callback для вычисления данных
     * @param int|null $ttl TTL в секундах
     * @param array $tags Теги
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = [])
    {
        $ttl = $ttl ?? $this->defaultTTL;
        
        if (empty($tags)) {
            return Cache::remember($key, $ttl, $callback);
        }
        
        return Cache::tags($tags)->remember($key, $ttl, $callback);
    }

    /**
     * Инвалидировать кеш конкретного виджета
     * 
     * @param string $widgetType Тип виджета
     * @param int|null $organizationId ID организации (опционально)
     * @param int|null $userId ID пользователя (опционально)
     * @return void
     */
    public function invalidateWidgetCache(string $widgetType, ?int $organizationId = null, ?int $userId = null): void
    {
        $tags = ["widget:{$widgetType}"];
        
        if ($organizationId) {
            $tags[] = "org:{$organizationId}";
        }
        
        if ($userId) {
            $tags[] = "user:{$userId}";
        }
        
        Cache::tags($tags)->flush();
    }

    /**
     * Инвалидировать весь кеш пользователя
     * 
     * @param int $userId ID пользователя
     * @return void
     */
    public function invalidateUserCache(int $userId): void
    {
        Cache::tags(["user:{$userId}"])->flush();
    }

    /**
     * Инвалидировать весь кеш организации
     * 
     * @param int $organizationId ID организации
     * @return void
     */
    public function invalidateOrganizationCache(int $organizationId): void
    {
        Cache::tags(["org:{$organizationId}"])->flush();
    }

    /**
     * Инвалидировать кеш конкретного дашборда
     * 
     * @param int $dashboardId ID дашборда
     * @return void
     */
    public function invalidateDashboardCache(int $dashboardId): void
    {
        Cache::tags(["dashboard:{$dashboardId}"])->flush();
    }

    /**
     * Инвалидировать кеш по типу данных
     * 
     * @param string $dataType Тип данных (contracts, projects, materials, etc.)
     * @param int|null $organizationId ID организации (опционально)
     * @return void
     */
    public function invalidateByDataType(string $dataType, ?int $organizationId = null): void
    {
        $tags = ["data:{$dataType}"];
        
        if ($organizationId) {
            $tags[] = "org:{$organizationId}";
        }
        
        Cache::tags($tags)->flush();
    }

    /**
     * Инвалидировать финансовую аналитику
     * 
     * @param int $organizationId ID организации
     * @return void
     */
    public function invalidateFinancialAnalytics(int $organizationId): void
    {
        $tags = [
            "org:{$organizationId}",
            "widget:financial",
            "data:contracts",
            "data:completed_works",
        ];
        
        Cache::tags($tags)->flush();
    }

    /**
     * Инвалидировать предиктивную аналитику
     * 
     * @param int $organizationId ID организации
     * @return void
     */
    public function invalidatePredictiveAnalytics(int $organizationId): void
    {
        $tags = [
            "org:{$organizationId}",
            "widget:predictive",
            "data:contracts",
            "data:projects",
        ];
        
        Cache::tags($tags)->flush();
    }

    /**
     * Инвалидировать KPI и HR аналитику
     * 
     * @param int $organizationId ID организации
     * @return void
     */
    public function invalidateKPIAnalytics(int $organizationId): void
    {
        $tags = [
            "org:{$organizationId}",
            "widget:kpi",
            "widget:hr",
            "data:completed_works",
            "data:users",
        ];
        
        Cache::tags($tags)->flush();
    }

    /**
     * Получить информацию о состоянии кеша
     * 
     * @param int $organizationId ID организации
     * @return array
     */
    public function getCacheStats(int $organizationId): array
    {
        // Получаем информацию из Redis
        try {
            $redis = Redis::connection();
            
            // Подсчитываем ключи по префиксам
            $patterns = [
                "laravel_cache:*org:{$organizationId}*" => 'organization_keys',
                "laravel_cache:*widget:*" => 'widget_keys',
                "laravel_cache:*dashboard:*" => 'dashboard_keys',
            ];
            
            $stats = [
                'organization_id' => $organizationId,
                'counts' => [],
            ];
            
            foreach ($patterns as $pattern => $label) {
                $keys = $redis->keys($pattern);
                $stats['counts'][$label] = count($keys);
            }
            
            return $stats;
        } catch (\Exception $e) {
            return [
                'organization_id' => $organizationId,
                'error' => 'Unable to fetch cache stats: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Инвалидировать кеш по категории виджетов
     * 
     * @param string $category Категория (financial, projects, contracts, materials, hr, predictive, activity, performance)
     * @param int|null $organizationId ID организации
     * @return void
     */
    public function invalidateByCategory(string $category, ?int $organizationId = null): void
    {
        $tags = ["advanced_dashboard", "widget_data", $category];
        
        if ($organizationId) {
            $tags[] = "org:{$organizationId}";
        }
        
        Cache::tags($tags)->flush();
    }

    /**
     * Очистить весь кеш дашбордов (использовать осторожно!)
     * 
     * @return void
     */
    public function flushAllDashboardCache(): void
    {
        Cache::tags([
            'advanced_dashboard',
            'widget_data',
            'financial',
            'projects',
            'contracts',
            'materials',
            'hr',
            'predictive',
            'activity',
            'performance',
        ])->flush();
    }

    /**
     * Создать ключ кеша для виджета
     * 
     * @param string $widgetType Тип виджета
     * @param int $organizationId ID организации
     * @param array $params Дополнительные параметры
     * @return string
     */
    public function makeWidgetKey(string $widgetType, int $organizationId, array $params = []): string
    {
        $key = "widget:{$widgetType}:org:{$organizationId}";
        
        if (!empty($params)) {
            $paramsString = http_build_query($params);
            $key .= ':' . md5($paramsString);
        }
        
        return $key;
    }

    /**
     * Создать теги для виджета
     * 
     * @param string $widgetType Тип виджета
     * @param int $organizationId ID организации
     * @param int|null $userId ID пользователя (опционально)
     * @param array $dataTypes Типы данных (contracts, projects, etc.)
     * @return array
     */
    public function makeWidgetTags(string $widgetType, int $organizationId, ?int $userId = null, array $dataTypes = []): array
    {
        $tags = [
            "widget:{$widgetType}",
            "org:{$organizationId}",
        ];
        
        if ($userId) {
            $tags[] = "user:{$userId}";
        }
        
        foreach ($dataTypes as $dataType) {
            $tags[] = "data:{$dataType}";
        }
        
        return $tags;
    }

    /**
     * Установить TTL по умолчанию
     * 
     * @param int $seconds TTL в секундах
     * @return self
     */
    public function setDefaultTTL(int $seconds): self
    {
        $this->defaultTTL = $seconds;
        return $this;
    }

    /**
     * Получить TTL по умолчанию
     * 
     * @return int
     */
    public function getDefaultTTL(): int
    {
        return $this->defaultTTL;
    }

    /**
     * Проверить, существует ли кеш
     * 
     * @param string $key Ключ кеша
     * @param array $tags Теги
     * @return bool
     */
    public function has(string $key, array $tags = []): bool
    {
        if (empty($tags)) {
            return Cache::has($key);
        }
        
        return Cache::tags($tags)->has($key);
    }

    /**
     * Удалить конкретный ключ из кеша
     * 
     * @param string $key Ключ кеша
     * @param array $tags Теги
     * @return bool
     */
    public function forget(string $key, array $tags = []): bool
    {
        if (empty($tags)) {
            return Cache::forget($key);
        }
        
        return Cache::tags($tags)->forget($key);
    }
}

