<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheStatsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CACHE_STATS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $driver = config('cache.default');
        
        if ($driver === 'redis') {
            return $this->getOrganizationRedisStats($request->organizationId);
        }

        return $this->getOrganizationCacheStats($request->organizationId, $driver);
    }

    protected function getOrganizationRedisStats(int $organizationId): array
    {
        try {
            $redis = Redis::connection();
            
            // Ищем ключи конкретной организации
            $pattern = "*org:{$organizationId}*";
            $keys = $redis->keys($pattern);
            $keysCount = count($keys);
            
            // Анализируем ключи по категориям
            $categories = [
                'widget' => 0,
                'dashboard' => 0,
                'data' => 0,
                'other' => 0,
            ];
            
            $totalMemory = 0;
            foreach ($keys as $key) {
                try {
                    $memory = $redis->memory('usage', $key);
                    $totalMemory += $memory ?: 0;
                } catch (\Exception $e) {
                    // Если команда MEMORY не поддерживается
                }
                
                if (strpos($key, 'widget:') !== false) {
                    $categories['widget']++;
                } elseif (strpos($key, 'dashboard:') !== false) {
                    $categories['dashboard']++;
                } elseif (strpos($key, 'data:') !== false) {
                    $categories['data']++;
                } else {
                    $categories['other']++;
                }
            }
            
            return [
                'cache_stats' => [
                    'driver' => 'Redis',
                    'organization_id' => $organizationId,
                    'total_keys' => $keysCount,
                    'estimated_memory_kb' => $totalMemory > 0 ? round($totalMemory / 1024, 2) : null,
                    'keys_by_category' => $categories,
                    'cache_enabled' => true,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'cache_stats' => [
                    'driver' => 'Redis',
                    'organization_id' => $organizationId,
                    'status' => 'error',
                    'message' => 'Не удалось получить статистику кеша',
                    'cache_enabled' => false,
                ],
            ];
        }
    }

    protected function getOrganizationCacheStats(int $organizationId, string $driver): array
    {
        // Тестируем работу кеша для организации
        $testKey = "cache_test:org:{$organizationId}:" . time();
        $testValue = ['test' => true, 'timestamp' => time()];
        
        $writeSuccess = false;
        $readSuccess = false;
        
        try {
            Cache::put($testKey, $testValue, 60);
            $writeSuccess = true;
            
            $retrieved = Cache::get($testKey);
            $readSuccess = ($retrieved === $testValue);
            
            Cache::forget($testKey);
        } catch (\Exception $e) {
            // Ошибка при работе с кешем
        }

        // Подсчитываем примерное количество закешированных виджетов организации
        $estimatedWidgets = 0;
        $widgetTypes = ['financial', 'projects', 'contracts', 'materials', 'hr', 'predictive'];
        
        foreach ($widgetTypes as $type) {
            $key = "widget:{$type}:org:{$organizationId}";
            if (Cache::has($key)) {
                $estimatedWidgets++;
            }
        }

        return [
            'cache_stats' => [
                'driver' => $driver,
                'organization_id' => $organizationId,
                'status' => ($writeSuccess && $readSuccess) ? 'working' : 'error',
                'cache_enabled' => $driver !== 'array',
                'cached_widgets_count' => $estimatedWidgets,
                'message' => $driver === 'array' 
                    ? 'Кеш отключен (используется array driver)' 
                    : (($writeSuccess && $readSuccess) ? 'Кеш работает корректно' : 'Ошибка при работе с кешем'),
            ],
        ];
    }
}
