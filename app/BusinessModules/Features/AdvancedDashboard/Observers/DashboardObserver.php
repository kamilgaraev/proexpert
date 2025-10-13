<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Observers;

use App\BusinessModules\Features\AdvancedDashboard\Models\Dashboard;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardLayoutService;
use Illuminate\Support\Facades\Cache;

class DashboardObserver
{
    /**
     * Обработка события "created" для Dashboard.
     */
    public function created(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    /**
     * Обработка события "updated" для Dashboard.
     */
    public function updated(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    /**
     * Обработка события "deleted" для Dashboard.
     */
    public function deleted(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    /**
     * Обработка события "restored" для Dashboard.
     */
    public function restored(Dashboard $dashboard): void
    {
        $this->clearDashboardCache($dashboard);
    }

    /**
     * Очистить кеш дашбордов
     */
    protected function clearDashboardCache(Dashboard $dashboard): void
    {
        $userId = $dashboard->user_id;
        $organizationId = $dashboard->organization_id;

        // Определяем поддержку tagged cache
        $driver = config('cache.default');
        $supportsTagging = in_array($driver, ['redis', 'memcached']);

        if ($supportsTagging) {
            // Используем tagged cache для полной инвалидации
            $tags = [
                "dashboards",
                "user:{$userId}",
                "org:{$organizationId}",
            ];
            Cache::tags($tags)->flush();
        } else {
            // Fallback для драйверов без поддержки тегов
            Cache::forget("user_dashboards_{$userId}_{$organizationId}_true");
            Cache::forget("user_dashboards_{$userId}_{$organizationId}_false");
            Cache::forget("default_dashboard_{$userId}_{$organizationId}");
        }
    }
}

