<?php

namespace App\Services\Project;

use App\Models\CompletedWork;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
        $perPage = min(max($perPage, 1), 100);

        $query = DB::table('cross_org_completed_works as cow')
            ->join('organizations as org', 'org.id', '=', 'cow.child_organization_id')
            ->join('work_types as wt', 'wt.id', '=', 'cow.work_type_id')
            ->leftJoin('measurement_units as mu', 'mu.id', '=', 'wt.measurement_unit_id')
            ->where('cow.project_id', $projectId)
            ->whereNull('cow.deleted_at')
            ->where('cow.status', CompletedWork::STATUS_CONFIRMED)
            ->select([
                'cow.*',
                'org.name as child_organization_name',
                'wt.name as work_type_name',
                'mu.short_name as measurement_unit'
            ]);

        $this->applyFilters($query, $filters);

        // По умолчанию сортируем по дате выполнения (DESC)
        $query->orderByDesc('cow.completion_date');

        return $query->paginate($perPage);
    }

    /**
     * Получить агрегаты (сумма, количество) по фильтрам.
     */
    public function aggregateByProject(int $projectId, array $filters = []): object|null
    {
        $query = DB::table('cross_org_completed_works as cow')
            ->select(
                DB::raw('COALESCE(SUM(cow.total_amount), 0) as total_amount'),
                DB::raw('COALESCE(SUM(COALESCE(cow.completed_quantity, cow.quantity, 0)), 0) as total_quantity')
            )
            ->where('cow.project_id', $projectId)
            ->whereNull('cow.deleted_at')
            ->where('cow.status', CompletedWork::STATUS_CONFIRMED);

        $this->applyFilters($query, $filters);

        return $query->first();
    }

    public function statisticsByProject(int $projectId, array $filters = []): array
    {
        $query = DB::table('cross_org_completed_works as cow')
            ->where('cow.project_id', $projectId)
            ->whereNull('cow.deleted_at')
            ->where('cow.status', CompletedWork::STATUS_CONFIRMED);

        $this->applyFilters($query, $filters);

        $stats = $query
            ->selectRaw('COUNT(*) as total_works')
            ->selectRaw('COALESCE(SUM(cow.total_amount), 0) as total_cost')
            ->selectRaw('COUNT(DISTINCT cow.child_organization_id) as contractors_count')
            ->first();

        return [
            'total_works' => (int) ($stats->total_works ?? 0),
            'total_cost' => (float) ($stats->total_cost ?? 0),
            'contractors_count' => (int) ($stats->contractors_count ?? 0),
        ];
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
