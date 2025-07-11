<?php

namespace App\Services\Project;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class CrossOrgWorkReadService
{
    /**
     * Получить детализированные работы дочерних организаций по проекту с фильтрами и пагинацией.
     *
     * Поддерживаемые фильтры:
     *  - child_organization_id : int|array
     *  - work_type_id         : int|array
     *  - status               : string|array
     *  - date_from            : YYYY-mm-dd
     *  - date_to              : YYYY-mm-dd
     *  - search               : строка — поиск по notes (если есть)
     */
    public function paginateByProject(int $projectId, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = DB::table('cross_org_completed_works as cow')
            ->where('cow.project_id', $projectId);

        $this->applyFilters($query, $filters);

        // По умолчанию сортируем по дате выполнения (DESC)
        $query->orderByDesc('cow.completion_date');

        return $query->paginate($perPage);
    }

    /**
     * Получить агрегаты (сумма, количество) по фильтрам.
     */
    public function aggregateByProject(int $projectId, array $filters = []): Collection
    {
        $query = DB::table('cross_org_completed_works as cow')
            ->select(DB::raw('SUM(cow.total_amount) as total_amount'), DB::raw('SUM(cow.quantity) as total_quantity'))
            ->where('cow.project_id', $projectId);

        $this->applyFilters($query, $filters);

        return $query->first();
    }

    /**
     * Применяем фильтры к Query Builder.
     */
    protected function applyFilters($query, array $filters): void
    {
        if (!empty($filters['child_organization_id'])) {
            $childIds = is_array($filters['child_organization_id']) ? $filters['child_organization_id'] : [$filters['child_organization_id']];
            $query->whereIn('cow.child_organization_id', $childIds);
        }

        if (!empty($filters['work_type_id'])) {
            $types = is_array($filters['work_type_id']) ? $filters['work_type_id'] : [$filters['work_type_id']];
            $query->whereIn('cow.work_type_id', $types);
        }

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('cow.status', $statuses);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('cow.completion_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('cow.completion_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $query->where('cow.notes', 'like', '%' . $filters['search'] . '%');
        }
    }
} 