<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\Cache;

class CacheStatsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CACHE_STATS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $driver = config('cache.default');
        
        return [
            'cache_stats' => [
                'driver' => $driver,
                'enabled' => $driver !== 'array',
            ],
            'message' => 'Cache statistics require Redis/Memcached monitoring integration',
        ];
    }
}

