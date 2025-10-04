<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Listeners;

use App\BusinessModules\Features\AdvancedDashboard\Events\CompletedWorkDataChanged;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService;
use App\Services\LogService;

class InvalidateKPICache
{
    protected DashboardCacheService $cacheService;

    public function __construct(DashboardCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function handle(CompletedWorkDataChanged $event): void
    {
        $this->cacheService->invalidateKPIAnalytics($event->organizationId);
        
        if ($event->userId) {
            $this->cacheService->invalidateUserCache($event->userId);
        }
        
        LogService::info('KPI cache invalidated', [
            'organization_id' => $event->organizationId,
            'user_id' => $event->userId,
        ]);
    }
}

