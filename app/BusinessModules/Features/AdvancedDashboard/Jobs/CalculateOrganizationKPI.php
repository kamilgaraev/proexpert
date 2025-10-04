<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\BusinessModules\Features\AdvancedDashboard\Services\KPICalculationService;
use App\Services\LogService;

/**
 * Job для фонового расчета KPI организации
 * 
 * Используется для пересчета KPI всех сотрудников организации
 * без блокировки HTTP запросов
 */
class CalculateOrganizationKPI implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $organizationId;
    public ?array $userIds;
    public int $timeout = 300;
    public int $tries = 3;

    public function __construct(int $organizationId, ?array $userIds = null)
    {
        $this->organizationId = $organizationId;
        $this->userIds = $userIds;
        
        $this->onQueue('analytics');
    }

    public function handle(KPICalculationService $kpiService): void
    {
        LogService::info('Starting KPI calculation job', [
            'organization_id' => $this->organizationId,
            'user_ids' => $this->userIds,
        ]);

        try {
            $from = now()->subDays(30);
            $to = now();
            
            if ($this->userIds) {
                foreach ($this->userIds as $userId) {
                    $kpiService->calculateUserKPI($userId, $this->organizationId, $from, $to);
                }
            } else {
                $kpiService->getTopPerformers($this->organizationId, $from, $to, 50);
                $kpiService->getResourceUtilization($this->organizationId, $from, $to);
            }
            
            LogService::info('KPI calculation completed', [
                'organization_id' => $this->organizationId,
            ]);
            
        } catch (\Exception $e) {
            LogService::error('KPI calculation failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}

