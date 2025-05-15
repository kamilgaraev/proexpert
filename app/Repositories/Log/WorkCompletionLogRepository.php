<?php

namespace App\Repositories\Log;

use App\Models\Models\Log\WorkCompletionLog; // Корректный путь
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkCompletionLogRepository extends BaseRepository implements WorkCompletionLogRepositoryInterface
{
    /**
     * Конструктор репозитория логов выполнения работ
     */
    public function __construct()
    {
        parent::__construct(WorkCompletionLog::class);
    }

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?WorkCompletionLog
    {
        return parent::find($modelId, $columns, $relations, $appends);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    public function delete(int $id): bool
    {
        return parent::delete($id);
    }
    // End of RepositoryInterface methods

    /**
     * Получить агрегированные данные по выполненным работам.
     *
     * @param int $organizationId
     * @param array $filters (project_id, work_type_id, user_id, date_from, date_to)
     * @return Collection
     */
    public function getAggregatedUsage(int $organizationId, array $filters = []): Collection
    {
        $query = $this->model->select(
            'work_completion_logs.work_type_id',
            'work_completion_logs.project_id',
            'work_types.name as work_type_name',
            'projects.name as project_name',
            DB::raw('SUM(work_completion_logs.quantity) as total_quantity'), // quantity может быть null
            'work_types.measurement_unit_id' // ID ед.изм.
        )
        ->join('projects', 'work_completion_logs.project_id', '=', 'projects.id')
        ->join('work_types', 'work_completion_logs.work_type_id', '=', 'work_types.id')
        ->where('projects.organization_id', $organizationId)
        ->groupBy(
            'work_completion_logs.work_type_id',
            'work_completion_logs.project_id',
            'work_types.name',
            'projects.name',
            'work_types.measurement_unit_id'
        );

        // Фильтры
        if (!empty($filters['project_id'])) {
            $query->where('work_completion_logs.project_id', $filters['project_id']);
        }
        if (!empty($filters['work_type_id'])) {
            $query->where('work_completion_logs.work_type_id', $filters['work_type_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('work_completion_logs.user_id', $filters['user_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('work_completion_logs.completion_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('work_completion_logs.completion_date', '<=', $filters['date_to']);
        }

        $results = $query->orderBy('projects.name')->orderBy('work_types.name')->get();

        // Загружаем единицы измерения
        $unitIds = $results->pluck('measurement_unit_id')->unique()->filter();
        if ($unitIds->isNotEmpty()) {
            $units = DB::table('measurement_units')->whereIn('id', $unitIds)->pluck('symbol', 'id');
            $results->each(function ($item) use ($units) {
                $item->unit_symbol = $units->get($item->measurement_unit_id);
            });
        }

        return $results;
    }

    /**
     * Получить пагинированный список логов выполнения работ для организации.
     */
    public function getPaginatedLogs(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'completion_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator
    {
        $query = $this->model->with(['project', 'workType.measurementUnit', 'user']) // Загружаем связи
            ->whereHas('project', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });

        // Фильтры
        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['work_type_id'])) {
            $query->where('work_type_id', $filters['work_type_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('completion_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('completion_date', '<=', $filters['date_to']);
        }

        // TODO: Добавить валидацию $sortBy
        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    // Реализация специфичных методов, если они будут добавлены в интерфейс
} 