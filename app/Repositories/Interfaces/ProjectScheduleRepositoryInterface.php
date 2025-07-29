<?php

namespace App\Repositories\Interfaces;

use App\Models\ProjectSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProjectScheduleRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Получить пагинированный список графиков для организации
     */
    public function getPaginatedForOrganization(
        int $organizationId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator;

    /**
     * Получить активные графики для проекта
     */
    public function getActiveForProject(int $projectId): Collection;

    /**
     * Получить шаблоны графиков для организации
     */
    public function getTemplatesForOrganization(int $organizationId): Collection;

    /**
     * Создать график из шаблона
     */
    public function createFromTemplate(
        int $templateId,
        int $projectId,
        array $overrides = []
    ): ProjectSchedule;

    /**
     * Найти графики требующие пересчета критического пути
     */
    public function findNeedingCriticalPathRecalculation(): Collection;

    /**
     * Получить статистику по графикам организации
     */
    public function getOrganizationStats(int $organizationId): array;

    /**
     * Получить графики с просроченными задачами
     */
    public function getWithOverdueTasks(int $organizationId): Collection;

    /**
     * Найти графики по диапазону дат
     */
    public function findByDateRange(
        int $organizationId,
        string $startDate,
        string $endDate
    ): Collection;

    /**
     * Получить недавно обновленные графики
     */
    public function getRecentlyUpdated(
        int $organizationId,
        int $limit = 10
    ): Collection;

    /**
     * Сохранить базовый план для графика
     */
    public function saveBaseline(int $scheduleId, int $userId): bool;

    /**
     * Очистить базовый план графика
     */
    public function clearBaseline(int $scheduleId): bool;

    /**
     * Получить графики с критическими проблемами
     */
    public function getCriticalSchedules(int $organizationId): Collection;

    /**
     * Архивировать завершенные графики
     */
    public function archiveCompleted(int $organizationId, int $daysOld = 90): int;

    /**
     * Получить графики с конфликтами ресурсов
     */
    public function getWithResourceConflicts(int $organizationId): Collection;

    /**
     * Найти график по ID для конкретной организации
     */
    public function findForOrganization(int $scheduleId, int $organizationId): ?ProjectSchedule;
} 