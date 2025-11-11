<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationDashboardRepositoryInterface;
use App\Services\Logging\LoggingService;

class DashboardService
{
    private OrganizationDashboardRepositoryInterface $repository;
    private LoggingService $logging;

    public function __construct(OrganizationDashboardRepositoryInterface $repository, LoggingService $logging)
    {
        $this->repository = $repository;
        $this->logging = $logging;
    }

    /**
     * Получить агрегированные данные дашборда по проекту
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getDashboardData(int $organizationId, int $projectId): array
    {
        $startTime = microtime(true);
        
        $this->logging->business('dashboard.data.requested', [
            'organization_id' => $organizationId,
            'project_id' => $projectId
        ]);

        $data = [];
        
        // ДИАГНОСТИКА: Измеряем время каждой операции дашборда по проекту
        $operations = [
            'financial' => fn() => $this->repository->getFinancialSummary($organizationId, $projectId),
            'projects' => fn() => $this->repository->getProjectSummary($organizationId, $projectId),
            'contracts' => fn() => $this->repository->getContractSummary($organizationId, $projectId),
            'works_materials' => fn() => $this->repository->getWorkMaterialSummary($organizationId, $projectId),
            'acts' => fn() => $this->repository->getActSummary($organizationId, $projectId),
            'team' => fn() => $this->repository->getTeamSummary($organizationId, $projectId),
            'team_details' => fn() => $this->repository->getTeamDetails($organizationId, $projectId)
        ];
        
        foreach ($operations as $operationName => $operation) {
            $operationStart = microtime(true);
            
            try {
                $data[$operationName] = $operation();
                $operationDuration = (microtime(true) - $operationStart) * 1000;
                
                if ($operationDuration > 1000) {
                    $this->logging->technical("dashboard.operation.slow", [
                        'operation' => $operationName,
                        'organization_id' => $organizationId,
                        'duration_ms' => $operationDuration
                    ], 'warning');
                }
                
                $this->logging->technical("dashboard.operation.completed", [
                    'operation' => $operationName,
                    'organization_id' => $organizationId,
                    'duration_ms' => $operationDuration
                ]);
                
            } catch (\Exception $e) {
                $this->logging->technical("dashboard.operation.failed", [
                    'operation' => $operationName,
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                    'duration_ms' => (microtime(true) - $operationStart) * 1000
                ], 'error');
                
                $data[$operationName] = null;
            }
        }
        
        // ДИАГНОСТИКА: Charts - отдельно измеряем каждый график
        $chartsStart = microtime(true);
        $charts = [];
        $chartOperations = [
            'projects_monthly' => fn() => $this->repository->getTimeseries('projects', 'month', $organizationId, $projectId),
            'contracts_monthly' => fn() => $this->repository->getTimeseries('contracts', 'month', $organizationId, $projectId),
            'completed_works_monthly' => fn() => $this->repository->getTimeseries('completed_works', 'month', $organizationId, $projectId),
            'balance_monthly' => fn() => $this->repository->getMonthlyBalance($organizationId, 6, $projectId),
            'projects_status' => fn() => $this->repository->getStatusDistribution('projects', $organizationId, $projectId),
            'contracts_status' => fn() => $this->repository->getStatusDistribution('contracts', $organizationId, $projectId)
        ];
        
        foreach ($chartOperations as $chartName => $chartOperation) {
            $chartStart = microtime(true);
            
            try {
                $charts[$chartName] = $chartOperation();
                $chartDuration = (microtime(true) - $chartStart) * 1000;
                
                if ($chartDuration > 2000) {
                    $this->logging->technical("dashboard.chart.slow", [
                        'chart' => $chartName,
                        'organization_id' => $organizationId,
                        'duration_ms' => $chartDuration
                    ], 'warning');
                }
                
                $this->logging->technical("dashboard.chart.completed", [
                    'chart' => $chartName,
                    'organization_id' => $organizationId,
                    'duration_ms' => $chartDuration
                ]);
                
            } catch (\Exception $e) {
                $this->logging->technical("dashboard.chart.failed", [
                    'chart' => $chartName,
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                    'duration_ms' => (microtime(true) - $chartStart) * 1000
                ], 'error');
                
                $charts[$chartName] = [];
            }
        }
        
        $data['charts'] = $charts;
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        $this->logging->business('dashboard.data.completed', [
            'organization_id' => $organizationId,
            'total_duration_ms' => $totalDuration,
            'operations_count' => count($operations) + count($chartOperations),
            'charts_duration_ms' => (microtime(true) - $chartsStart) * 1000
        ]);
        
        if ($totalDuration > 3000) {
            $this->logging->technical('dashboard.slow_response', [
                'organization_id' => $organizationId,
                'total_duration_ms' => $totalDuration
            ], 'error');
        }
        
        return $data;
    }
} 