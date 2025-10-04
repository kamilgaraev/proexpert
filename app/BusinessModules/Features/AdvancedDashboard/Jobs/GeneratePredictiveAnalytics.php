<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\BusinessModules\Features\AdvancedDashboard\Services\PredictiveAnalyticsService;
use App\Services\LogService;

/**
 * Job для фоновой генерации предиктивной аналитики
 * 
 * Прогнозы контрактов, рисков бюджета и потребности материалов
 */
class GeneratePredictiveAnalytics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $organizationId;
    public ?int $contractId;
    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(int $organizationId, ?int $contractId = null)
    {
        $this->organizationId = $organizationId;
        $this->contractId = $contractId;
        
        $this->onQueue('analytics');
    }

    public function handle(PredictiveAnalyticsService $predictiveService): void
    {
        LogService::info('Starting predictive analytics job', [
            'organization_id' => $this->organizationId,
            'contract_id' => $this->contractId,
        ]);

        try {
            if ($this->contractId) {
                $predictiveService->predictContractCompletion($this->contractId);
                $predictiveService->predictBudgetOverrun($this->contractId);
            } else {
                $predictiveService->predictMaterialNeeds($this->organizationId, 6);
            }
            
            LogService::info('Predictive analytics completed', [
                'organization_id' => $this->organizationId,
                'contract_id' => $this->contractId,
            ]);
            
        } catch (\Exception $e) {
            LogService::error('Predictive analytics failed', [
                'organization_id' => $this->organizationId,
                'contract_id' => $this->contractId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}

