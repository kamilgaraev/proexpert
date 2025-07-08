<?php

namespace App\Services\Landing;

use App\Repositories\Landing\OrganizationDashboardRepositoryInterface;

class DashboardService
{
    private OrganizationDashboardRepositoryInterface $repository;

    public function __construct(OrganizationDashboardRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Получить агрегированные данные дашборда по организации.
     */
    public function getDashboardData(int $organizationId): array
    {
        return [
            'financial' => $this->repository->getFinancialSummary($organizationId),
            'projects' => $this->repository->getProjectSummary($organizationId),
            'contracts' => $this->repository->getContractSummary($organizationId),
            'works_materials' => $this->repository->getWorkMaterialSummary($organizationId),
            'acts' => $this->repository->getActSummary($organizationId),
            'team' => $this->repository->getTeamSummary($organizationId),
            'team_details' => $this->repository->getTeamDetails($organizationId),
            'charts' => [
                'projects_monthly' => $this->repository->getTimeseries('projects', 'month', $organizationId),
                'contracts_monthly' => $this->repository->getTimeseries('contracts', 'month', $organizationId),
                'completed_works_monthly' => $this->repository->getTimeseries('completed_works', 'month', $organizationId),
                'balance_monthly' => $this->repository->getMonthlyBalance($organizationId),
                'projects_status' => $this->repository->getStatusDistribution('projects', $organizationId),
                'contracts_status' => $this->repository->getStatusDistribution('contracts', $organizationId),
            ],
        ];
    }
} 