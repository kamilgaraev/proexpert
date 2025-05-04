<?php

namespace App\Repositories\Interfaces\Log;

use App\Repositories\RepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
 
interface MaterialUsageLogRepositoryInterface extends RepositoryInterface
{
    /**
     * Получить агрегированные данные по расходу материалов.
     *
     * @param int $organizationId
     * @param array $filters (project_id, material_id, user_id, date_from, date_to)
     * @return Collection
     */
    public function getAggregatedUsage(int $organizationId, array $filters = []): Collection;

    /**
     * Получить пагинированный список логов использования материалов для организации.
     *
     * @param int $organizationId
     * @param int $perPage
     * @param array $filters (project_id, material_id, user_id, date_from, date_to)
     * @param string $sortBy
     * @param string $sortDirection
     * @return LengthAwarePaginator
     */
    public function getPaginatedLogs(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'usage_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator;

    // TODO: Добавить специфичные методы для MaterialUsageLog, если они понадобятся
    // Например, получение логов для проекта за период и т.д.
} 