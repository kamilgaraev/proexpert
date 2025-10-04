<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Listeners;

use App\BusinessModules\Features\AdvancedDashboard\Events\DashboardUpdated;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService;
use App\Services\LogService;

class InvalidateDashboardCache
{
    protected DashboardCacheService $cacheService;

    public function __construct(DashboardCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function handle(DashboardUpdated $event): void
    {
        $dashboard = $event->dashboard;
        
        $this->cacheService->invalidateDashboardCache($dashboard->id);
        $this->cacheService->invalidateUserCache($dashboard->user_id);
        
        LogService::info('Dashboard cache invalidated', [
            'dashboard_id' => $dashboard->id,
            'user_id' => $dashboard->user_id,
        ]);
    }
}

