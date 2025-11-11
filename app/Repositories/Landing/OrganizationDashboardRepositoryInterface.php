<?php

namespace App\Repositories\Landing;

interface OrganizationDashboardRepositoryInterface
{
    /**
     * Финансовая сводка по проекту
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getFinancialSummary(int $organizationId, int $projectId): array;

    /**
     * Сводка по конкретному проекту
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getProjectSummary(int $organizationId, int $projectId): array;

    /**
     * Сводка по контрактам проекта
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getContractSummary(int $organizationId, int $projectId): array;

    /**
     * Сводка по работам и материалам проекта
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getWorkMaterialSummary(int $organizationId, int $projectId): array;

    /**
     * Сводка по актам проекта
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getActSummary(int $organizationId, int $projectId): array;

    /**
     * Сводка по команде проекта
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getTeamSummary(int $organizationId, int $projectId): array;

    /**
     * Подробный список участников команды проекта с ролями и аватаром
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getTeamDetails(int $organizationId, int $projectId): array;

    /**
     * Распределение статусов по проекту (projects|contracts)
     * @param string $entity Тип сущности (projects|contracts)
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getStatusDistribution(string $entity, int $organizationId, int $projectId): array;

    /**
     * Баланс проекта на конец каждого месяца за N месяцев
     * @param int $organizationId ID организации
     * @param int $months Количество месяцев
     * @param int $projectId ID проекта (обязательно)
     */
    public function getMonthlyBalance(int $organizationId, int $months, int $projectId): array;

    /**
     * Временные ряды (timeseries) для метрик проекта
     * @param string $metric Метрика (projects|contracts|completed_works)
     * @param string $period Период (month)
     * @param int $organizationId ID организации
     * @param int $projectId ID проекта (обязательно)
     */
    public function getTimeseries(string $metric, string $period, int $organizationId, int $projectId): array;
} 