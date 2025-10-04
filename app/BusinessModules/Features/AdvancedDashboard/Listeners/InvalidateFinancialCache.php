<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Listeners;

use App\BusinessModules\Features\AdvancedDashboard\Events\ContractDataChanged;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardCacheService;
use App\Services\LogService;

class InvalidateFinancialCache
{
    protected DashboardCacheService $cacheService;

    public function __construct(DashboardCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    public function handle(ContractDataChanged $event): void
    {
        $this->cacheService->invalidateFinancialAnalytics($event->organizationId);
        $this->cacheService->invalidatePredictiveAnalytics($event->organizationId);
        
        LogService::info('Financial cache invalidated', [
            'organization_id' => $event->organizationId,
            'contract_id' => $event->contractId,
        ]);
    }
}

