<?php

namespace App\Repositories\Landing;

interface OrganizationDashboardRepositoryInterface
{
    public function getFinancialSummary(int $organizationId): array;

    public function getProjectSummary(int $organizationId): array;

    public function getContractSummary(int $organizationId): array;

    public function getWorkMaterialSummary(int $organizationId): array;

    public function getActSummary(int $organizationId): array;

    public function getTeamSummary(int $organizationId): array;

    /**
     * Подробный список участников команды с ролями и аватаром.
     */
    public function getTeamDetails(int $organizationId): array;

    /**
     * Распределение статусов (projects|contracts).
     */
    public function getStatusDistribution(string $entity, int $organizationId): array;

    /**
     * Баланс на конец каждого месяца за N=6 месяцев.
     */
    public function getMonthlyBalance(int $organizationId, int $months = 6): array;

    public function getTimeseries(string $metric, string $period, int $organizationId): array;
} 